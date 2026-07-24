<?php

namespace App\Shared\Enums;

/**
 * Les six états de l'entonnoir.
 *
 * Le rang porte l'ordre de l'entonnoir, qui est une information analytique :
 * c'est lui qui distingue une progression d'une régression.
 */
enum AdoptionStageCode: string
{
    case Known = 'known';
    case Registered = 'registered';
    case Adopter = 'adopter';
    case Engaged = 'engaged';
    case AtRisk = 'at_risk';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Known => 'Connu',
            self::Registered => 'Inscrit',
            self::Adopter => 'Adoptant',
            self::Engaged => 'Engagé',
            self::AtRisk => 'À risque',
            self::Lost => 'Perdu',
        };
    }

    public function funnelRank(): int
    {
        return match ($this) {
            self::Known => 1,
            self::Registered => 2,
            self::Adopter => 3,
            self::Engaged => 4,
            self::AtRisk => 5,
            self::Lost => 6,
        };
    }

    /**
     * A franchi le premier paiement : la conversion réelle.
     */
    public function isConverted(): bool
    {
        return in_array($this, [self::Adopter, self::Engaged, self::AtRisk, self::Lost], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Adopter, self::Engaged], true);
    }

    /**
     * Déduit d'une règle d'inactivité plutôt qu'observé dans les données source.
     * Ces deux états n'ont pas la même fiabilité que les autres.
     */
    public function isDerived(): bool
    {
        return in_array($this, [self::AtRisk, self::Lost], true);
    }
}
