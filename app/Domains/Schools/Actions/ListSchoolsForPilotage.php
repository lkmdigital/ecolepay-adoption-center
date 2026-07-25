<?php

namespace App\Domains\Schools\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Portefeuille des écoles pour le centre de pilotage : une ligne riche par
 * établissement plus les cartes de synthèse.
 *
 * ~110 écoles : tout est calculé en quelques requêtes groupées, puis filtré,
 * trié et paginé côté Livewire — pas de pagination serveur nécessaire.
 */
final class ListSchoolsForPilotage
{
    /**
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function __invoke(): array
    {
        $rows = $this->rows();

        return [
            'rows' => $rows,
            'summary' => $this->summary($rows),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        // Effectifs par école (élèves, connus, inscrits, actifs, élan 90 j).
        $base = DB::table('dim_schools as s')
            ->leftJoin('fact_parent_journeys as j', function ($join) {
                $join->on('j.school_id', '=', 's.id')->where('j.is_test', false);
            })
            ->leftJoin('dim_parents as p', 'p.id', '=', 'j.parent_id')
            ->where('s.is_test', false)->whereNotNull('s.is_current')
            ->groupBy('s.id', 's.name', 's.school_code', 's.subscription_model', 's.subscription_amount', 's.onboarded_at', 's.status')
            ->selectRaw('s.id, s.name, s.school_code, s.subscription_model, s.subscription_amount, s.onboarded_at, s.status')
            ->selectRaw('COUNT(DISTINCT j.parent_id) as known')
            ->selectRaw('COUNT(DISTINCT CASE WHEN p.account_created_at IS NOT NULL THEN j.parent_id END) as inscrits')
            ->selectRaw('COUNT(DISTINCT CASE WHEN j.has_ever_paid = 1 THEN j.parent_id END) as actifs')
            ->selectRaw('COUNT(DISTINCT CASE WHEN j.first_payment_at >= ? THEN j.parent_id END) as recent', [Carbon::now()->subDays(90)])
            ->get();

        // Élèves par école (le compteur dénormalisé de dim_schools n'est pas peuplé).
        $students = DB::table('dim_students')->where('is_test', false)->whereNotNull('school_id')
            ->selectRaw('school_id, COUNT(*) as n')->groupBy('school_id')->pluck('n', 'school_id');

        // Revenu et dernière activité par école.
        $payments = DB::table('fact_payments')->where('is_test', false)->where('is_manual', false)->where('status', 'success')
            ->selectRaw('school_id, SUM(amount) as revenue, MAX(paid_at) as last_activity')
            ->groupBy('school_id')->get()->keyBy('school_id');

        return $base->map(function ($r) use ($students, $payments) {
            $known = (int) $r->known;
            $actifs = (int) $r->actifs;
            $rate = $known > 0 ? round($actifs / $known * 100, 1) : 0.0;
            $nonAdopters = max($known - $actifs, 0);
            $potential = $r->subscription_model === 'parent_paid' ? $nonAdopters * (int) $r->subscription_amount : 0;
            $pay = $payments[$r->id] ?? null;
            $onboarded = $r->onboarded_at ? Carbon::parse($r->onboarded_at) : null;

            return [
                'id' => $r->id,
                'name' => $r->name,
                'code' => $r->school_code,
                'students' => (int) ($students[$r->id] ?? 0),
                'known' => $known,
                'inscrits' => (int) $r->inscrits,
                'actifs' => $actifs,
                'rate' => $rate,
                'revenue' => (int) ($pay->revenue ?? 0),
                'potential' => $potential,
                'recent' => (int) $r->recent,
                'lastActivity' => $pay->last_activity ?? null,
                'subscriptionModel' => $r->subscription_model,
                'onboardedAt' => $onboarded?->toDateString(),
                'badge' => $this->badge($rate, $known),
            ];
        })->all();
    }

    /**
     * Badge de statut d'adoption (taxonomie de la maquette, hors « Nouvelle école »).
     *
     * Le badge « Nouvelle école » n'est pas dérivé : onboarded_at est un backfill de
     * synchro (~100 écoles datées mai-juil. 2026), pas une vraie date d'onboarding.
     *
     * @return array{level: string, label: string, color: string, bg: string}
     */
    private function badge(float $rate, int $known): array
    {
        if ($known < 15) {
            return ['level' => 'insuffisant', 'label' => 'Base insuffisante', 'color' => '#6B7280', 'bg' => '#F2F3F5'];
        }

        return match (true) {
            $rate >= 70 => ['level' => 'excellente', 'label' => 'Excellente adoption', 'color' => '#0F7A44', 'bg' => '#E9F8EF'],
            $rate >= 40 => ['level' => 'progression', 'label' => 'Bonne progression', 'color' => '#B45309', 'bg' => '#FEF9E7'],
            $rate >= 20 => ['level' => 'surveiller', 'label' => 'À surveiller', 'color' => '#B45F04', 'bg' => '#FEF3E2'],
            default => ['level' => 'critique', 'label' => 'Critique', 'color' => '#B91C1C', 'bg' => '#FDECEC'],
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows): array
    {
        $rows = collect($rows);
        $totalKnown = $rows->sum('known');
        $totalActifs = $rows->sum('actifs');

        return [
            'total' => $rows->count(),
            'avgAdoption' => $totalKnown > 0 ? round($totalActifs / $totalKnown * 100, 1) : 0.0,
            'critical' => $rows->filter(fn ($s) => $s['known'] >= 20 && $s['rate'] < 20)->count(),
            'potential' => (int) $rows->sum('potential'),
            'adoptionSpark' => $this->adoptionSpark(),
        ];
    }

    /** Adoptants cumulés / connus par mois sur 6 mois — mini-courbe de la carte adoption. */
    private function adoptionSpark(): array
    {
        $connus = max(1, (int) DB::table('dim_parents')->where('is_test', false)->count());
        $byMonth = DB::table('fact_parent_journeys')->where('is_test', false)->whereNotNull('first_payment_at')
            ->where('first_payment_at', '>=', Carbon::now()->startOfMonth()->subMonths(5))
            ->selectRaw("DATE_FORMAT(first_payment_at, '%Y-%m') as m, COUNT(DISTINCT parent_id) as n")
            ->groupBy('m')->pluck('n', 'm');

        $cumulativeBefore = (int) DB::table('fact_parent_journeys')->where('is_test', false)->whereNotNull('first_payment_at')
            ->where('first_payment_at', '<', Carbon::now()->startOfMonth()->subMonths(5))->distinct()->count('parent_id');

        $spark = [];
        $cursor = Carbon::now()->startOfMonth()->subMonths(5);
        $cumulative = $cumulativeBefore;
        for ($i = 0; $i < 6; $i++) {
            $cumulative += (int) ($byMonth[$cursor->format('Y-m')] ?? 0);
            $spark[] = round($cumulative / $connus * 100, 1);
            $cursor->addMonth();
        }

        return $spark;
    }
}
