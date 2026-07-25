<?php

namespace App\Domains\Dashboard\Actions;

use App\Domains\Parents\Models\ParentJourney;
use App\Domains\Parents\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * Données des graphiques du Dashboard : tendance mensuelle et classement des écoles.
 *
 * Séparé des KPI : ces séries alimentent ECharts côté client.
 */
final class ComputeDashboardCharts
{
    private const MONTHS = 12;

    private const MOIS_FR = [
        1 => 'jan.', 2 => 'fév.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin',
        7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.',
    ];

    /**
     * @return array{trend: array{labels: list<string>, adopters: list<int>, volume: list<float>}, topSchools: list<array<string, mixed>>}
     */
    public function __invoke(): array
    {
        return [
            'trend' => $this->trend(),
            'topSchools' => $this->topSchools(),
        ];
    }

    /**
     * Nouveaux adoptants (premier paiement) et volume payé, par mois.
     *
     * @return array{labels: list<string>, adopters: list<int>, volume: list<float>}
     */
    private function trend(): array
    {
        $since = Carbon::now()->startOfMonth()->subMonths(self::MONTHS - 1);

        $adopters = ParentJourney::query()->production()
            ->whereNotNull('first_payment_at')
            ->where('first_payment_at', '>=', $since)
            ->selectRaw("DATE_FORMAT(first_payment_at, '%Y-%m') as m, COUNT(DISTINCT parent_id) as n")
            ->groupBy('m')->pluck('n', 'm');

        $volume = Payment::query()->production()->countsForAdoption()
            ->where('paid_at', '>=', $since)
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as m, SUM(amount) as v")
            ->groupBy('m')->pluck('v', 'm');

        $labels = [];
        $adoptersSeries = [];
        $volumeSeries = [];
        for ($cursor = $since->copy(); $cursor <= Carbon::now(); $cursor->addMonth()) {
            $key = $cursor->format('Y-m');
            $labels[] = self::MOIS_FR[$cursor->month];
            $adoptersSeries[] = (int) ($adopters[$key] ?? 0);
            $volumeSeries[] = round((float) ($volume[$key] ?? 0) / 1_000_000, 1);
        }

        return ['labels' => $labels, 'adopters' => $adoptersSeries, 'volume' => $volumeSeries];
    }

    /**
     * Top écoles par nombre d'adoptants, avec leur taux d'adoption.
     *
     * @return list<array{name: string, adopters: int, known: int, rate: float}>
     */
    private function topSchools(): array
    {
        return ParentJourney::query()->production()
            ->join('dim_schools as s', 's.id', '=', 'fact_parent_journeys.school_id')
            ->groupBy('s.id', 's.name')
            ->selectRaw('s.name as name')
            ->selectRaw('COUNT(DISTINCT CASE WHEN has_ever_paid = 1 THEN parent_id END) as adopters')
            ->selectRaw('COUNT(DISTINCT parent_id) as known')
            ->orderByDesc('adopters')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'adopters' => (int) $r->adopters,
                'known' => (int) $r->known,
                'rate' => $r->known > 0 ? round($r->adopters / $r->known * 100, 1) : 0.0,
            ])
            ->all();
    }
}
