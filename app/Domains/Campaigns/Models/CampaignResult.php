<?php

namespace App\Domains\Campaigns\Models;

use App\Domains\Parents\Models\ParentProfile;
use App\Domains\Parents\Models\Payment;
use App\Domains\Schools\Models\School;
use App\Shared\Models\AdoptionStage;
use App\Shared\Models\CalendarDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Instantané cumulatif — table `fact_campaign_results`, grain : un parent × une
 * campagne, évalué après clôture de la fenêtre d'attribution.
 *
 * Séparée de `CampaignContact` parce qu'un changement de modèle d'attribution ne
 * doit jamais réécrire des faits de remise réellement observés.
 */
class CampaignResult extends Model
{
    protected $table = 'fact_campaign_results';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'window_closed_at' => 'datetime',
            'has_progressed' => 'boolean',
            'converted' => 'boolean',
            'is_reactivation' => 'boolean',
            'attributed_amount' => 'decimal:2',
            'computed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ParentProfile::class, 'parent_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CampaignContact::class, 'contact_id');
    }

    public function attributedPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'attributed_payment_id');
    }

    public function stageAtSend(): BelongsTo
    {
        return $this->belongsTo(AdoptionStage::class, 'stage_at_send_id');
    }

    public function stageAtClose(): BelongsTo
    {
        return $this->belongsTo(AdoptionStage::class, 'stage_at_close_id');
    }

    public function conversionDate(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'conversion_date_id');
    }

    public function scopeConverted(Builder $query): Builder
    {
        return $query->where('converted', true);
    }

    /**
     * Cas ambigus : plusieurs campagnes dans la même fenêtre. Sans ce garde-fou, la
     * somme des conversions attribuées peut dépasser le nombre de conversions
     * réelles — un chiffre faux et flatteur, donc rarement contesté.
     */
    public function scopeContested(Builder $query): Builder
    {
        return $query->where('competing_contacts', '>', 0);
    }

    public function scopeComputedWith(Builder $query, string $version): Builder
    {
        return $query->where('computation_version', $version);
    }
}
