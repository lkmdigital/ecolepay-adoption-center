<?php

namespace App\Domains\Parents\Models;

use App\Domains\Schools\Models\School;
use App\Shared\Concerns\ExcludesTestData;
use App\Shared\Models\AdoptionRuleVersion;
use App\Shared\Models\AdoptionStage;
use App\Shared\Models\CalendarDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fait transactionnel — table `fact_adoption_events`, grain : une transition d'état.
 *
 * Registre de la machine à états. C'est cette table qui rend possibles les cohortes
 * et les réactivations, invisibles si l'on ne regarde que l'état courant.
 *
 * Ne contient que les transitions, jamais l'usage brut : un parcours en compte cinq
 * ou six dans toute sa vie, l'activité se compte en milliers.
 */
class AdoptionEvent extends Model
{
    use ExcludesTestData;

    protected $table = 'fact_adoption_events';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'is_progression' => 'boolean',
            'is_regression' => 'boolean',
            'is_reactivation' => 'boolean',
            'is_test' => 'boolean',
            'computed_at' => 'datetime',
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

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(AdoptionStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(AdoptionStage::class, 'to_stage_id');
    }

    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(AdoptionRuleVersion::class, 'rule_version_id');
    }

    public function date(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'date_id');
    }

    public function scopeReactivations(Builder $query): Builder
    {
        return $query->where('is_reactivation', true);
    }

    public function scopeRegressions(Builder $query): Builder
    {
        return $query->where('is_regression', true);
    }

    /**
     * Une transition produite par un paiement constaté et une transition produite
     * par un franchissement de seuil n'ont pas la même fiabilité.
     */
    public function scopeTriggeredBy(Builder $query, string $trigger): Builder
    {
        return $query->where('trigger_type', $trigger);
    }

    public function scopeObserved(Builder $query): Builder
    {
        return $query->whereIn('trigger_type', ['payment', 'registration', 'activity', 'sync']);
    }

    public function scopeDerived(Builder $query): Builder
    {
        return $query->where('trigger_type', 'inactivity_rule');
    }
}
