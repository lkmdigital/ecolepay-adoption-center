<?php

namespace App\Domains\AI\Models;

use App\Shared\Models\CalendarDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Table `ai_model_performance` — exactitude consolidée par modèle et par période.
 *
 * Raison d'être : survivre à la purge d'`ai_predictions`. Ces métriques doivent être
 * calculées AVANT la suppression des partitions — l'ordre inverse détruirait
 * l'historique de qualité des modèles sans lever la moindre erreur.
 */
class ModelPerformance extends Model
{
    protected $table = 'ai_model_performance';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'precision_score' => 'decimal:5',
            'recall_score' => 'decimal:5',
            'f1_score' => 'decimal:5',
            'score_band_distribution' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function periodStart(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'period_start_date_id');
    }

    public function scopeForModel(Builder $query, string $modelKey): Builder
    {
        return $query->where('model_key', $modelKey)->orderBy('period_start_date_id');
    }

    public function accuracy(): ?float
    {
        return $this->evaluated_count > 0
            ? round($this->correct_count / $this->evaluated_count, 4)
            : null;
    }
}
