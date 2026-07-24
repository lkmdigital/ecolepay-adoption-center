<?php

namespace App\Domains\Parents\Models;

use App\Domains\Schools\Models\School;
use App\Shared\Concerns\ExcludesTestData;
use App\Shared\Models\CalendarDate;
use App\Shared\Models\EventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fait transactionnel — table `fact_parent_activities`, grain : un événement d'usage.
 *
 * Table indispensable : les états « engagé », « à risque » et « perdu » se déduisent
 * de l'inactivité. Sans elle, les transitions `inactivity_rule` n'ont aucune source.
 *
 * Table la plus volumineuse du schéma — la seule dont le volume croît avec l'usage
 * et non avec la population.
 */
class ParentActivity extends Model
{
    use ExcludesTestData;

    protected $table = 'fact_parent_activities';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'is_test' => 'boolean',
            'synced_at' => 'datetime',
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

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class, 'event_type_id');
    }

    public function date(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'date_id');
    }

    /**
     * Seuls les événements qualifiants entrent dans le calcul d'inactivité :
     * recevoir une notification n'est pas un usage, l'ouvrir en est un.
     */
    public function scopeQualifying(Builder $query): Builder
    {
        return $query->whereHas('eventType', fn (Builder $type) => $type->where('counts_as_activity', true));
    }

    public function scopeSince(Builder $query, int $dateKey): Builder
    {
        return $query->where('date_id', '>=', $dateKey);
    }

    public function scopeForParent(Builder $query, ParentProfile|int $parent): Builder
    {
        return $query->where('parent_id', $parent instanceof ParentProfile ? $parent->id : $parent);
    }
}
