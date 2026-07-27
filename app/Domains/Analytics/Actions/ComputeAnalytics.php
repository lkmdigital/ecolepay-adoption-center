<?php

namespace App\Domains\Analytics\Actions;

use App\Domains\Campaigns\Actions\ListCampaigns;
use App\Domains\Schools\Actions\ListSchoolsForPilotage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Centre de Business Intelligence : agrège tout l'entrepôt sous l'angle demandé
 * (période, mode d'abonnement, école) pour l'exploration analytique.
 *
 * S'appuie sur les métriques par école déjà calculées (ListSchoolsForPilotage) et
 * par campagne (ListCampaigns), puis dérive tendances, comparaisons, revenus,
 * anomalies et recommandations. Vocabulaire unique : connu → inscrit → adoptant
 * (⭐ 1ᵉʳ paiement) → engagé.
 */
final class ComputeAnalytics
{
    private const MOIS_FR = [1 => 'jan.', 2 => 'fév.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin', 7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.'];

    public function __construct(
        private readonly ListSchoolsForPilotage $schools,
        private readonly ListCampaigns $campaigns,
    ) {}

    public function __invoke(string $period = 'school_year', string $model = '', ?int $schoolId = null): array
    {
        $all = collect(($this->schools)()['rows']);
        $scoped = $all
            ->when($model !== '', fn ($c) => $c->where('subscriptionModel', $model))
            ->when($schoolId, fn ($c) => $c->where('id', $schoolId))
            ->values();
        $schoolIds = $scoped->pluck('id')->all();
        $unfiltered = $model === '' && $schoolId === null;
        $base = $this->baseCounts($scoped, $unfiltered);

        return [
            'kpis' => $this->kpis($base, $schoolIds),
            'funnel' => $this->funnel($base),
            'trends' => $this->trends($schoolIds),
            'comparison' => $this->comparison($scoped),
            'campaigns' => $this->campaignAnalysis(),
            'revenue' => $this->revenue($all, $scoped, $schoolIds),
            'anomalies' => $this->anomalies($scoped),
            'recommendations' => $this->recommendations($all),
        ];
    }

    /**
     * Effectifs de base : canoniques (dédupliqués) quand aucun filtre n'est actif,
     * sinon sommés sur les écoles retenues — cohérent avec le reste de l'app.
     *
     * @param  Collection<int, array<string,mixed>>  $scoped
     * @return array{connus:int, inscrits:int, adoptants:int, engages:int, revenue:int, potential:int}
     */
    private function baseCounts(Collection $scoped, bool $unfiltered): array
    {
        if ($unfiltered) {
            return [
                'connus' => (int) DB::table('dim_parents')->where('is_test', false)->count(),
                'inscrits' => (int) DB::table('dim_parents')->where('is_test', false)->whereNotNull('account_created_at')->count(),
                'adoptants' => (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('has_ever_paid', true)->distinct()->count('parent_id'),
                'engages' => (int) DB::query()->fromSub(DB::table('fact_parent_journeys')->where('is_test', false)->where('has_ever_paid', true)->groupBy('parent_id')->havingRaw('SUM(successful_payment_count) >= 2')->selectRaw('parent_id'), 'sub')->count(),
                'revenue' => (int) $scoped->sum('revenue'),
                'potential' => (int) $scoped->sum('potential'),
            ];
        }

        return [
            'connus' => (int) $scoped->sum('known'),
            'inscrits' => (int) $scoped->sum('inscrits'),
            'adoptants' => (int) $scoped->sum('actifs'),
            'engages' => (int) $scoped->sum('engages'),
            'revenue' => (int) $scoped->sum('revenue'),
            'potential' => (int) $scoped->sum('potential'),
        ];
    }

    /** @param array{connus:int, inscrits:int, adoptants:int, engages:int, revenue:int, potential:int} $base */
    private function kpis(array $base, array $schoolIds): array
    {
        ['connus' => $connus, 'inscrits' => $inscrits, 'adoptants' => $adoptants, 'engages' => $engages, 'revenue' => $revenue, 'potential' => $potential] = $base;

        // Croissance : élan récent stable (30 j vs 30 j précédents), indépendant de la période.
        $now = Carbon::now();
        $cur = $this->newAdoptersBetween($schoolIds, $now->copy()->subDays(30), $now);
        $prev = $this->newAdoptersBetween($schoolIds, $now->copy()->subDays(60), $now->copy()->subDays(30));
        $growth = $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : null;

        // Prévision : moyenne des mois complets récents (le mois courant est partiel).
        $series = array_values($this->adoptersByMonth($schoolIds));
        $forecast = $this->forecastNext($series);

        $spark = fn ($s) => array_slice($s, -6);
        $revSeries = array_values($this->revenueByMonth($schoolIds));

        return [
            ['key' => 'reg', 'label' => "Taux d'inscription", 'value' => $connus > 0 ? round($inscrits / $connus * 100, 1) : 0, 'format' => 'pct', 'spark' => null, 'tip' => 'Parents inscrits / parents connus. Mesure l\'efficacité de la communication.'],
            ['key' => 'act', 'label' => "Taux d'activation", 'value' => $inscrits > 0 ? round($adoptants / $inscrits * 100, 1) : 0, 'format' => 'pct', 'spark' => null, 'tip' => 'Parents adoptants / parents inscrits. Capacité à convertir un inscrit en payeur.'],
            ['key' => 'adopt', 'label' => "Taux d'adoption ⭐", 'value' => $connus > 0 ? round($adoptants / $connus * 100, 1) : 0, 'format' => 'pct', 'spark' => $spark($this->cumulativeAdoptionRate($schoolIds, $connus)), 'tip' => 'Parents adoptants / parents connus. Le KPI suivi par la Direction.'],
            ['key' => 'eng', 'label' => 'Parents engagés', 'value' => $engages, 'format' => 'int', 'spark' => null, 'tip' => 'Parents avec paiements récurrents (≥ 2).'],
            ['key' => 'arpa', 'label' => 'Revenu moyen / adoptant', 'value' => $adoptants > 0 ? (int) round($revenue / $adoptants) : 0, 'format' => 'money', 'spark' => null, 'tip' => 'Chiffre d\'affaires rapporté au nombre de parents adoptants.'],
            ['key' => 'pot', 'label' => 'Potentiel restant', 'value' => $potential, 'format' => 'money', 'spark' => null, 'tip' => 'Abonnements dormants : parents non adoptants × montant d\'abonnement (écoles à abonnement parent).'],
            ['key' => 'growth', 'label' => 'Croissance (30 j)', 'value' => $growth, 'format' => 'delta', 'spark' => $spark($series), 'tip' => 'Nouveaux adoptants sur 30 jours vs les 30 jours précédents.'],
            ['key' => 'forecast', 'label' => 'Prévision (mois +1)', 'value' => $forecast, 'format' => 'int', 'spark' => $spark($series), 'tip' => 'Nouveaux adoptants projetés le mois prochain (tendance récente). Estimation.'],
        ];
    }

    /** @param array{connus:int, inscrits:int, adoptants:int, engages:int} $base */
    private function funnel(array $base): array
    {
        ['connus' => $connus, 'inscrits' => $inscrits, 'adoptants' => $adoptants, 'engages' => $engages] = $base;

        $stages = [['Parents connus', $connus, false], ['Parents inscrits', $inscrits, false], ['Parents adoptants', $adoptants, true], ['Parents engagés', $engages, false]];
        $out = [];
        $prev = null;
        $frictions = [];
        foreach ($stages as $i => [$label, $value, $star]) {
            $conv = $prev !== null && $prev > 0 ? round($value / $prev * 100, 1) : null;
            $loss = $prev !== null && $prev > 0 ? round(($prev - $value) / $prev * 100, 1) : null;
            $out[] = ['label' => $label, 'value' => $value, 'conv' => $conv, 'loss' => $loss, 'star' => $star];
            if ($loss !== null && $loss >= 30) {
                $frictions[] = ['from' => $stages[$i - 1][0], 'to' => $label, 'loss' => $loss];
            }
            $prev = $value;
        }

        return ['stages' => $out, 'frictions' => $frictions];
    }

    private function trends(array $schoolIds): array
    {
        $keys = $this->monthKeys(12);
        $connus = max(1, (int) DB::table('fact_parent_journeys')->where('is_test', false)->whereIn('school_id', $schoolIds)->distinct()->count('parent_id'));
        $newAdopters = $this->fill($keys, $this->adoptersByMonth($schoolIds));
        $newEngaged = $this->fill($keys, $this->engagedByMonth($schoolIds));
        $revenue = $this->fill($keys, $this->revenueByMonth($schoolIds));

        $cum = 0;
        $adoptionRate = [];
        foreach ($newAdopters as $n) {
            $cum += $n;
            $adoptionRate[] = round($cum / $connus * 100, 1);
        }

        return [
            'labels' => array_column($keys, 'label'),
            'adoptionRate' => $adoptionRate,
            'newAdopters' => $newAdopters,
            'newEngaged' => $newEngaged,
            'revenue' => array_map(fn ($v) => round($v / 1_000_000, 2), $revenue),
        ];
    }

    /** @param Collection<int, array<string,mixed>> $scoped */
    private function comparison(Collection $scoped): array
    {
        return $scoped->sortByDesc('actifs')->take(8)->map(fn ($s) => [
            'id' => $s['id'],
            'name' => $s['name'],
            'registration' => $s['known'] > 0 ? round($s['inscrits'] / $s['known'] * 100, 1) : 0,
            'activation' => $s['inscrits'] > 0 ? round($s['actifs'] / $s['inscrits'] * 100, 1) : 0,
            'adoption' => $s['rate'],
            'engages' => $s['engages'],
            'revenue' => $s['revenue'],
            'potential' => $s['potential'],
            'progression' => $s['recent'],
            'health' => $s['healthScore'],
        ])->values()->all();
    }

    private function campaignAnalysis(): array
    {
        $rows = ($this->campaigns)()['rows'];
        $ranking = $rows->sortByDesc('conversion')->take(8)->map(fn ($r) => [
            'id' => $r['id'], 'name' => $r['name'], 'channel' => $r['channel'],
            'contacts' => $r['contacts'], 'newAccounts' => $r['newAccounts'],
            'newPayments' => $r['newPayments'], 'conversion' => $r['conversion'], 'revenue' => $r['revenue'],
        ])->values()->all();

        // Conversion moyenne par canal.
        $byChannel = $rows->groupBy(fn ($r) => $r['channel']->value)->map(function ($g, $ch) {
            $contacts = $g->sum('contacts');

            return ['channel' => $g->first()['channel']->label(), 'conversion' => $contacts > 0 ? round($g->sum('newPayments') / $contacts * 100, 1) : 0, 'campaigns' => $g->count()];
        })->sortByDesc('conversion')->values()->all();

        // Temps moyen campagne → premier paiement.
        $avgDays = DB::table('fact_campaign_contacts as cc')
            ->join('dim_campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->join('fact_parent_journeys as j', 'j.parent_id', '=', 'cc.parent_id')
            ->where('cc.is_valid', true)->where('j.is_test', false)
            ->whereNotNull('j.first_payment_at')->whereNotNull('c.campaign_date')
            ->whereColumn('j.first_payment_at', '>=', 'c.campaign_date')
            ->avg(DB::raw('DATEDIFF(j.first_payment_at, c.campaign_date)'));

        return ['ranking' => $ranking, 'byChannel' => $byChannel, 'avgDaysToPayment' => $avgDays !== null ? (int) round($avgDays) : null];
    }

    /** @param Collection<int, array<string,mixed>> $all */
    private function revenue(Collection $all, Collection $scoped, array $schoolIds): array
    {
        $bySubscription = $all->groupBy('subscriptionModel')->map(fn ($g, $m) => [
            'label' => $m === 'parent_paid' ? 'Abonnement parent' : 'Abonnement intégré',
            'value' => (int) $g->sum('revenue'),
        ])->values()->all();

        $bySchool = $scoped->sortByDesc('revenue')->take(8)->map(fn ($s) => ['name' => $s['name'], 'value' => (int) $s['revenue']])->values()->all();

        $keys = $this->monthKeys(12);
        $monthly = $this->fill($keys, $this->revenueByMonth($schoolIds));
        $cum = 0;
        $cumulative = array_map(function ($v) use (&$cum) {
            $cum += $v;

            return round($cum / 1_000_000, 2);
        }, $monthly);
        $forecastM = $this->forecastNext(array_map(fn ($v) => (int) round($v / 1_000_000), $monthly));

        return [
            'bySubscription' => $bySubscription,
            'bySchool' => $bySchool,
            'labels' => array_column($keys, 'label'),
            'cumulative' => $cumulative,
            'forecast' => $forecastM,
        ];
    }

    /** @param Collection<int, array<string,mixed>> $scoped */
    private function anomalies(Collection $scoped): array
    {
        $out = [];

        $urgent = $scoped->filter(fn ($s) => $s['known'] >= 50 && $s['rate'] < 15)->sortByDesc('known')->take(3);
        foreach ($urgent as $s) {
            $out[] = ['level' => 'danger', 'title' => "Adoption critique — {$s['name']}", 'detail' => "Seulement {$this->pct($s['rate'])} d'adoption sur {$this->num($s['known'])} parents connus.", 'impact' => "{$this->money((int) $s['potential'])} de potentiel bloqué", 'school' => $s['id']];
        }

        // Fortes inscriptions sans conversion (bon haut d'entonnoir, faible activation).
        $leaky = $scoped->filter(function ($s) {
            $reg = $s['known'] > 0 ? $s['inscrits'] / $s['known'] * 100 : 0;
            $act = $s['inscrits'] > 0 ? $s['actifs'] / $s['inscrits'] * 100 : 0;

            return $s['inscrits'] >= 40 && $reg >= 55 && $act < 30;
        })->sortByDesc('inscrits')->take(2);
        foreach ($leaky as $s) {
            $out[] = ['level' => 'warning', 'title' => "Inscriptions sans conversion — {$s['name']}", 'detail' => "{$this->num($s['inscrits'])} inscrits mais seulement {$this->num($s['actifs'])} adoptants : blocage au premier paiement.", 'impact' => 'Activation à renforcer', 'school' => $s['id']];
        }

        return $out;
    }

    /** @param Collection<int, array<string,mixed>> $all */
    private function recommendations(Collection $all): array
    {
        $recos = [];

        // Comparaison des modes d'abonnement.
        $byModel = $all->groupBy('subscriptionModel')->map(function ($g) {
            $known = $g->sum('known');

            return $known > 0 ? round($g->sum('actifs') / $known * 100, 1) : 0;
        });
        $pp = $byModel['parent_paid'] ?? 0;
        $bundled = $byModel['bundled'] ?? 0;
        if ($pp > 0 && $bundled > 0) {
            $higher = $bundled >= $pp ? 'intégré à la scolarité' : 'payé par les parents';
            $recos[] = ['priority' => 'moyenne', 'title' => "Les écoles à abonnement {$higher} adoptent mieux", 'why' => "Taux d'adoption : intégré {$this->pct($bundled)} vs parent {$this->pct($pp)}.", 'impact' => 'Orienter le discours commercial selon le mode.'];
        }

        // Meilleur canal de campagne.
        $camp = ($this->campaigns)()['rows'];
        if ($camp->isNotEmpty()) {
            $best = $camp->groupBy(fn ($r) => $r['channel']->label())->map(function ($g) {
                $c = $g->sum('contacts');

                return $c > 0 ? round($g->sum('newPayments') / $c * 100, 1) : 0;
            })->sortDesc()->keys()->first();
            if ($best) {
                $recos[] = ['priority' => 'moyenne', 'title' => "Le canal « {$best} » convertit le mieux", 'why' => 'Meilleur taux de conversion contacts → adoptants parmi les opérations mesurées.', 'impact' => 'Prioriser ce canal pour les prochaines relances.'];
            }
        }

        // Écoles à intervention.
        $urgent = $all->filter(fn ($s) => $s['known'] >= 20 && $s['rate'] < 20)->count();
        if ($urgent > 0) {
            $recos[] = ['priority' => 'critique', 'title' => "{$urgent} établissements nécessitent une intervention commerciale", 'why' => 'Adoption sous 20 % sur une base significative.', 'impact' => 'Cibler ces écoles en priorité ce trimestre.'];
        }

        // Top potentiel.
        $topPot = $all->filter(fn ($s) => $s['potential'] > 0)->sortByDesc('potential')->first();
        if ($topPot) {
            $recos[] = ['priority' => 'elevee', 'title' => "{$topPot['name']} concentre le plus fort potentiel", 'why' => "{$this->money((int) $topPot['potential'])} d'abonnements dormants.", 'impact' => 'Campagne ciblée à fort retour attendu.'];
        }

        return $recos;
    }

    /* --------------------------------------------------------------- Outils */

    private function newAdoptersBetween(array $schoolIds, Carbon $a, Carbon $b): int
    {
        return (int) DB::table('fact_parent_journeys')->where('is_test', false)->whereIn('school_id', $schoolIds)
            ->whereBetween('first_payment_at', [$a, $b])->distinct()->count('parent_id');
    }

    private function adoptersByMonth(array $schoolIds): array
    {
        return $this->monthlyDistinct($schoolIds, 'first_payment_at');
    }

    private function engagedByMonth(array $schoolIds): array
    {
        // Approximation : 2ᵉ paiement daté par mois via fact_payments (paiement récurrent).
        return DB::table('fact_payments')->where('is_test', false)->where('is_manual', false)->where('status', 'success')
            ->whereIn('school_id', $schoolIds)->where('is_first_payment', false)
            ->where('paid_at', '>=', Carbon::now()->startOfMonth()->subMonths(11))
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as m, COUNT(DISTINCT parent_id) as n")->groupBy('m')->pluck('n', 'm')->map(fn ($v) => (int) $v)->all();
    }

    private function monthlyDistinct(array $schoolIds, string $col): array
    {
        return DB::table('fact_parent_journeys')->where('is_test', false)->whereIn('school_id', $schoolIds)
            ->whereNotNull($col)->where($col, '>=', Carbon::now()->startOfMonth()->subMonths(11))
            ->selectRaw("DATE_FORMAT($col, '%Y-%m') as m, COUNT(DISTINCT parent_id) as n")->groupBy('m')->pluck('n', 'm')->map(fn ($v) => (int) $v)->all();
    }

    private function revenueByMonth(array $schoolIds): array
    {
        return DB::table('fact_payments')->where('is_test', false)->where('is_manual', false)->where('status', 'success')
            ->whereIn('school_id', $schoolIds)->where('paid_at', '>=', Carbon::now()->startOfMonth()->subMonths(11))
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as m, SUM(amount) as v")->groupBy('m')->pluck('v', 'm')->map(fn ($v) => (float) $v)->all();
    }

    private function cumulativeAdoptionRate(array $schoolIds, int $connus): array
    {
        $connus = max(1, $connus);
        $keys = $this->monthKeys(6);
        $byMonth = $this->adoptersByMonth($schoolIds);
        $before = (int) DB::table('fact_parent_journeys')->where('is_test', false)->whereIn('school_id', $schoolIds)
            ->whereNotNull('first_payment_at')->where('first_payment_at', '<', Carbon::now()->startOfMonth()->subMonths(5))->distinct()->count('parent_id');
        $cum = $before;
        $out = [];
        foreach ($keys as $k) {
            $cum += (int) ($byMonth[$k['key']] ?? 0);
            $out[] = round($cum / $connus * 100, 1);
        }

        return $out;
    }

    /**
     * Prévision robuste du point suivant : moyenne des mois COMPLETS récents, en
     * écartant le mois courant (partiel, donc artificiellement bas). Estimation
     * volontairement prudente plutôt qu'une extrapolation sensible au bruit.
     */
    private function forecastNext(array $series): int
    {
        $n = count($series);
        if ($n < 2) {
            return $series[0] ?? 0;
        }
        // On retire le dernier mois (en cours) puis on moyenne les 3 derniers mois complets.
        $complete = array_slice($series, 0, $n - 1);
        $recent = array_slice($complete, -3);

        return (int) round(array_sum($recent) / max(1, count($recent)));
    }

    private function monthKeys(int $n): array
    {
        $keys = [];
        $cursor = Carbon::now()->startOfMonth()->subMonths($n - 1);
        for ($i = 0; $i < $n; $i++) {
            $keys[] = ['key' => $cursor->format('Y-m'), 'label' => self::MOIS_FR[$cursor->month]];
            $cursor->addMonth();
        }

        return $keys;
    }

    private function fill(array $keys, array $data): array
    {
        return array_map(fn ($k) => (float) ($data[$k['key']] ?? 0), $keys);
    }

    private function num(int $n): string
    {
        return number_format($n, 0, ',', ' ');
    }

    private function pct(float $n): string
    {
        return number_format($n, 1, ',', ' ').' %';
    }

    private function money(int $n): string
    {
        return $n >= 1_000_000 ? number_format($n / 1_000_000, 1, ',', ' ').' M F' : $this->num($n).' F';
    }
}
