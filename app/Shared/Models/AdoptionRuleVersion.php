<?php

namespace App\Shared\Models;

use App\Domains\Users\Models\User;
use App\Shared\Concerns\HasCurrentVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versions de la règle produisant « à risque » et « perdu » — table
 * `dim_adoption_rule_versions`.
 *
 * Sans elle, une inflexion du nombre de parents à risque ne se distingue pas d'un
 * changement de définition.
 */
class AdoptionRuleVersion extends Model
{
    use HasCurrentVersion;

    protected $table = 'dim_adoption_rule_versions';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qualifying_event_types' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_current' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public static function active(): ?self
    {
        return static::query()->current()->first();
    }

    /**
     * Un type d'événement entre-t-il dans le calcul d'inactivité pour cette version ?
     */
    public function counts(string $eventTypeCode): bool
    {
        return in_array($eventTypeCode, $this->qualifying_event_types ?? [], true);
    }
}
