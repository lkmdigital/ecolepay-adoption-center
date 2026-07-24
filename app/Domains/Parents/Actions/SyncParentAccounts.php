<?php

namespace App\Domains\Parents\Actions;

use App\Infrastructure\EcolePay\UserReader;
use App\Infrastructure\Sync\Models\SyncRun;
use App\Shared\Support\PhoneHasher;
use App\Shared\ValueObjects\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Synchronise les comptes `users` (EcolePay) → `dim_parents`.
 *
 * C'est le passage « connu → inscrit ». Chaque compte est rapproché de son parent
 * connu par empreinte du téléphone :
 * - parent déjà connu (roster) → on le met à niveau : source_parent_id +
 *   account_created_at + contact ;
 * - numéro absent des rosters → nouveau parent inscrit créé directement.
 *
 * Un même numéro peut porter plusieurs comptes (un par école) : on retient le plus
 * ancien (première inscription), sinon `source_parent_id` — unique — entrerait en
 * conflit.
 */
final class SyncParentAccounts
{
    private const CHUNK = 2000;

    /** Colonnes mises à jour quand le parent existe déjà (connu ou re-sync). */
    private const UPDATE = [
        'source_parent_id', 'phone_e164', 'phone_country', 'full_name',
        'email', 'account_created_at', 'row_hash', 'synced_at',
    ];

    public function __construct(
        private readonly UserReader $reader,
        private readonly PhoneHasher $hasher,
    ) {}

    /**
     * @return array{read: int, matched_known: int, new_registered: int, already: int, rejected: int}
     */
    public function __invoke(SyncRun $run): array
    {
        $now = Carbon::now();

        // 1. Agréger les comptes par empreinte, en gardant le plus ancien.
        [$accounts, $rejects] = $this->aggregate($now);

        // 2. Connaître l'état préalable de dim_parents pour un décompte juste.
        $existing = $this->existingParents(); // hex => bool estDéjàInscrit

        $stats = [
            'read' => 0, 'matched_known' => 0, 'new_registered' => 0, 'already' => 0,
            'rejected' => count($rejects),
        ];
        $stats['read'] = array_sum(array_column($accounts, 'accounts_count'));

        foreach ($accounts as $hex => $acc) {
            if (! array_key_exists($hex, $existing)) {
                $stats['new_registered']++;      // inscrit, jamais vu dans un roster
            } elseif ($existing[$hex] === false) {
                $stats['matched_known']++;       // connu → inscrit
            } else {
                $stats['already']++;             // déjà inscrit (re-sync)
            }
        }

        // 3. Upsert par empreinte : met à niveau les connus, insère les nouveaux.
        foreach (array_chunk($this->rows($accounts, $now), self::CHUNK) as $chunk) {
            DB::table('dim_parents')->upsert($chunk, ['phone_hash'], self::UPDATE);
        }

        // 4. Rejets.
        $this->insertRejects($rejects, $run->id);

        return $stats;
    }

    /**
     * Agrège les comptes par empreinte (plus ancien retenu) et collecte les rejets.
     *
     * @return array{0: array<string, array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function aggregate(Carbon $now): array
    {
        $accounts = [];
        $rejects = [];

        $this->reader->chunk(self::CHUNK, function (Collection $rows) use (&$accounts, &$rejects, $now) {
            foreach ($rows as $row) {
                $phone = PhoneNumber::tryFrom($row->telephone);
                if ($phone === null) {
                    if ($row->telephone !== null && trim((string) $row->telephone) !== '') {
                        $rejects[] = $this->reject($row, 'invalid_phone', 'Numéro invalide : '.$row->telephone, $now);
                    }

                    continue;
                }

                $hex = bin2hex($this->hasher->hash($phone));
                $date = $this->date($row->date_ajout) ?? $now->toDateTimeString();

                // Plus ancien compte pour ce numéro.
                if (! isset($accounts[$hex]) || $date < $accounts[$hex]['account_created_at']) {
                    $accounts[$hex] = [
                        'phone' => $phone,
                        'source_parent_id' => (string) $row->id_user,
                        'account_created_at' => $date,
                        'full_name' => $this->fullName($row),
                        'email' => $row->email ? Str::limit(trim((string) $row->email), 180, '') : null,
                        'accounts_count' => ($accounts[$hex]['accounts_count'] ?? 0) + 1,
                    ];
                } else {
                    $accounts[$hex]['accounts_count']++;
                }
            }
        });

        return [$accounts, $rejects];
    }

    /**
     * @param  array<string, array<string, mixed>>  $accounts
     * @return list<array<string, mixed>>
     */
    private function rows(array $accounts, Carbon $now): array
    {
        $rows = [];
        foreach ($accounts as $acc) {
            /** @var PhoneNumber $phone */
            $phone = $acc['phone'];
            $hash = $this->hasher->hash($phone);

            $rows[] = [
                'phone_hash' => $hash,
                'source_parent_id' => $acc['source_parent_id'],
                'phone_e164' => $phone->canonical,
                'phone_country' => config('eac.country_code'),
                'full_name' => $acc['full_name'],
                'email' => $acc['email'],
                // Insert seulement : un parent connu garde son first_known_at.
                'first_known_at' => $acc['account_created_at'],
                'account_created_at' => $acc['account_created_at'],
                'marketing_consent' => false,
                'is_pseudonymized' => false,
                'is_test' => false,
                'row_hash' => hash('sha256', $hash.'|account', true),
                'synced_at' => $now,
            ];
        }

        return $rows;
    }

    /** @return array<string, bool> hex(phone_hash) => estDéjàInscrit */
    private function existingParents(): array
    {
        $map = [];
        DB::table('dim_parents')->select('phone_hash', 'source_parent_id')->orderBy('id')
            ->chunk(5000, function ($rows) use (&$map) {
                foreach ($rows as $r) {
                    $map[bin2hex($r->phone_hash)] = $r->source_parent_id !== null;
                }
            });

        return $map;
    }

    private function fullName(object $row): ?string
    {
        $name = trim(trim((string) $row->prenom).' '.trim((string) $row->nom));

        return $name !== '' ? Str::limit($name, 160, '') : null;
    }

    private function date(mixed $value): ?string
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
    private function reject(object $row, string $code, string $detail, Carbon $now): array
    {
        return [
            'entity' => 'parent_accounts',
            'source_identifier' => (string) $row->id_user,
            'reason_code' => $code,
            'reason_detail' => Str::limit($detail, 255, ''),
            'is_resolved' => false,
            'rejected_at' => $now,
        ];
    }

    /** @param list<array<string, mixed>> $rejects */
    private function insertRejects(array $rejects, int $syncRunId): void
    {
        foreach (array_chunk($rejects, self::CHUNK) as $chunk) {
            DB::table('sync_rejects')->insert(array_map(fn ($r) => [...$r, 'sync_run_id' => $syncRunId], $chunk));
        }
    }
}
