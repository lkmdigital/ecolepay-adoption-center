<?php

namespace App\Shared\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Filtre explicite des données de développement.
 *
 * Volontairement local et non global : un filtre global qui masque des lignes
 * surprend au débogage. En contrepartie, tout calcul d'indicateur doit appeler
 * `production()` — l'oublier fausse silencieusement les KPI.
 */
trait ExcludesTestData
{
    public function scopeProduction(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_test'), false);
    }

    public function scopeOnlyTestData(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_test'), true);
    }
}
