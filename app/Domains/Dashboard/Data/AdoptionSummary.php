<?php

namespace App\Domains\Dashboard\Data;

/**
 * Objet de transfert : structure figée que la vue consomme sans requêter.
 */
final readonly class AdoptionSummary
{
    public function __construct(
        public int $schools,
        public int $parents,
        public float $adoptionRate,
    ) {}

    public function formattedRate(): string
    {
        return number_format($this->adoptionRate * 100, 1).' %';
    }
}
