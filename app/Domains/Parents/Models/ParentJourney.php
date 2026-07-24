<?php

namespace App\Domains\Parents\Models;

use App\Domains\Schools\Models\School;
use App\Shared\Concerns\ExcludesTestData;
use App\Shared\Enums\AdoptionStageCode;
use App\Shared\Models\AdoptionRuleVersion;
use App\Shared\Models\AdoptionStage;
use App\Shared\Models\CalendarDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Instantané cumulatif — table `fact_parent_journeys`, grain : un parent × une école.
 *
 * Table de travail des tableaux de bord : la majorité des écrans la lisent seule.
 *
 * Le grain parent × école est le choix structurant du schéma. Compter les adoptants
 * au niveau national en sommant ces lignes compterait deux fois les parents
 * multi-écoles — c'est le rôle d'`agg_parent_kpis` de dédoublonner.
 *
 * Entièrement dérivée, donc reconstructible. Jamais source de vérité.
 */
class ParentJourney extends Model
{
    use ExcludesTestData;

    protected $table = 'fact_parent_journeys';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'known_at' => 'datetime',
            'registered_at' => 'datetime',
            'first_payment_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'at_risk_at' => 'datetime',
            'lost_at' => 'datetime',
            'reactivated_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'avg_payment_amount' => 'decimal:2',
            'is_converted' => 'boolean',
            'is_active' => 'boolean',
            'has_ever_paid' => 'boolean',
            'is_test' => 'boolean',
            'first_built_at' => 'datetime',
            'last_recomputed_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ParentProfile::class, 'parent_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(AdoptionStage::class, 'current_stage_id');
    }

    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(AdoptionRuleVersion::class, 'rule_version_id');
    }

    // Dimension à rôles multiples : sept références vers le calendrier, une par
    // jalon du parcours. Les nommer explicitement est indispensable à la lisibilité.

    public function knownDate(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'known_date_id');
    }

    public function registeredDate(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'registered_date_id');
    }

    public function firstPaymentDate(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'first_payment_date_id');
    }

    public function lastActivityDate(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'last_activity_date_id');
    }

    public function scopeConverted(Builder $query): Builder
    {
        return $query->where('is_converted', true);
    }

    public function scopeInStage(Builder $query, AdoptionStageCode $code): Builder
    {
        return $query->whereHas('currentStage', fn (Builder $stage) => $stage->where('code', $code->value));
    }

    public function scopeForSchool(Builder $query, School|int $school): Builder
    {
        return $query->where('school_id', $school instanceof School ? $school->id : $school);
    }

    /**
     * Parents dépassant le seuil d'inactivité : liste de relance, et entrée du
     * traitement quotidien qui produit les transitions « à risque ».
     */
    public function scopeInactiveFor(Builder $query, int $days): Builder
    {
        return $query->where('days_since_last_activity', '>=', $days);
    }
}
