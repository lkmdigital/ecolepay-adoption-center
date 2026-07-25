<?php

namespace App\Domains\Dashboard\Data;

/**
 * Indicateurs de l'écran d'accueil, prêts à afficher.
 *
 * Les taux ne sont jamais stockés : ils se calculent ici depuis numérateur et
 * dénominateur, pour rester additifs et justes.
 */
final readonly class DashboardKpis
{
    public function __construct(
        public int $connus,
        public int $inscrits,
        public int $adoptants,
        public int $engages,
        public int $aRisque,
        public int $perdus,
        public int $revenue,
        public int $activeSchools,
        public int $potentialRevenue = 0,
        public int $urgentSchools = 0,
    ) {}

    /**
     * Parents connus qui n'ont pas encore adopté : la cible de conversion.
     */
    public function nonAdopters(): int
    {
        return max($this->connus - $this->adoptants, 0);
    }

    public function adoptionRate(): float
    {
        return $this->connus > 0 ? round($this->adoptants / $this->connus * 100, 1) : 0.0;
    }

    public function registrationRate(): float
    {
        return $this->connus > 0 ? round($this->inscrits / $this->connus * 100, 1) : 0.0;
    }

    public function activationRate(): float
    {
        return $this->inscrits > 0 ? round($this->adoptants / $this->inscrits * 100, 1) : 0.0;
    }
}
