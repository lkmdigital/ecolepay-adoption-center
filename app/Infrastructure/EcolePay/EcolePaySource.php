<?php

namespace App\Infrastructure\EcolePay;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Point d'entrée unique vers la base EcolePay, en LECTURE SEULE.
 *
 * Toute lecture de la source passe par ici. Aucune méthode d'écriture n'existe :
 * EAC ne modifie jamais EcolePay.
 */
final class EcolePaySource
{
    public const CONNECTION = 'ecolepay';

    public function table(string $name): Builder
    {
        return DB::connection(self::CONNECTION)->table($name);
    }

    /**
     * Vérifie que la source est joignable et lisible.
     */
    public function isReachable(): bool
    {
        try {
            DB::connection(self::CONNECTION)->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
