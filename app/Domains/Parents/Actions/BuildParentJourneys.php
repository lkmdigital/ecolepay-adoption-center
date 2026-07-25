<?php

namespace App\Domains\Parents\Actions;

use App\Domains\Parents\Support\AdoptionStatusResolver;
use App\Shared\Models\AdoptionRuleVersion;
use App\Shared\Models\AdoptionStage;
use App\Shared\Models\CalendarDate;
use App\Shared\Support\SchoolYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reconstruit `fact_parent_journeys` : un parcours par parent × école, avec le
 * statut vivant (connu → inscrit → adoptant → engagé → à risque → perdu).
 *
 * Table entièrement dérivée : détruite et reconstruite à chaque calcul, jamais
 * source de vérité. Le grain parent × école permet des KPI justes par école ;
 * l'agrégation nationale se déduit ensuite.
 *
 * Le statut est déterminé par année scolaire ([[AdoptionStatusResolver]]), sur le
 * seul signal fiable : le paiement via l'app validé.
 */
final class BuildParentJourneys
{
    private const CHUNK = 2000;

    private const UPDATE = [
        'current_stage_id', 'rule_version_id',
        'known_date_id', 'registered_date_id', 'first_payment_date_id', 'last_activity_date_id',
        'known_at', 'registered_at', 'first_payment_at', 'last_activity_at',
        'days_known_to_registered', 'days_registered_to_first_payment', 'days_to_adoption',
        'days_since_last_activity',
        'payment_count', 'successful_payment_count', 'failed_payment_count',
        'total_amount', 'avg_payment_amount',
        'is_converted', 'is_active', 'has_ever_paid', 'last_recomputed_at',
    ];

    /**
     * @return array{journeys: int, connus: int, inscrits: int, adoptants: int, engages: int, a_risque: int, perdus: int}
     */
    public function __invoke(): array
    {
        $now = Carbon::now();
        $currentYear = SchoolYear::current()->startYear;
        $stageIds = AdoptionStage::query()->pluck('id', 'code')->all();
        $ruleVersionId = AdoptionRuleVersion::query()->current()->value('id');
        $resolver = new AdoptionStatusResolver(
            (int) config('eac.adoption.payment_window_end_month'),
            (int) config('eac.adoption.school_year_start_month'),
        );

        $payments = $this->paymentAggregates();          // "parent:school" => agg
        $rosterPairs = $this->rosterPairs();              // set "parent:school"
        $parents = $this->parentInfo();                   // parent_id => {account_created_at, first_known_at}

        // Univers des parcours : union des couples payés et des couples du roster.
        $keys = $payments;
        foreach ($rosterPairs as $k => $_) {
            $keys[$k] ??= null;
        }

        $stats = ['journeys' => 0, 'connus' => 0, 'inscrits' => 0, 'adoptants' => 0, 'engages' => 0, 'a_risque' => 0, 'perdus' => 0];
        $buffer = [];

        foreach (array_keys($keys) as $key) {
            [$parentId, $schoolId] = array_map('intval', explode(':', $key));
            $parent = $parents[$parentId] ?? null;
            if ($parent === null) {
                continue;
            }

            $agg = $payments[$key] ?? null;
            $row = $this->journeyRow(
                $parentId, $schoolId, $parent, $agg,
                isset($rosterPairs[$key]), $resolver, $stageIds, $ruleVersionId, $currentYear, $now,
            );

            $this->tally($stats, $row['__code']);
            unset($row['__code']);
            $buffer[] = $row;

            if (count($buffer) >= self::CHUNK) {
                $stats['journeys'] += $this->flush($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $stats['journeys'] += $this->flush($buffer);
        }

        return $stats;
    }

    /**
     * @param  array{account_created_at: ?string, first_known_at: ?string}  $parent
     * @param  array<string, mixed>|null  $agg
     * @param  array<string, int>  $stageIds
     * @return array<string, mixed>
     */
    private function journeyRow(
        int $parentId, int $schoolId, array $parent, ?array $agg, bool $isInRoster,
        AdoptionStatusResolver $resolver, array $stageIds, ?int $ruleVersionId, int $currentYear, Carbon $now,
    ): array {
        $registeredAt = $parent['account_created_at'];
        $knownAt = $parent['first_known_at'];
        $hasAccount = $registeredAt !== null;

        $firstPay = $agg['first_app_pay'] ?? null;
        $lastPay = $agg['last_app_pay'] ?? null;
        $firstPayYear = $firstPay !== null ? SchoolYear::forDate($firstPay)->startYear : null;
        $lastPayYear = $lastPay !== null ? SchoolYear::forDate($lastPay)->startYear : null;

        $code = $resolver->resolve($isInRoster, $hasAccount, $firstPayYear, $lastPayYear, $currentYear, $now);

        $successCount = (int) ($agg['success_count'] ?? 0);
        $hasEverPaid = $firstPay !== null;

        return [
            'parent_id' => $parentId,
            'school_id' => $schoolId,
            'current_stage_id' => $stageIds[$code->value],
            'rule_version_id' => $ruleVersionId,

            'known_date_id' => $this->dateId($knownAt),
            'registered_date_id' => $this->dateId($registeredAt),
            'first_payment_date_id' => $this->dateId($firstPay),
            'last_activity_date_id' => $this->dateId($lastPay),
            'known_at' => $knownAt,
            'registered_at' => $registeredAt,
            'first_payment_at' => $firstPay,
            'last_activity_at' => $lastPay,

            'days_known_to_registered' => $this->daysBetween($knownAt, $registeredAt),
            'days_registered_to_first_payment' => $this->daysBetween($registeredAt, $firstPay),
            'days_to_adoption' => $this->daysBetween($knownAt, $firstPay),
            'days_since_last_activity' => $lastPay !== null ? max((int) Carbon::parse($lastPay)->diffInDays($now), 0) : null,

            'payment_count' => (int) ($agg['payment_count'] ?? 0),
            'successful_payment_count' => $successCount,
            'failed_payment_count' => (int) ($agg['failed_count'] ?? 0),
            'total_amount' => $agg['total_amount'] ?? 0,
            'avg_payment_amount' => $successCount > 0 ? round(((float) ($agg['total_amount'] ?? 0)) / $successCount, 2) : null,

            'is_converted' => $hasEverPaid,
            'is_active' => $code->isActive(),
            'has_ever_paid' => $hasEverPaid,
            'is_test' => false,
            'first_built_at' => $now,
            'last_recomputed_at' => $now,

            '__code' => $code->value,
        ];
    }

    /** @param array{connus:int,inscrits:int,adoptants:int,engages:int,a_risque:int,perdus:int} $stats */
    private function tally(array &$stats, string $code): void
    {
        $key = match ($code) {
            'known' => 'connus',
            'registered' => 'inscrits',
            'adopter' => 'adoptants',
            'engaged' => 'engages',
            'at_risk' => 'a_risque',
            'lost' => 'perdus',
            default => null,
        };
        if ($key !== null) {
            $stats[$key]++;
        }
    }

    /** @param list<array<string, mixed>> $buffer */
    private function flush(array $buffer): int
    {
        return DB::table('fact_parent_journeys')->upsert($buffer, ['parent_id', 'school_id'], self::UPDATE);
    }

    /** @return array<string, array<string, mixed>> "parent:school" => agrégats */
    private function paymentAggregates(): array
    {
        $map = [];
        DB::table('fact_payments')
            ->selectRaw('parent_id, school_id')
            ->selectRaw('COUNT(*) as payment_count')
            ->selectRaw("SUM(status='success' AND is_manual=0) as success_count")
            ->selectRaw("SUM(status='failed') as failed_count")
            ->selectRaw("MIN(CASE WHEN status='success' AND is_manual=0 THEN paid_at END) as first_app_pay")
            ->selectRaw("MAX(CASE WHEN status='success' AND is_manual=0 THEN paid_at END) as last_app_pay")
            ->selectRaw("SUM(CASE WHEN status='success' AND is_manual=0 THEN amount ELSE 0 END) as total_amount")
            ->where('is_test', false)
            ->groupBy('parent_id', 'school_id')
            ->orderBy('parent_id')
            ->chunk(5000, function ($rows) use (&$map) {
                foreach ($rows as $r) {
                    $map["{$r->parent_id}:{$r->school_id}"] = [
                        'payment_count' => $r->payment_count,
                        'success_count' => $r->success_count,
                        'failed_count' => $r->failed_count,
                        'first_app_pay' => $r->first_app_pay,
                        'last_app_pay' => $r->last_app_pay,
                        'total_amount' => $r->total_amount,
                    ];
                }
            });

        return $map;
    }

    /** @return array<string, true> set des couples "parent:school" présents dans un roster */
    private function rosterPairs(): array
    {
        $set = [];
        DB::table('bridge_student_parents as bsp')
            ->join('dim_students as ds', 'ds.id', '=', 'bsp.student_id')
            ->where('ds.is_test', false)
            ->select('bsp.parent_id', 'ds.school_id')
            ->distinct()
            ->orderBy('bsp.parent_id')
            ->chunk(5000, function ($rows) use (&$set) {
                foreach ($rows as $r) {
                    $set["{$r->parent_id}:{$r->school_id}"] = true;
                }
            });

        return $set;
    }

    /** @return array<int, array{account_created_at: ?string, first_known_at: ?string}> */
    private function parentInfo(): array
    {
        $map = [];
        DB::table('dim_parents')->select('id', 'account_created_at', 'first_known_at')
            ->where('is_test', false)->orderBy('id')
            ->chunk(5000, function ($rows) use (&$map) {
                foreach ($rows as $r) {
                    $map[$r->id] = [
                        'account_created_at' => $r->account_created_at,
                        'first_known_at' => $r->first_known_at,
                    ];
                }
            });

        return $map;
    }

    private function dateId(?string $datetime): ?int
    {
        if ($datetime === null) {
            return null;
        }
        $year = (int) substr($datetime, 0, 4);
        if ($year < (int) config('eac.calendar.start_year') || $year > (int) config('eac.calendar.end_year')) {
            return null;
        }

        return CalendarDate::keyFor($datetime);
    }

    private function daysBetween(?string $from, ?string $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        return max((int) Carbon::parse($from)->diffInDays(Carbon::parse($to), false), 0);
    }
}
