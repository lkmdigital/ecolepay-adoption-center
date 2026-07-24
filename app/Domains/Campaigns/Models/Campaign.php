<?php

namespace App\Domains\Campaigns\Models;

use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Users\Models\User;
use App\Shared\Models\AdoptionStage;
use App\Shared\Models\Channel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dimension campagne — table `dim_campaigns`. Lignée native : créée dans EAC.
 *
 * Suppression logique obligatoire : la permission `campaigns.delete` existe, mais
 * une suppression physique orphelinerait `fact_campaign_contacts` et effacerait des
 * coûts réellement engagés.
 *
 * Seul modèle du schéma analytique avec un `$fillable` explicite : c'est le seul
 * alimenté depuis une requête HTTP.
 */
class Campaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'dim_campaigns';

    protected $fillable = [
        'name', 'slug', 'objective', 'target_stage_id', 'channel_id',
        'target_segment', 'message_template', 'scheduled_at',
        'estimated_cost', 'currency', 'attribution_window_days',
    ];

    protected function casts(): array
    {
        return [
            'target_segment' => 'array',
            'status' => CampaignStatus::class,
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'estimated_cost' => 'decimal:2',
            'actual_cost' => 'decimal:2',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    public function targetStage(): BelongsTo
    {
        return $this->belongsTo(AdoptionStage::class, 'target_stage_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CampaignContact::class, 'campaign_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(CampaignResult::class, 'campaign_id');
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', CampaignStatus::Sent);
    }

    public function scopeReadyToEvaluate(Builder $query): Builder
    {
        return $query->where('status', CampaignStatus::Sent)
            ->whereNotNull('completed_at')
            ->whereRaw('DATE_ADD(completed_at, INTERVAL attribution_window_days DAY) <= NOW()');
    }

    /**
     * La fenêtre d'attribution est portée par la campagne : une relance de paiement
     * se juge à quelques jours, une campagne de notoriété à plusieurs semaines.
     */
    public function attributionWindowHasClosed(): bool
    {
        return $this->completed_at !== null
            && $this->completed_at->addDays($this->attribution_window_days)->isPast();
    }
}
