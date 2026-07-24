<?php

namespace App\Domains\Analytics\Models;

use App\Shared\Models\CalendarDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agrégat — table `agg_cohort_kpis`, grain : une cohorte × un nombre de mois
 * écoulés × un périmètre.
 *
 * Répond à « nos parents restent-ils ? » — question qu'aucun autre agrégat ne
 * traite. Un graphique période sur période peut monter alors que chaque génération
 * se comporte plus mal que la précédente : la croissance du volume masque la
 * dégradation.
 */
class CohortKpi extends Model
{
    protected $table = 'agg_cohort_kpis';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cumulative_amount' => 'decimal:2',
            'computed_at' => 'datetime',
            'source_watermark_at' => 'datetime',
        ];
    }

    public function cohortMonth(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'cohort_month_date_id');
    }

    public function scopeBasedOn(Builder $query, string $basis): Builder
    {
        return $query->where('cohort_basis', $basis);
    }

    public function scopeForCohort(Builder $query, int $monthDateKey): Builder
    {
        return $query->where('cohort_month_date_id', $monthDateKey)->orderBy('months_since');
    }

    /**
     * `cohort_size` est figé à la constitution : un dénominateur mouvant rendrait
     * la courbe de rétention ininterprétable.
     */
    public function retentionRate(): ?float
    {
        return $this->cohort_size > 0
            ? round($this->still_active_count / $this->cohort_size, 4)
            : null;
    }

    public function conversionRate(): ?float
    {
        return $this->cohort_size > 0
            ? round($this->converted_count / $this->cohort_size, 4)
            : null;
    }
}
