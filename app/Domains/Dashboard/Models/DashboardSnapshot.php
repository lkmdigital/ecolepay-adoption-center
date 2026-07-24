<?php

namespace App\Domains\Dashboard\Models;

use App\Shared\Models\CalendarDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cache d'affichage — table `dashboard_snapshots`.
 *
 * De nature différente des agrégats typés : non joignable, jetable, optimisé pour
 * une lecture unique.
 *
 * Deux règles strictes :
 *  - Ne jamais interroger l'intérieur du `payload`. Le jour où l'on en a besoin,
 *    c'est qu'il fallait une table typée.
 *  - Aucun chiffre ne doit exister uniquement ici.
 */
class DashboardSnapshot extends Model
{
    protected $table = 'dashboard_snapshots';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'computed_at' => 'datetime',
            'expires_at' => 'datetime',
            'source_watermark_at' => 'datetime',
            'is_stale' => 'boolean',
        ];
    }

    public function asOfDate(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'as_of_date_id');
    }

    public function scopeFresh(Builder $query): Builder
    {
        return $query->where('is_stale', false)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    public function scopeFor(Builder $query, string $dashboardKey, string $scopeType = 'global', string $scopeId = 'ALL'): Builder
    {
        return $query->where('dashboard_key', $dashboardKey)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId);
    }

    public function isUsable(): bool
    {
        return ! $this->is_stale && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Repris tel quel dans l'interface : évite qu'un utilisateur prenne une décision
     * sur un écran périmé sans le savoir.
     */
    public function freshnessLabel(): ?string
    {
        return $this->source_watermark_at?->diffForHumans();
    }
}
