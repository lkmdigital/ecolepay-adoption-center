<?php

namespace App\Domains\Campaigns\Models;

use App\Domains\Campaigns\Enums\DeliveryStatus;
use App\Domains\Parents\Models\ParentProfile;
use App\Domains\Schools\Models\School;
use App\Shared\Concerns\ExcludesTestData;
use App\Shared\Models\AdoptionStage;
use App\Shared\Models\CalendarDate;
use App\Shared\Models\Channel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fait transactionnel — table `fact_campaign_contacts`, grain : un message pour un
 * parent dans une campagne.
 *
 * Seule table de faits réellement mutable après insertion : les accusés de remise
 * arrivent des heures, voire des jours plus tard. Tout agrégat calculé dessus doit
 * être recalculable sur une fenêtre glissante de 30 jours, sinon les taux
 * d'ouverture restent sous-évalués pour toujours.
 */
class CampaignContact extends Model
{
    use ExcludesTestData;

    protected $table = 'fact_campaign_contacts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'failed_at' => 'datetime',
            'delivery_status' => DeliveryStatus::class,
            'actual_cost' => 'decimal:4',
            'is_test' => 'boolean',
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

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    public function date(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'date_id');
    }

    /**
     * État du parent au moment du ciblage. Indispensable et non reconstituable :
     * sans lui, impossible de juger a posteriori si la campagne visait juste.
     */
    public function stageAtSend(): BelongsTo
    {
        return $this->belongsTo(AdoptionStage::class, 'stage_id_at_send');
    }

    public function scopeDelivered(Builder $query): Builder
    {
        return $query->whereNotNull('delivered_at');
    }

    public function scopeEngaged(Builder $query): Builder
    {
        return $query->whereNotNull('opened_at')->orWhereNotNull('clicked_at');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('delivery_status', [DeliveryStatus::Failed, DeliveryStatus::Bounced]);
    }
}
