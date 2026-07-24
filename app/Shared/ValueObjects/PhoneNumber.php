<?php

namespace App\Shared\ValueObjects;

/**
 * Numéro de téléphone ivoirien normalisé.
 *
 * Le téléphone est l'identité métier du parent (identifiant de connexion EcolePay).
 * On le ramène à une forme canonique de 10 chiffres avant tout hachage, sinon deux
 * écritures du même numéro (avec/sans +225, avec espaces) donneraient deux parents.
 *
 * Format ivoirien depuis 2021 : 10 chiffres (ex. 0708818239). Les numéros à 8/9
 * chiffres, tronqués ou vides sont invalides — la source en contient ~11 %.
 */
final readonly class PhoneNumber
{
    private function __construct(public string $canonical) {}

    /**
     * Retourne le numéro normalisé, ou null si invalide.
     */
    public static function tryFrom(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        // Indicatif +225 : on le retire pour obtenir les 10 chiffres nationaux.
        if (strlen($digits) === 13 && str_starts_with($digits, '225')) {
            $digits = substr($digits, 3);
        }

        // Numéro ivoirien valide = exactement 10 chiffres commençant par 0.
        if (strlen($digits) !== 10 || $digits[0] !== '0') {
            return null;
        }

        return new self($digits);
    }

    public function equals(self $other): bool
    {
        return $this->canonical === $other->canonical;
    }

    public function __toString(): string
    {
        return $this->canonical;
    }
}
