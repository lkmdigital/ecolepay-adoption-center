<?php

namespace App\Domains\AI\Models;

use App\Domains\Schools\Models\School;
use App\Shared\Models\CalendarDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Table `ai_predictions`, grain : une cible × un modèle × un cycle de calcul.
 *
 * Conserver l'historique est ce qui rend le modèle évaluable : mesurer sa justesse
 * suppose de comparer ce qu'il prédisait avant à ce qui s'est produit après.
 *
 * `is_current` suit l'astuce du NULL : clôturer un score le passe à NULL, jamais à
 * false — un 0 stocké entrerait en collision sur l'index unique.
 */
class Prediction extends Model
{
    protected $table = 'ai_predictions';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scored_at' => 'datetime',
            'score' => 'decimal:5',
            'predicted_value' => 'decimal:2',
            'top_features' => 'array',
            'previous_score' => 'decimal:5',
            'score_delta' => 'decimal:5',
            'is_full_snapshot' => 'boolean',
            'is_current' => 'boolean',
            'resolved_at' => 'datetime',
            'actual_outcome' => 'boolean',
            'actual_value' => 'decimal:2',
            'is_correct' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function scoredDate(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'scored_date_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNotNull('is_current');
    }

    public function scopeModel(Builder $query, string $modelKey): Builder
    {
        return $query->where('model_key', $modelKey);
    }

    public function scopeForTarget(Builder $query, string $type, int $id): Builder
    {
        return $query->where('target_type', $type)->where('target_id', $id);
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->whereIn('score_band', ['high', 'critical']);
    }

    /**
     * Prédictions dont l'horizon est échu mais qui n'ont pas encore été confrontées
     * à la réalité. Entrée du traitement de résolution.
     */
    public function scopeAwaitingResolution(Builder $query): Builder
    {
        return $query->where('resolution_status', 'pending')
            ->whereRaw('DATE_ADD(scored_at, INTERVAL horizon_days DAY) <= NOW()');
    }

    /**
     * Clôture le score courant. Le NULL est impératif.
     */
    public function supersede(): bool
    {
        return $this->forceFill(['is_current' => null])->save();
    }

    public function horizonHasElapsed(): bool
    {
        return $this->scored_at->addDays($this->horizon_days)->isPast();
    }
}
