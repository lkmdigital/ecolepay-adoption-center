<?php

namespace App\Domains\Campaigns\Models;

use App\Domains\Campaigns\Enums\CampaignChannel;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Schools\Models\School;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Campagne marketing — table `dim_campaigns`. Lignée native : créée dans EAC.
 *
 * Le workflow : la campagne tourne dans Perfect CX / WhatsApp ; on importe sa liste
 * de contacts dans EAC pour en mesurer l'impact. `campaign_date` est la date de
 * référence de l'attribution (conversions comptées après cette date).
 *
 * Suppression logique : une suppression physique orphelinerait les contacts et
 * effacerait des coûts réellement engagés.
 */
class Campaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'dim_campaigns';

    protected $fillable = [
        'name', 'slug', 'description', 'school_id', 'owner', 'channel', 'status',
        'campaign_date', 'attribution_window_days', 'cost', 'contacts_count', 'valid_count',
        'invalid_count', 'duplicate_count', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'channel' => CampaignChannel::class,
            'campaign_date' => 'date',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CampaignContact::class, 'campaign_id');
    }
}
