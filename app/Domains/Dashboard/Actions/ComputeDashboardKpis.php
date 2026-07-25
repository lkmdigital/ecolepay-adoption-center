<?php

namespace App\Domains\Dashboard\Actions;

use App\Domains\Dashboard\Data\DashboardKpis;
use App\Domains\Parents\Models\ParentJourney;
use App\Domains\Parents\Models\Payment;
use App\Domains\Schools\Models\School;
use App\Shared\Enums\AdoptionStageCode;
use Illuminate\Support\Facades\DB;

/**
 * Calcule les KPI de l'écran d'accueil depuis l'entrepôt.
 *
 * Lecture directe des faits/parcours : à l'échelle actuelle (~30 k parcours) c'est
 * instantané. On introduira des agrégats (`agg_*`) le jour où un écran sera lent.
 */
final class ComputeDashboardKpis
{
    public function __invoke(): DashboardKpis
    {
        // Effectifs de l'entonnoir, au niveau parent (dédupliqué).
        $connus = (int) DB::table('dim_parents')->where('is_test', false)->count();
        $inscrits = (int) DB::table('dim_parents')->where('is_test', false)
            ->whereNotNull('account_created_at')->count();
        $adoptants = (int) ParentJourney::query()->production()
            ->where('has_ever_paid', true)->distinct()->count('parent_id');

        // Statuts vivants (au niveau parcours), pour la répartition.
        $byStage = $this->liveStageCounts();

        // Chiffre d'affaires : total des paiements via l'app validés (hors manuels).
        $revenue = (int) Payment::query()->production()->countsForAdoption()->sum('amount');

        $activeSchools = (int) School::query()->where('is_test', false)->current()->active()->count();

        return new DashboardKpis(
            connus: $connus,
            inscrits: $inscrits,
            adoptants: $adoptants,
            engages: $byStage[AdoptionStageCode::Engaged->value] ?? 0,
            aRisque: $byStage[AdoptionStageCode::AtRisk->value] ?? 0,
            perdus: $byStage[AdoptionStageCode::Lost->value] ?? 0,
            revenue: $revenue,
            activeSchools: $activeSchools,
        );
    }

    /**
     * @return array<string, int> code d'état → nombre de parcours
     */
    private function liveStageCounts(): array
    {
        return ParentJourney::query()->production()
            ->join('dim_adoption_stages as s', 's.id', '=', 'fact_parent_journeys.current_stage_id')
            ->groupBy('s.code')
            ->pluck(DB::raw('COUNT(*)'), 's.code')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
