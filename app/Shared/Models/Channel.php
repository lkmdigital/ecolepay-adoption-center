<?php

namespace App\Shared\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Canaux de communication — table `dim_channels`.
 */
class Channel extends Model
{
    use HasFactory;

    protected $table = 'dim_channels';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'default_unit_cost' => 'decimal:4',
            'supports_rich_content' => 'boolean',
            'requires_opt_in' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Le coût stocké ici n'est qu'indicatif : le montant réellement facturé est
     * figé sur chaque ligne de `fact_campaign_contacts` à l'envoi. Recalculer une
     * ancienne campagne au tarif courant falsifierait sa rentabilité.
     */
    public function estimateCostFor(int $recipients): float
    {
        return (float) $this->default_unit_cost * $recipients;
    }
}
