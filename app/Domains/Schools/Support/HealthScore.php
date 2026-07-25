<?php

namespace App\Domains\Schools\Support;

use Illuminate\Support\Carbon;

/**
 * Score de santé composite (0–100) d'une école : un seul indicateur de priorité
 * agrégeant cinq critères pondérés. Partagé entre la liste des écoles et la fiche.
 *
 * Le critère « campagnes » est réservé (poids 0) tant que le module n'existe pas ;
 * il figure dans le détail pour rester transparent sur ce qui n'est pas encore mesuré.
 */
final class HealthScore
{
    /**
     * @return array{score: int, color: string, bg: string, dot: string, breakdown: list<array{label: string, score: int, weight: int, available: bool}>}
     */
    public static function compute(float $rate, int $known, int $inscrits, int $actifs, int $recent, int $revenue, ?string $lastActivity): array
    {
        // Adoption : 60 % de taux vaut déjà l'excellence dans ce contexte.
        $adoption = (int) min(100, round($rate / 60 * 100));
        // Paiements : part des inscrits qui ont activé (premier paiement).
        $paiements = $inscrits > 0 ? (int) min(100, round($actifs / $inscrits * 100)) : 0;
        // Qualité des données : complétude d'inscription, pénalisée si base trop faible.
        $qualite = $known < 15 ? 30 : (int) min(100, round($inscrits / max($known, 1) * 100));
        // Évolution : adoptants récents (90 j) rapportés au socle actif.
        $evolution = $actifs > 0 ? (int) min(100, round($recent / $actifs * 100)) : 0;
        // Activité récente : ancienneté du dernier paiement.
        $days = $lastActivity ? (int) Carbon::parse($lastActivity)->diffInDays(Carbon::now()) : null;
        $activite = $days === null ? 0 : ($days <= 30 ? 100 : ($days <= 90 ? 70 : ($days <= 180 ? 40 : 15)));

        $breakdown = [
            ['label' => 'Adoption', 'score' => $adoption, 'weight' => 35, 'available' => true],
            ['label' => 'Paiements (activation)', 'score' => $paiements, 'weight' => 20, 'available' => true],
            ['label' => 'Qualité des données', 'score' => $qualite, 'weight' => 15, 'available' => true],
            ['label' => 'Évolution', 'score' => $evolution, 'weight' => 15, 'available' => true],
            ['label' => 'Activité récente', 'score' => $activite, 'weight' => 15, 'available' => true],
            ['label' => 'Campagnes', 'score' => 0, 'weight' => 0, 'available' => false],
        ];

        $score = (int) round(collect($breakdown)->sum(fn ($c) => $c['score'] * $c['weight']) / 100);

        [$color, $bg] = match (true) {
            $score >= 70 => ['#0F7A44', '#E9F8EF'],
            $score >= 40 => ['#B45F04', '#FEF3E2'],
            default => ['#B91C1C', '#FDECEC'],
        };

        return ['score' => $score, 'color' => $color, 'bg' => $bg, 'dot' => $color, 'breakdown' => $breakdown];
    }
}
