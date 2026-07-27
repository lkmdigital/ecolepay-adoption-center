<?php

namespace App\Domains\Campaigns\Models;

use App\Domains\Parents\Models\ParentProfile;
use App\Shared\Concerns\ExcludesTestData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contact ciblé — table `fact_campaign_contacts`, grain : un numéro dans une campagne.
 *
 * `phone_hash` est la clé d'identité qui relie un contact importé à un parent
 * EcolePay. Les données personnelles (téléphone) sont masquées à la sérialisation.
 */
class CampaignContact extends Model
{
    use ExcludesTestData;

    protected $table = 'fact_campaign_contacts';

    protected $guarded = [];

    protected $hidden = ['phone_e164', 'phone_hash', 'raw_phone'];

    protected function casts(): array
    {
        return [
            'is_valid' => 'boolean',
            'had_account_before' => 'boolean',
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
}
