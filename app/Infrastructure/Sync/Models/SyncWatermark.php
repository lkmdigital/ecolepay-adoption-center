<?php

namespace App\Infrastructure\Sync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Table `sync_watermarks` — point de reprise, une ligne par entité.
 *
 * Rend la synchronisation incrémentale et reprenable après incident.
 */
class SyncWatermark extends Model
{
    protected $table = 'sync_watermarks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }

    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'last_run_id');
    }

    public static function for(string $entity, string $source = 'ecolepay'): ?self
    {
        return static::query()->where('source', $source)->where('entity', $entity)->first();
    }

    /**
     * Borne basse du prochain lot, reculée de la fenêtre de reprise.
     *
     * Aucun traitement ne doit être strictement incrémental : les données arrivent
     * en retard, et un lot qui repart exactement du dernier repère laisse dériver
     * les chiffres en silence.
     */
    public function restatementFloor(int $days = 7): ?Carbon
    {
        return $this->last_synced_at?->copy()->subDays($days);
    }
}
