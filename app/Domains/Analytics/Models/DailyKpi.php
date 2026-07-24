<?php

namespace App\Domains\Analytics\Models;

use App\Shared\Models\AdoptionRuleVersion;
use App\Shared\Models\CalendarDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agrégat — table `agg_daily_kpis`, grain : un jour × un périmètre géographique.
 *
 * Chaque périmètre est calculé séparément : la ligne `global` n'est jamais la somme
 * des lignes `region`, les parents multi-écoles y seraient comptés plusieurs fois.
 *
 * Dérivé et reconstructible. Jamais source de vérité.
 */
class DailyKpi extends Model
{
    protected $table = 'agg_daily_kpis';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payment_amount' => 'decimal:2',
            'computed_at' => 'datetime',
            'source_watermark_at' => 'datetime',
            'is_final' => 'boolean',
        ];
    }

    public function date(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'date_id');
    }

    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(AdoptionRuleVersion::class, 'rule_version_id');
    }

    public function scopeGlobalScope(Builder $query): Builder
    {
        return $query->where('scope_level', 'global');
    }

    public function scopeForRegion(Builder $query, string $region): Builder
    {
        return $query->where('scope_level', 'region')->where('scope_code', $region);
    }

    public function scopeBetween(Builder $query, int $fromDateKey, int $toDateKey): Builder
    {
        return $query->whereBetween('date_id', [$fromDateKey, $toDateKey]);
    }

    /**
     * Lignes encore susceptibles d'être recalculées : la fenêtre de reprise n'est
     * pas close. Un traitement strictement incrémental graverait des valeurs
     * partielles.
     */
    public function scopeRestatable(Builder $query): Builder
    {
        return $query->where('is_final', false);
    }

    /**
     * Un taux se calcule à la lecture, depuis son couple numérateur / dénominateur.
     * Stocké seul, il ne serait pas additif : le taux national n'est pas la moyenne
     * des taux régionaux.
     */
    public function adoptionRate(): ?float
    {
        return $this->eligible_count > 0
            ? round($this->converted_count / $this->eligible_count, 4)
            : null;
    }

    public function averageDaysToAdoption(): ?float
    {
        return $this->adoption_delay_sample_count > 0
            ? round($this->sum_days_to_adoption / $this->adoption_delay_sample_count, 1)
            : null;
    }
}
