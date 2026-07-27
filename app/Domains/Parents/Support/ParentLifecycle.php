<?php

namespace App\Domains\Parents\Support;

/**
 * Cycle de vie officiel d'un parent dans EcolePay. L'adoption = premier paiement,
 * jamais la seule création de compte.
 *
 *   Connu (numéro fourni) → Inscrit (compte créé) → Adoptant (⭐ 1er paiement)
 *   → Engagé (paiements récurrents / usage continu)
 *
 * Partagé par tous les écrans pour garantir un vocabulaire unique.
 */
final class ParentLifecycle
{
    /**
     * @return array{level: string, label: string, short: string, color: string, bg: string, rank: int, star: bool}
     */
    public static function of(bool $hasAccount, bool $hasPaid, bool $isRecurring): array
    {
        if ($hasPaid && $isRecurring) {
            return ['level' => 'engage', 'label' => 'Parent engagé', 'short' => 'Engagé', 'color' => '#0B6A3B', 'bg' => '#DDF3E7', 'rank' => 4, 'star' => true];
        }
        if ($hasPaid) {
            return ['level' => 'adoptant', 'label' => 'Parent adoptant', 'short' => 'Adoptant', 'color' => '#0F7A44', 'bg' => '#E9F8EF', 'rank' => 3, 'star' => false];
        }
        if ($hasAccount) {
            return ['level' => 'inscrit', 'label' => 'Parent inscrit', 'short' => 'Inscrit', 'color' => '#1D4ED8', 'bg' => '#E7EEFE', 'rank' => 2, 'star' => false];
        }

        return ['level' => 'connu', 'label' => 'Parent connu', 'short' => 'Connu', 'color' => '#6B7280', 'bg' => '#F2F3F5', 'rank' => 1, 'star' => false];
    }

    /** Les quatre étapes, dans l'ordre — pour les filtres et la barre de progression. */
    public static function stages(): array
    {
        return [
            'connu' => 'Parent connu',
            'inscrit' => 'Parent inscrit',
            'adoptant' => 'Parent adoptant',
            'engage' => 'Parent engagé',
        ];
    }
}
