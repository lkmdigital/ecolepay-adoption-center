<?php

namespace App\Domains\Parents\Actions;

use App\Domains\Parents\Support\ParentLifecycle;
use App\Shared\Support\PhoneHasher;
use App\Shared\ValueObjects\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CRM des parents : KPI du cycle de vie et liste filtrée/triée.
 *
 * L'identité métier reste le téléphone (recherche par empreinte). L'adoption =
 * premier paiement ; l'engagement = paiements récurrents. La liste est filtrée
 * et bornée côté SQL (≈33 k parents) : jamais tout chargé en mémoire.
 */
final class ListParentsForCrm
{
    private const LIMIT = 150;

    public function __construct(private readonly PhoneHasher $hasher) {}

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, total: int, truncated: bool}
     */
    public function search(string $term = '', string $stage = '', ?int $schoolId = null, string $activity = '', string $sort = 'activity'): array
    {
        $base = DB::table('dim_parents as p')
            ->leftJoin('fact_parent_journeys as j', function ($join) {
                $join->on('j.parent_id', '=', 'p.id')->where('j.is_test', false);
            })
            ->where('p.is_test', false)
            ->groupBy('p.id', 'p.full_name', 'p.phone_e164', 'p.email', 'p.account_created_at', 'p.first_known_at')
            ->selectRaw('p.id, p.full_name, p.phone_e164, p.email, p.account_created_at, p.first_known_at')
            ->selectRaw('COALESCE(MAX(j.has_ever_paid), 0) as has_paid')
            ->selectRaw('COALESCE(SUM(j.successful_payment_count), 0) as pay_count')
            ->selectRaw('MAX(j.last_activity_at) as last_activity')
            ->selectRaw('MAX(j.school_id) as school_id');

        // Recherche : téléphone (empreinte) ou nom.
        $term = trim($term);
        if ($term !== '') {
            $phone = PhoneNumber::tryFrom($term);
            if ($phone !== null) {
                $base->where('p.phone_hash', $this->hasher->hash($phone));
            } else {
                $base->where('p.full_name', 'like', '%'.$term.'%');
            }
        }

        if ($schoolId) {
            $base->whereExists(fn ($q) => $q->from('fact_parent_journeys as jx')
                ->whereColumn('jx.parent_id', 'p.id')->where('jx.school_id', $schoolId)->where('jx.is_test', false));
        }

        // Étape du parcours (mélange condition sur le compte et agrégats de paiement).
        match ($stage) {
            'connu' => $base->whereNull('p.account_created_at')->havingRaw('MAX(j.has_ever_paid) IS NULL OR MAX(j.has_ever_paid) = 0'),
            'inscrit' => $base->whereNotNull('p.account_created_at')->havingRaw('COALESCE(MAX(j.has_ever_paid),0) = 0'),
            'adoptant' => $base->havingRaw('MAX(j.has_ever_paid) = 1 AND COALESCE(SUM(j.successful_payment_count),0) < 2'),
            'engage' => $base->havingRaw('MAX(j.has_ever_paid) = 1 AND COALESCE(SUM(j.successful_payment_count),0) >= 2'),
            default => null,
        };

        if ($activity !== '') {
            $threshold = match ($activity) {
                'today' => Carbon::now()->startOfDay(),
                'week' => Carbon::now()->startOfWeek(),
                'month' => Carbon::now()->startOfMonth(),
                default => null,
            };
            if ($activity === 'stale') {
                $base->havingRaw('MAX(j.last_activity_at) < ? OR MAX(j.last_activity_at) IS NULL', [Carbon::now()->subDays(30)]);
            } elseif ($threshold) {
                $base->havingRaw('MAX(j.last_activity_at) >= ?', [$threshold]);
            }
        }

        $total = DB::query()->fromSub(clone $base, 'sub')->count();

        $orderBy = match ($sort) {
            'adoption' => ['has_paid', 'desc'],
            'registration' => ['p.account_created_at', 'desc'],
            'payments' => ['pay_count', 'desc'],
            default => ['last_activity', 'desc'],
        };

        $rows = $base->orderByRaw("{$orderBy[0]} {$orderBy[1]}")->orderBy('p.id')->limit(self::LIMIT)->get();

        // Enrichissement des lignes de la page : enfants, dernier paiement, école.
        $ids = $rows->pluck('id')->all();
        $children = DB::table('bridge_student_parents')->whereIn('parent_id', $ids)
            ->selectRaw('parent_id, COUNT(*) as n')->groupBy('parent_id')->pluck('n', 'parent_id');
        $lastPay = DB::table('fact_payments')->whereIn('parent_id', $ids)
            ->where('is_test', false)->where('is_manual', false)->where('status', 'success')
            ->selectRaw('parent_id, MAX(paid_at) as last_pay')->groupBy('parent_id')->pluck('last_pay', 'parent_id');
        $schoolIds = $rows->pluck('school_id')->filter()->unique()->all();
        $schools = DB::table('dim_schools')->whereIn('id', $schoolIds)->whereNotNull('is_current')->pluck('name', 'id');

        return [
            'rows' => $rows->map(function ($r) use ($children, $lastPay, $schools) {
                $hasAccount = $r->account_created_at !== null;
                $hasPaid = (bool) $r->has_paid;
                $recurring = (int) $r->pay_count >= 2;
                $lastActivity = $r->last_activity ?? ($lastPay[$r->id] ?? null);

                return [
                    'id' => $r->id,
                    'name' => $r->full_name ?: 'Parent sans nom',
                    'phone' => $r->phone_e164,
                    'email' => $r->email,
                    'school' => $r->school_id ? ($schools[$r->school_id] ?? null) : null,
                    'children' => (int) ($children[$r->id] ?? 0),
                    'lifecycle' => ParentLifecycle::of($hasAccount, $hasPaid, $recurring),
                    'registeredAt' => $r->account_created_at,
                    'lastPayment' => $lastPay[$r->id] ?? null,
                    'lastActivity' => $lastActivity,
                    'payCount' => (int) $r->pay_count,
                    'engagement' => $this->engagement($hasAccount, $hasPaid, $recurring, $lastActivity),
                ];
            }),
            'total' => $total,
            'truncated' => $total > self::LIMIT,
        ];
    }

    /** KPI du cycle de vie, calculés en SQL sur toute la base. */
    public function kpis(): array
    {
        $connus = (int) DB::table('dim_parents')->where('is_test', false)->count();
        $inscrits = (int) DB::table('dim_parents')->where('is_test', false)->whereNotNull('account_created_at')->count();
        $adoptants = (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('has_ever_paid', true)->distinct()->count('parent_id');
        $engages = (int) DB::query()->fromSub(
            DB::table('fact_parent_journeys')->where('is_test', false)->where('has_ever_paid', true)
                ->groupBy('parent_id')->havingRaw('SUM(successful_payment_count) >= 2')->selectRaw('parent_id'),
            'sub'
        )->count();
        $newThisMonth = (int) DB::table('dim_parents')->where('is_test', false)
            ->where('account_created_at', '>=', Carbon::now()->startOfMonth())->count();

        return [
            'connus' => $connus,
            'inscrits' => $inscrits,
            'adoptants' => $adoptants,
            'engages' => $engages,
            'newThisMonth' => $newThisMonth,
            'adoptionRate' => $connus > 0 ? round($adoptants / $connus * 100, 1) : 0.0,
            'registrationRate' => $connus > 0 ? round($inscrits / $connus * 100, 1) : 0.0,
            'activationRate' => $inscrits > 0 ? round($adoptants / $inscrits * 100, 1) : 0.0,
            'inscritsSpark' => $this->monthlySpark('account_created_at'),
            'adoptantsSpark' => $this->adoptersSpark(),
        ];
    }

    private function engagement(bool $hasAccount, bool $hasPaid, bool $recurring, ?string $lastActivity): int
    {
        $recent = $lastActivity && Carbon::parse($lastActivity)->gt(Carbon::now()->subDays(90));

        return ($hasAccount ? 15 : 0) + ($hasPaid ? 35 : 0) + ($recurring ? 25 : 0) + ($recent ? 25 : 0);
    }

    private function monthlySpark(string $col): array
    {
        $byMonth = DB::table('dim_parents')->where('is_test', false)->whereNotNull($col)
            ->where($col, '>=', Carbon::now()->startOfMonth()->subMonths(5))
            ->selectRaw("DATE_FORMAT($col, '%Y-%m') as m, COUNT(*) as n")->groupBy('m')->pluck('n', 'm');

        return $this->fill($byMonth);
    }

    private function adoptersSpark(): array
    {
        $byMonth = DB::table('fact_parent_journeys')->where('is_test', false)->whereNotNull('first_payment_at')
            ->where('first_payment_at', '>=', Carbon::now()->startOfMonth()->subMonths(5))
            ->selectRaw("DATE_FORMAT(first_payment_at, '%Y-%m') as m, COUNT(DISTINCT parent_id) as n")->groupBy('m')->pluck('n', 'm');

        return $this->fill($byMonth);
    }

    private function fill($byMonth): array
    {
        $out = [];
        $cursor = Carbon::now()->startOfMonth()->subMonths(5);
        for ($i = 0; $i < 6; $i++) {
            $out[] = (int) ($byMonth[$cursor->format('Y-m')] ?? 0);
            $cursor->addMonth();
        }

        return $out;
    }
}
