<?php

namespace App\Infrastructure\Sync\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Table `sync_rejects` — lignes refusées, avec leur motif.
 *
 * Une donnée invalide doit être visible : un rejet non tracé se manifeste plus tard
 * sous forme d'un taux d'adoption inexplicablement bas.
 */
class SyncReject extends Model
{
    protected $table = 'sync_rejects';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'is_resolved' => 'boolean',
            'resolved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'sync_run_id');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('is_resolved', false);
    }

    public function scopeForEntity(Builder $query, string $entity): Builder
    {
        return $query->where('entity', $entity);
    }
}
