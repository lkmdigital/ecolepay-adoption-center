<?php

namespace App\Shared\Support;

use Illuminate\Support\Carbon;

/**
 * Année scolaire ivoirienne, frontière en septembre (rentrée nationale).
 *
 * `payer.annee_scolaire` étant souvent vide, l'année d'un paiement se déduit de sa
 * date via cette frontière. Une date de septembre ou après appartient à l'année qui
 * commence ; avant, à l'année précédente.
 */
final class SchoolYear
{
    public function __construct(public readonly int $startYear) {}

    public static function forDate(Carbon|string $date): self
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $boundaryMonth = (int) config('eac.calendar.school_year_start.month', 9);

        return new self($date->month >= $boundaryMonth ? $date->year : $date->year - 1);
    }

    public static function current(): self
    {
        return self::forDate(Carbon::now());
    }

    public function label(): string
    {
        return sprintf('%d-%d', $this->startYear, $this->startYear + 1);
    }

    /**
     * Nombre d'années scolaires entre celle-ci et une autre (this - other).
     * Sert au calcul du statut d'adoption (écart depuis le dernier paiement).
     */
    public function gapFrom(self $other): int
    {
        return $this->startYear - $other->startYear;
    }
}
