<?php

namespace App\Domains\Dashboard\Actions;

use App\Domains\Dashboard\Data\AdoptionSummary;
use App\Domains\Users\Models\User;

/**
 * Cas d'usage : une classe, une responsabilité, invocable.
 *
 * Les chiffres réels arriveront avec les domaines Schools et Parents ;
 * seul le comptage des utilisateurs est branché pour l'instant.
 */
final class BuildAdoptionSummary
{
    public function __invoke(): AdoptionSummary
    {
        return new AdoptionSummary(
            schools: 0,
            parents: User::count(),
            adoptionRate: 0.0,
        );
    }
}
