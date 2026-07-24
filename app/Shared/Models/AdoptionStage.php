<?php

namespace App\Shared\Models;

use App\Shared\Enums\AdoptionStageCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Les six états de l'entonnoir — table `dim_adoption_stages`.
 *
 * Donnée de référence, alimentée par un seeder. Clé TINYINT : elle est recopiée
 * dans chaque ligne de fait, et `fact_adoption_events` en porte deux.
 */
class AdoptionStage extends Model
{
    use HasFactory;

    protected $table = 'dim_adoption_stages';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'code' => AdoptionStageCode::class,
            'is_converted' => 'boolean',
            'is_active_state' => 'boolean',
            'is_terminal' => 'boolean',
            'is_derived' => 'boolean',
        ];
    }

    public function scopeConverted(Builder $query): Builder
    {
        return $query->where('is_converted', true);
    }

    /**
     * « À risque » et « perdu » : déduits d'un seuil, non observés. Les présenter
     * comme les autres états serait trompeur.
     */
    public function scopeDerived(Builder $query): Builder
    {
        return $query->where('is_derived', true);
    }

    public function scopeInFunnelOrder(Builder $query): Builder
    {
        return $query->orderBy('funnel_rank');
    }

    public static function idFor(AdoptionStageCode $code): int
    {
        return (int) static::query()->where('code', $code->value)->value('id');
    }
}
