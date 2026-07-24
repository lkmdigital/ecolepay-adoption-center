<?php

namespace App\Shared\Models;

use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Table `audit_logs` — actions sensibles réalisées dans EAC.
 *
 * Alimente la permission `audit.view`. La clé vers `users` est en suppression
 * interdite : la trace doit survivre à son auteur, ce que la suppression logique
 * sur `users` rend possible sans blocage.
 */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function scopeAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    /**
     * Sorties de données hors de la plateforme.
     */
    public function scopeExports(Builder $query): Builder
    {
        return $query->where('action', 'like', '%.export');
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('occurred_at', [$from, $to]);
    }
}
