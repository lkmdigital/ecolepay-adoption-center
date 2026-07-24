<?php

namespace App\Shared\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Catalogue des événements d'usage — table `dim_event_types`.
 *
 * `counts_as_activity` définit la frontière entre « engagé » et « à risque » :
 * recevoir une notification n'est pas un usage, l'ouvrir en est un.
 */
class EventType extends Model
{
    protected $table = 'dim_event_types';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'counts_as_activity' => 'boolean',
            'is_value_action' => 'boolean',
            'activity_weight' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function scopeQualifying(Builder $query): Builder
    {
        return $query->where('counts_as_activity', true);
    }

    public function scopeValueActions(Builder $query): Builder
    {
        return $query->where('is_value_action', true);
    }

    /**
     * @return list<string>
     */
    public static function qualifyingCodes(): array
    {
        return static::query()->qualifying()->pluck('code')->all();
    }
}
