<?php

namespace App\Infrastructure\Sync\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Table `sync_runs` — journal des exécutions de synchronisation.
 *
 * Alimente le « données à jour au… » affiché sur les tableaux de bord : la première
 * question posée devant un chiffre surprenant.
 */
class SyncRun extends Model
{
    protected $table = 'sync_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'watermark_from' => 'datetime',
            'watermark_to' => 'datetime',
        ];
    }

    public function rejects(): HasMany
    {
        return $this->hasMany(SyncReject::class, 'sync_run_id');
    }

    public function scopeForEntity(Builder $query, string $entity): Builder
    {
        return $query->where('entity', $entity);
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderByDesc('started_at');
    }

    /**
     * Fraîcheur réelle des données d'une entité : ce qu'on affiche à l'utilisateur.
     */
    public static function watermarkFor(string $entity): ?Carbon
    {
        return static::query()
            ->forEntity($entity)
            ->successful()
            ->latest()
            ->value('watermark_to');
    }

    public function hasRejects(): bool
    {
        return $this->rows_rejected > 0;
    }
}
