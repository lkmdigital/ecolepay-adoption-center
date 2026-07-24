<?php

namespace App\Infrastructure\Sync\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Table `source_entity_maps` — résolution des identifiants EcolePay vers les clés
 * internes.
 *
 * C'est ce qui absorbe les changements de numéro de téléphone et les fusions de
 * doublons sans casser l'historique des faits.
 */
class SourceEntityMap extends Model
{
    protected $table = 'source_entity_maps';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'merged_at' => 'datetime',
        ];
    }

    public function scopeOfType(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    public function scopeMerged(Builder $query): Builder
    {
        return $query->whereNotNull('merged_into_key');
    }

    /**
     * Résout un identifiant source, en suivant une éventuelle fusion.
     */
    public static function resolve(string $entityType, string $sourceId): ?int
    {
        $row = static::query()
            ->ofType($entityType)
            ->where('source_id', $sourceId)
            ->first();

        return $row?->merged_into_key ?? $row?->target_key;
    }

    public function wasMerged(): bool
    {
        return $this->merged_into_key !== null;
    }
}
