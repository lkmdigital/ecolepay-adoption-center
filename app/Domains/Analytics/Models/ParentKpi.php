<?php

namespace App\Domains\Analytics\Models;

use App\Domains\Parents\Models\ParentProfile;
use App\Domains\Schools\Models\School;
use App\Shared\Enums\AdoptionStageCode;
use App\Shared\Models\AdoptionStage;
use App\Shared\Models\Channel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agrégat — table `agg_parent_kpis`, grain : un parent, tous établissements
 * confondus.
 *
 * Raison d'être : `fact_parent_journeys` a pour grain « parent × école ». Compter
 * les adoptants au niveau national en sommant ses lignes compterait deux fois les
 * parents multi-écoles. Cette table déduplique.
 *
 * Sert aussi de plan de ciblage : les colonnes dénormalisées évitent une jointure
 * lourde au moment de constituer un segment de campagne.
 */
class ParentKpi extends Model
{
    protected $table = 'agg_parent_kpis';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_converted_anywhere' => 'boolean',
            'is_active_anywhere' => 'boolean',
            'is_at_risk_everywhere' => 'boolean',
            'first_known_at' => 'datetime',
            'first_registered_at' => 'datetime',
            'first_payment_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'marketing_consent' => 'boolean',
            'computed_at' => 'datetime',
            'source_watermark_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ParentProfile::class, 'parent_id');
    }

    public function primarySchool(): BelongsTo
    {
        return $this->belongsTo(School::class, 'primary_school_id');
    }

    /**
     * État le plus avancé, tous établissements confondus.
     */
    public function overallStage(): BelongsTo
    {
        return $this->belongsTo(AdoptionStage::class, 'overall_stage_id');
    }

    /**
     * Garde-fou : un parent « adoptant » à l'école A et « perdu » à l'école B ne
     * doit pas être réduit à une seule lecture.
     */
    public function worstStage(): BelongsTo
    {
        return $this->belongsTo(AdoptionStage::class, 'worst_stage_id');
    }

    public function preferredChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'preferred_channel_id');
    }

    public function scopeConverted(Builder $query): Builder
    {
        return $query->where('is_converted_anywhere', true);
    }

    public function scopeMultiSchool(Builder $query): Builder
    {
        return $query->where('school_count', '>', 1);
    }

    public function scopeInStage(Builder $query, AdoptionStageCode $code): Builder
    {
        return $query->whereHas('overallStage', fn (Builder $s) => $s->where('code', $code->value));
    }

    /**
     * Segment de campagne : consentement et état, sans jointure.
     */
    public function scopeTargetable(Builder $query): Builder
    {
        return $query->where('marketing_consent', true);
    }

    public function scopeInactiveFor(Builder $query, int $days): Builder
    {
        return $query->where('days_since_last_activity', '>=', $days);
    }
}
