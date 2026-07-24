<?php

namespace App\Domains\AI\Models;

use App\Domains\Campaigns\Models\Campaign;
use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Table `ai_recommendations`, grain : une recommandation.
 *
 * Seule table du dispositif conservée cinq ans sans archivage : c'est le seul
 * indicateur de qualité de la fonctionnalité IA. `linked_campaign_id` la rapproche
 * de `agg_campaign_kpis`, `metric_before` et `metric_after` mesurent son effet réel.
 */
class Recommendation extends Model
{
    protected $table = 'ai_recommendations';

    protected $fillable = [
        'status', 'rejection_reason', 'assigned_to_user_id', 'due_at',
        'outcome_status', 'outcome_notes',
    ];

    protected function casts(): array
    {
        return [
            'expected_value' => 'decimal:4',
            'decided_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'metric_before' => 'decimal:4',
            'metric_after' => 'decimal:4',
            'outcome_measured_at' => 'datetime',
        ];
    }

    public function diagnostic(): BelongsTo
    {
        return $this->belongsTo(Diagnostic::class, 'diagnostic_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /**
     * Ferme la boucle diagnostic → action → mesure.
     */
    public function linkedCampaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'linked_campaign_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class, 'recommendation_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['new', 'accepted', 'in_progress']);
    }

    public function scopeActionable(Builder $query): Builder
    {
        return $query->where('status', 'new')
            ->where(fn (Builder $inner) => $inner
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->orderByDesc('priority');
    }

    /**
     * Une recommandation portant sur une école désormais partie est du bruit. Sans
     * péremption, la liste se remplit de conseils obsolètes et cesse d'être
     * consultée — l'échec le plus courant de ce type de fonctionnalité.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'new')->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    public function scopeFromAi(Builder $query): Builder
    {
        return $query->where('source', 'ai');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Effet mesuré. Les deux bornes sont figées à la décision et à la mesure :
     * recalculer la valeur de départ ferait disparaître l'effet.
     */
    public function measuredImpact(): ?float
    {
        return $this->metric_before !== null && $this->metric_after !== null
            ? round((float) $this->metric_after - (float) $this->metric_before, 4)
            : null;
    }
}
