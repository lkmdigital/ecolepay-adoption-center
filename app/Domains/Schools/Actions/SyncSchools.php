<?php

namespace App\Domains\Schools\Actions;

use App\Domains\Schools\Models\School;
use App\Infrastructure\EcolePay\SchoolReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Synchronise `tb_ecole` (EcolePay) → `dim_schools` (EAC), en historisation type 2.
 *
 * Règles :
 * - Le modèle d'abonnement se déduit de `tb_ecole.abonnement` (0 = pris en charge).
 * - La géographie et le commercial référent sont des champs propriété EAC : ils
 *   sont **reportés d'une version à l'autre**, jamais écrasés par la source.
 * - Une nouvelle version n'est créée que si un attribut suivi change réellement
 *   (comparaison par empreinte), pour ne pas gonfler l'historique à chaque synchro.
 */
final class SyncSchools
{
    /** Attributs venant d'EcolePay qui déclenchent une nouvelle version s'ils changent. */
    private const TRACKED = ['name', 'school_code', 'subscription_amount', 'subscription_model', 'status'];

    public function __construct(private readonly SchoolReader $reader) {}

    /**
     * @return array{read: int, inserted: int, updated: int, unchanged: int}
     */
    public function __invoke(): array
    {
        $stats = ['read' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        $now = Carbon::now();

        foreach ($this->reader->all() as $row) {
            $stats['read']++;
            $attributes = $this->map($row);
            $hash = $this->hash($attributes);

            $current = School::query()
                ->sameSource($attributes['source_school_id'])
                ->current()
                ->first();

            if ($current === null) {
                $this->insertFirstVersion($attributes, $hash, $row, $now);
                $stats['inserted']++;

                continue;
            }

            if (hash_equals($current->row_hash, $hash)) {
                $current->forceFill(['synced_at' => $now])->save();
                $stats['unchanged']++;

                continue;
            }

            $this->supersede($current, $attributes, $hash, $row, $now);
            $stats['updated']++;
        }

        return $stats;
    }

    /**
     * @return array<string, mixed>
     */
    private function map(object $row): array
    {
        $amount = (int) $row->abonnement;

        return [
            'source_school_id' => (string) $row->id,
            'school_code' => $row->code !== null ? Str::limit(trim((string) $row->code), 32, '') : null,
            'name' => Str::limit(trim((string) $row->nom), 180, ''),
            // 0 = l'école prend en charge l'abonnement (intégré à la scolarité).
            'subscription_amount' => $amount,
            'subscription_model' => $amount === 0 ? 'bundled' : 'parent_paid',
            'status' => ((int) $row->actif) === 1 ? 'active' : 'inactive',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function hash(array $attributes): string
    {
        $tracked = array_map(fn (string $key) => (string) ($attributes[$key] ?? ''), self::TRACKED);

        return hash('sha256', implode('|', $tracked), true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertFirstVersion(array $attributes, string $hash, object $row, Carbon $now): void
    {
        School::query()->create([
            ...$attributes,
            'school_type' => 'inconnu',
            'country_code' => config('eac.country_code'),
            'geo_locked' => false,
            'is_test' => false,
            'is_current' => true,
            'valid_from' => $now,
            'valid_to' => null,
            'version' => 1,
            'row_hash' => $hash,
            'onboarded_at' => $this->date($row->date_add),
            'source_created_at' => $this->date($row->date_add),
            'synced_at' => $now,
        ]);
    }

    /**
     * Clôture la version courante et en crée une nouvelle, en reportant les champs
     * propriété EAC (géographie, commercial référent).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function supersede(School $current, array $attributes, string $hash, object $row, Carbon $now): void
    {
        DB::transaction(function () use ($current, $attributes, $hash, $now) {
            $current->closeVersion($now);

            School::query()->create([
                ...$attributes,
                // Attributs non suivis : conservés de la version précédente.
                'school_type' => $current->school_type,
                'country_code' => $current->country_code,
                // Champs propriété EAC : reportés, jamais écrasés par la source.
                'region' => $current->region,
                'city' => $current->city,
                'district' => $current->district,
                'latitude' => $current->latitude,
                'longitude' => $current->longitude,
                'geo_locked' => $current->geo_locked,
                'account_manager_user_id' => $current->account_manager_user_id,
                'is_test' => false,
                'is_current' => true,
                'valid_from' => $now,
                'valid_to' => null,
                'version' => $current->version + 1,
                'row_hash' => $hash,
                'onboarded_at' => $current->onboarded_at,
                'source_created_at' => $current->source_created_at,
                'synced_at' => $now,
            ]);
        });
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
