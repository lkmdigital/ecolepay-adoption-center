<?php

namespace App\Domains\Schools\Actions;

use App\Domains\Parents\Models\ParentJourney;
use Illuminate\Support\Collection;

/**
 * Liste des écoles avec leurs métriques d'adoption et un score de santé.
 *
 * ~110 écoles : tout est calculé en une requête groupée, filtré/trié ensuite côté
 * Livewire sans pagination serveur.
 */
final class ListSchoolsWithAdoption
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function __invoke(): Collection
    {
        // Le filtre is_test des parcours est dans la jointure (pas en WHERE) pour
        // que les écoles SANS aucun parcours apparaissent quand même (rightJoin).
        return ParentJourney::query()
            ->rightJoin('dim_schools as s', function ($join) {
                $join->on('s.id', '=', 'fact_parent_journeys.school_id')
                    ->where('fact_parent_journeys.is_test', false);
            })
            ->where('s.is_test', false)
            ->whereNotNull('s.is_current')
            ->groupBy('s.id', 's.name', 's.city', 's.region', 's.status', 's.subscription_model')
            ->selectRaw('s.id, s.name, s.city, s.region, s.status, s.subscription_model')
            ->selectRaw('COUNT(DISTINCT fact_parent_journeys.parent_id) as known')
            ->selectRaw('COUNT(DISTINCT CASE WHEN has_ever_paid = 1 THEN parent_id END) as adopters')
            ->orderByDesc('adopters')
            ->get()
            ->map(function ($r) {
                $known = (int) $r->known;
                $adopters = (int) $r->adopters;
                $rate = $known > 0 ? round($adopters / $known * 100, 1) : 0.0;

                return [
                    'id' => $r->id,
                    'name' => $r->name,
                    'city' => $r->city,
                    'region' => $r->region,
                    'status' => $r->status,
                    'subscription_model' => $r->subscription_model,
                    'known' => $known,
                    'adopters' => $adopters,
                    'rate' => $rate,
                    'health' => $this->health($rate, $known),
                ];
            });
    }

    /**
     * Score de santé sur 4 niveaux (catalogue KPI).
     *
     * @return array{level: string, label: string, color: string, bg: string}
     */
    private function health(float $rate, int $known): array
    {
        // Base trop faible pour conclure.
        if ($known < 20) {
            return ['level' => 'nd', 'label' => 'Données insuffisantes', 'color' => '#6B7280', 'bg' => '#F2F3F5'];
        }

        return match (true) {
            $rate >= 50 => ['level' => 'reference', 'label' => 'Référence', 'color' => '#0F7A44', 'bg' => '#E9F8EF'],
            $rate >= 25 => ['level' => 'satisfaisante', 'label' => 'Satisfaisante', 'color' => '#1D3F9C', 'bg' => '#EEF3FE'],
            $rate >= 10 => ['level' => 'fragile', 'label' => 'Fragile', 'color' => '#B45F04', 'bg' => '#FEF3E2'],
            default => ['level' => 'prioritaire', 'label' => 'Prioritaire', 'color' => '#B91C1C', 'bg' => '#FDECEC'],
        };
    }
}
