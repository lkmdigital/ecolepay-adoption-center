<?php

namespace App\Domains\Parents\Actions;

use App\Domains\Schools\Models\School;
use App\Infrastructure\EcolePay\PaymentReader;
use App\Infrastructure\EcolePay\UserReader;
use App\Infrastructure\Sync\Models\SyncRun;
use App\Shared\Models\CalendarDate;
use App\Shared\Support\PhoneHasher;
use App\Shared\Support\SchoolYear;
use App\Shared\ValueObjects\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Synchronise `payer` (EcolePay) → `fact_payments`.
 *
 * C'est le fait qui définit la conversion : le premier paiement app validé fait
 * passer un parent inscrit à ADOPTANT (l'indicateur is_first_payment est calculé
 * ensuite par le moteur de parcours, pas ici).
 *
 * `is_manuel = 1` est conservé mais restera exclu des calculs. Tous les paiements
 * sont chargés (y compris en attente et échoués : un premier paiement échoué est le
 * signal le plus actionnable du dispositif).
 *
 * Le parent est résolu via `id_user` → empreinte du téléphone → dim_parents, ce qui
 * couvre les comptes multiples d'un même numéro (dim_parents.source_parent_id ne
 * retient que le plus ancien).
 */
final class SyncPayments
{
    private const CHUNK = 2000;

    /** mode_paiement EcolePay → code dim_payment_methods. */
    private const MODE_MAP = [
        'WAVECI' => 'wave',
        'OMCI' => 'orange_money',
        'CARD' => 'card',
        'ESPECES' => 'cash',
        'VIREMENT' => 'bank_transfer',
    ];

    public function __construct(
        private readonly PaymentReader $reader,
        private readonly UserReader $userReader,
        private readonly PhoneHasher $hasher,
    ) {}

    /**
     * @return array{read: int, inserted: int, unchanged: int, rejected: int}
     */
    public function __invoke(SyncRun $run): array
    {
        $schoolMap = $this->schoolMap();
        $parentByUser = $this->parentByUser();
        $studentMap = $this->studentMap();
        $methodMap = $this->methodMap();

        $stats = ['read' => 0, 'inserted' => 0, 'unchanged' => 0, 'rejected' => 0];
        $now = Carbon::now();

        $this->reader->chunk(self::CHUNK, function (Collection $rows) use (
            &$stats, $schoolMap, $parentByUser, $studentMap, $methodMap, $now, $run
        ) {
            $facts = [];
            $rejects = [];

            foreach ($rows as $row) {
                $stats['read']++;

                $parentId = $parentByUser[(int) $row->id_user] ?? null;
                $schoolId = $schoolMap[(string) $row->id_ecole] ?? null;
                $dateId = $this->dateId($row->date_transaction);

                if ($parentId === null || $schoolId === null || $dateId === null) {
                    $rejects[] = $this->reject($row, $parentId, $schoolId, $dateId, $now);

                    continue;
                }

                $facts[] = $this->fact($row, $parentId, $schoolId, $dateId, $studentMap, $methodMap, $now);
            }

            if ($facts !== []) {
                $stats['inserted'] += DB::table('fact_payments')->insertOrIgnore($facts);
            }
            if ($rejects !== []) {
                $stats['rejected'] += count($rejects);
                DB::table('sync_rejects')->insert(array_map(fn ($r) => [...$r, 'sync_run_id' => $run->id], $rejects));
            }
        });

        $stats['unchanged'] = $stats['read'] - $stats['inserted'] - $stats['rejected'];

        return $stats;
    }

    /**
     * @param  array<string, int>  $studentMap
     * @param  array<string, int>  $methodMap
     * @return array<string, mixed>
     */
    private function fact(object $row, int $parentId, int $schoolId, int $dateId, array $studentMap, array $methodMap, Carbon $now): array
    {
        $amount = (int) $row->montant;
        $base = (int) $row->montant_initial;
        $subscription = (int) $row->abonnement;
        $mode = strtoupper(trim((string) $row->mode_paiement));

        return [
            'source_payment_id' => (string) $row->id,
            'date_id' => $dateId,
            'paid_at' => $this->datetime($row->date_transaction),
            'parent_id' => $parentId,
            'school_id' => $schoolId,
            'student_id' => $studentMap[(string) $row->id_eleve] ?? null,
            'payment_method_id' => $methodMap[self::MODE_MAP[$mode] ?? 'unknown'] ?? $methodMap['unknown'],
            'amount' => $amount,
            'net_amount' => $base,                                   // frais scolaires (école)
            'subscription_amount' => $subscription,                 // part EcolePay (LKM)
            'fee_amount' => max($amount - $base - $subscription, 0), // frais de transaction
            'currency' => config('eac.currency'),
            'status' => $this->status((int) $row->statut),
            'is_manual' => ((int) $row->is_manuel) === 1,
            'is_first_payment' => false, // calculé par le moteur de parcours
            'installment_label' => $row->type ? Str::limit(trim((string) $row->type), 40, '') : null,
            'school_year_label' => $this->schoolYear($row),
            'is_test' => false,
            'synced_at' => $now,
        ];
    }

    private function status(int $statut): string
    {
        return match ($statut) {
            1 => 'success',
            0 => 'pending',
            default => 'failed',
        };
    }

    private function schoolYear(object $row): string
    {
        $label = trim((string) $row->annee_scolaire);
        if (preg_match('/^\d{4}-\d{4}$/', $label)) {
            return $label;
        }

        return SchoolYear::forDate($this->datetime($row->date_transaction) ?? now())->label();
    }

    /** @return array<string, int> source_school_id => dim_schools.id (courant) */
    private function schoolMap(): array
    {
        return School::query()->where('is_test', false)->current()
            ->pluck('id', 'source_school_id')->all();
    }

    /**
     * id_user (int) → dim_parents.id, via l'empreinte du téléphone du compte.
     *
     * @return array<int, int>
     */
    private function parentByUser(): array
    {
        // Empreinte hex → parent_id.
        $byHash = [];
        DB::table('dim_parents')->select('id', 'phone_hash')->orderBy('id')
            ->chunk(5000, function ($rows) use (&$byHash) {
                foreach ($rows as $r) {
                    $byHash[bin2hex($r->phone_hash)] = $r->id;
                }
            });

        $map = [];
        $this->userReader->chunk(self::CHUNK, function (Collection $rows) use (&$map, $byHash) {
            foreach ($rows as $row) {
                $phone = PhoneNumber::tryFrom($row->telephone);
                if ($phone === null) {
                    continue;
                }
                $parentId = $byHash[bin2hex($this->hasher->hash($phone))] ?? null;
                if ($parentId !== null) {
                    $map[(int) $row->id_user] = $parentId;
                }
            }
        });

        return $map;
    }

    /** @return array<string, int> source_student_id => dim_students.id (année courante) */
    private function studentMap(): array
    {
        return DB::table('dim_students')
            ->where('school_year_label', SchoolYear::current()->label())
            ->pluck('id', 'source_student_id')->all();
    }

    /** @return array<string, int> code => dim_payment_methods.id */
    private function methodMap(): array
    {
        return DB::table('dim_payment_methods')->pluck('id', 'code')->all();
    }

    private function dateId(mixed $value): ?int
    {
        $dt = $this->datetime($value);
        if ($dt === null) {
            return null;
        }

        $year = (int) substr($dt, 0, 4);
        // Hors de la plage du calendrier dim_dates → non résolvable.
        if ($year < (int) config('eac.calendar.start_year') || $year > (int) config('eac.calendar.end_year')) {
            return null;
        }

        return CalendarDate::keyFor($dt);
    }

    private function datetime(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function reject(object $row, ?int $parentId, ?int $schoolId, ?int $dateId, Carbon $now): array
    {
        $reason = $parentId === null ? 'unknown_parent'
            : ($schoolId === null ? 'unknown_school' : 'invalid_date');

        return [
            'entity' => 'payments',
            'source_identifier' => (string) $row->id,
            'reason_code' => $reason,
            'reason_detail' => "user={$row->id_user} ecole={$row->id_ecole} date={$row->date_transaction}",
            'is_resolved' => false,
            'rejected_at' => $now,
        ];
    }
}
