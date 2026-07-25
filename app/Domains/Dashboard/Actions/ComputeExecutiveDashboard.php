<?php

namespace App\Domains\Dashboard\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assemble l'intégralité du Dashboard exécutif en une passe.
 *
 * Tout est calculé sur les vraies données de l'entrepôt. Les rares chiffres
 * dérivés d'une hypothèse (revenu d'abonnement, élan récent) sont nommés comme
 * tels — jamais présentés comme mesurés. Les sections « alertes » et
 * « recommandations » sont des règles métier, pas de l'IA : le vrai copilote
 * viendra avec le module Assistant IA.
 *
 * À l'échelle actuelle (~30 k parcours) la lecture directe est instantanée ;
 * on introduira des agrégats le jour où l'écran ralentira.
 */
final class ComputeExecutiveDashboard
{
    private const MOIS_FR = [
        1 => 'jan.', 2 => 'fév.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin',
        7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.',
    ];

    /** @var array<string, int> jours de la fenêtre par période. */
    private const WINDOWS = ['7d' => 7, '30d' => 30, 'year' => 365];

    public function __invoke(string $period = 'year'): array
    {
        $window = self::WINDOWS[$period] ?? 365;
        // La variation période-sur-période n'a de sens que sur une fenêtre courte :
        // l'historique source ne couvre qu'~15 mois, donc « année vs année » compare
        // à une période quasi vide. Sur la vue Année scolaire, on montre les séries,
        // pas de faux delta.
        $withDelta = $period !== 'year';

        return [
            'kpis' => $this->kpis($window, $withDelta),
            'health' => $this->health(),
            'repartition' => $this->repartition(),
            'topSchools' => $this->topSchools(),
            'actionSchools' => $this->actionSchools(),
            'opportunities' => $this->opportunities(),
            'alerts' => $this->alerts(),
            'recommendations' => $this->recommendations(),
        ];
    }

    /* ---------------------------------------------------------------- KPIs */

    /**
     * Les 8 indicateurs de tête, chacun avec sa variation sur la fenêtre et,
     * quand une date le permet, une mini-série (sparkline) sur 6 mois.
     */
    private function kpis(int $window, bool $withDelta): array
    {
        $d = fn (callable $compute) => $withDelta ? $compute() : null;

        $connus = (int) DB::table('dim_parents')->where('is_test', false)->count();
        $inscrits = (int) DB::table('dim_parents')->where('is_test', false)->whereNotNull('account_created_at')->count();
        $actifs = (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('has_ever_paid', true)->distinct()->count('parent_id');
        $ecoles = (int) DB::table('dim_schools')->where('is_test', false)->whereNotNull('is_current')->count();
        $eleves = (int) DB::table('dim_students')->where('is_test', false)->count();
        $revenue = (int) $this->paymentsQuery()->sum('amount');
        $subRevenue = $this->realizedSubscriptionRevenue();
        $adoption = $connus > 0 ? round($actifs / $connus * 100, 1) : 0.0;

        $spark = $this->sparklines();

        return [
            ['key' => 'ecoles', 'label' => 'Écoles', 'value' => $ecoles, 'format' => 'int', 'delta' => null, 'spark' => null, 'sub' => 'établissements suivis'],
            ['key' => 'eleves', 'label' => 'Élèves', 'value' => $eleves, 'format' => 'int', 'delta' => null, 'spark' => null, 'sub' => 'sur les listes des écoles'],
            // Pas de variation/série : first_known_at reflète la date de synchro, pas une acquisition réelle.
            ['key' => 'parents', 'label' => 'Parents connus', 'value' => $connus, 'format' => 'int', 'delta' => null, 'spark' => null, 'sub' => 'contacts identifiés'],
            ['key' => 'inscrits', 'label' => 'Parents inscrits', 'value' => $inscrits, 'format' => 'int', 'delta' => $d(fn () => $this->deltaByDate('dim_parents', 'account_created_at', $window)), 'spark' => $spark['inscrits'], 'sub' => 'ont créé un compte'],
            ['key' => 'actifs', 'label' => 'Parents actifs', 'value' => $actifs, 'format' => 'int', 'delta' => $d(fn () => $this->deltaAdopters($window)), 'spark' => $spark['actifs'], 'sub' => 'ont payé via l\'app'],
            ['key' => 'adoption', 'label' => 'Taux d\'adoption', 'value' => $adoption, 'format' => 'pct', 'delta' => null, 'spark' => $spark['adoption'], 'sub' => 'le chiffre suivi par la Direction'],
            ['key' => 'ca_sub', 'label' => 'CA abonnements', 'value' => $subRevenue, 'format' => 'money', 'delta' => null, 'spark' => null, 'sub' => 'estimé · débloqué par les adoptants'],
            ['key' => 'ca_pay', 'label' => 'CA paiements', 'value' => $revenue, 'format' => 'money', 'delta' => $d(fn () => $this->deltaRevenue($window)), 'spark' => $spark['revenue'], 'sub' => 'volume payé via l\'app'],
        ];
    }

    /** Variation d'un compte daté : fenêtre courante vs précédente, en %. */
    private function deltaByDate(string $table, string $col, int $window): ?array
    {
        $now = Carbon::now();
        $current = (int) DB::table($table)->where('is_test', false)
            ->whereBetween($col, [$now->copy()->subDays($window), $now])->count();
        $previous = (int) DB::table($table)->where('is_test', false)
            ->whereBetween($col, [$now->copy()->subDays($window * 2), $now->copy()->subDays($window)])->count();

        return $this->delta($current, $previous);
    }

    private function deltaAdopters(int $window): ?array
    {
        $now = Carbon::now();
        $current = (int) DB::table('fact_parent_journeys')->where('is_test', false)
            ->whereBetween('first_payment_at', [$now->copy()->subDays($window), $now])->distinct()->count('parent_id');
        $previous = (int) DB::table('fact_parent_journeys')->where('is_test', false)
            ->whereBetween('first_payment_at', [$now->copy()->subDays($window * 2), $now->copy()->subDays($window)])->distinct()->count('parent_id');

        return $this->delta($current, $previous);
    }

    private function deltaRevenue(int $window): ?array
    {
        $now = Carbon::now();
        $current = (int) $this->paymentsQuery()->whereBetween('paid_at', [$now->copy()->subDays($window), $now])->sum('amount');
        $previous = (int) $this->paymentsQuery()->whereBetween('paid_at', [$now->copy()->subDays($window * 2), $now->copy()->subDays($window)])->sum('amount');

        return $this->delta($current, $previous);
    }

    private function delta(int $current, int $previous): ?array
    {
        if ($previous === 0) {
            return $current > 0 ? ['dir' => 'up', 'pct' => null, 'raw' => $current] : null;
        }
        $pct = round(($current - $previous) / $previous * 100, 1);

        return ['dir' => $pct >= 0 ? 'up' : 'down', 'pct' => abs($pct), 'raw' => $current];
    }

    /* -------------------------------------------------------------- Séries */

    /** Six derniers mois pour les sparklines des cartes. */
    private function sparklines(): array
    {
        $keys = $this->monthKeys(6);

        return [
            'inscrits' => $this->fillMonths($keys, $this->countByMonth('dim_parents', 'account_created_at')),
            'actifs' => $this->fillMonths($keys, $this->adoptersByMonth()),
            'adoption' => $this->fillMonths($keys, $this->adoptersByMonth()),
            'revenue' => $this->fillMonths($keys, $this->revenueByMonth()),
        ];
    }

    /**
     * Santé globale : évolution du taux d'adoption (cumulatif) et des revenus
     * (paiements réels + abonnements estimés) sur 12 mois.
     */
    private function health(): array
    {
        $keys = $this->monthKeys(12);
        $connus = max(1, (int) DB::table('dim_parents')->where('is_test', false)->count());

        $newAdopters = $this->fillMonths($keys, $this->adoptersByMonth());
        $revenue = $this->fillMonths($keys, $this->revenueByMonth());
        $subRevenue = $this->fillMonths($keys, $this->subRevenueByMonth());

        // Taux d'adoption cumulatif : adoptants accumulés / connus actuels.
        $cumulative = 0;
        $adoptionRate = [];
        foreach ($newAdopters as $n) {
            $cumulative += $n;
            $adoptionRate[] = round($cumulative / $connus * 100, 1);
        }

        return [
            'labels' => array_column($keys, 'label'),
            'adoptionRate' => $adoptionRate,
            'revenue' => array_map(fn ($v) => round($v / 1_000_000, 2), $revenue),
            'subRevenue' => array_map(fn ($v) => round($v / 1_000_000, 2), $subRevenue),
        ];
    }

    /* ---------------------------------------------------------- Répartition */

    /**
     * Donut des parents, au grain parent (dédupliqué), par état le plus avancé.
     * On ne suit pas de « parents inconnus » : le premier segment est donc les
     * connus non inscrits.
     */
    private function repartition(): array
    {
        // Partition cohérente avec les KPI de tête (somme = total des parents).
        $connus = (int) DB::table('dim_parents')->where('is_test', false)->count();
        $inscrits = (int) DB::table('dim_parents')->where('is_test', false)->whereNotNull('account_created_at')->count();
        $actifs = (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('has_ever_paid', true)->distinct()->count('parent_id');
        // Inactifs : payeurs dont le parcours le plus avancé est « à risque » ou « perdu ».
        $inactifs = (int) DB::query()->fromSub(
            DB::table('dim_parents')
                ->join('fact_parent_journeys as j', 'j.parent_id', '=', 'dim_parents.id')
                ->where('dim_parents.is_test', false)->where('j.is_test', false)
                ->groupBy('dim_parents.id')
                ->havingRaw('MAX(j.current_stage_id) IN (5, 6)')
                ->selectRaw('dim_parents.id'),
            'sub'
        )->count();

        return [
            ['label' => 'Connus non inscrits', 'value' => max($connus - $inscrits, 0), 'color' => '#94A3B8'],
            ['label' => 'Inscrits non payeurs', 'value' => max($inscrits - $actifs, 0), 'color' => '#38BDF8'],
            ['label' => 'Actifs', 'value' => max($actifs - $inactifs, 0), 'color' => '#22C55E'],
            ['label' => 'Inactifs (à risque · perdus)', 'value' => $inactifs, 'color' => '#F59E0B'],
        ];
    }

    /* -------------------------------------------------------------- Écoles */

    /** Requête de base : une ligne par école avec ses métriques d'adoption. */
    private function schoolMetrics()
    {
        return DB::table('dim_schools as s')
            ->leftJoin('fact_parent_journeys as j', function ($join) {
                $join->on('j.school_id', '=', 's.id')->where('j.is_test', false);
            })
            ->where('s.is_test', false)->whereNotNull('s.is_current')
            ->groupBy('s.id', 's.name', 's.subscription_model', 's.subscription_amount')
            ->selectRaw('s.id, s.name, s.subscription_model, s.subscription_amount')
            ->selectRaw('COUNT(DISTINCT j.parent_id) as known')
            ->selectRaw('COUNT(DISTINCT CASE WHEN j.has_ever_paid = 1 THEN j.parent_id END) as adopters')
            ->selectRaw('COUNT(DISTINCT CASE WHEN j.first_payment_at >= ? THEN j.parent_id END) as recent', [Carbon::now()->subDays(90)]);
    }

    private function decorateSchool(object $r): array
    {
        $known = (int) $r->known;
        $adopters = (int) $r->adopters;
        $rate = $known > 0 ? round($adopters / $known * 100, 1) : 0.0;
        $nonAdopters = max($known - $adopters, 0);
        // Potentiel = abonnements non débloqués, uniquement là où le parent paie.
        $potential = $r->subscription_model === 'parent_paid' ? $nonAdopters * (int) $r->subscription_amount : 0;

        return [
            'id' => $r->id,
            'name' => $r->name,
            'known' => $known,
            'adopters' => $adopters,
            'rate' => $rate,
            'recent' => (int) $r->recent,
            'nonAdopters' => $nonAdopters,
            'potential' => $potential,
        ];
    }

    /** Top 10 par nombre d'adoptants. */
    private function topSchools(): array
    {
        return $this->schoolMetrics()->orderByDesc('adopters')->limit(10)->get()
            ->map(fn ($r) => $this->decorateSchool($r))->all();
    }

    /**
     * Écoles nécessitant une action : adoption < 25 % sur une base significative,
     * les plus « lourdes » d'abord (potentiel le plus élevé).
     */
    private function actionSchools(): array
    {
        return $this->schoolMetrics()->having('known', '>=', 20)
            ->havingRaw('COUNT(DISTINCT CASE WHEN j.has_ever_paid = 1 THEN j.parent_id END) / COUNT(DISTINCT j.parent_id) < 0.25')
            ->get()
            ->map(fn ($r) => $this->decorateSchool($r))
            ->map(function ($s) {
                // Priorité : combinaison de la taille et du déficit d'adoption.
                $s['priority'] = $s['known'] >= 100 && $s['rate'] < 15 ? 'critique'
                    : ($s['known'] >= 50 || $s['rate'] < 15 ? 'elevee' : 'moyenne');

                return $s;
            })
            ->sortByDesc('potential')->take(6)->values()->all();
    }

    /** Cinq meilleures opportunités de revenu (potentiel d'abonnement le plus élevé). */
    private function opportunities(): array
    {
        return $this->schoolMetrics()->having('known', '>=', 20)->get()
            ->map(fn ($r) => $this->decorateSchool($r))
            ->filter(fn ($s) => $s['potential'] > 0)
            ->sortByDesc('potential')->take(5)->values()->all();
    }

    /* -------------------------------------------------------------- Alertes */

    /**
     * Alertes générées à partir des vraies données (pas de campagne : le module
     * n'existe pas encore).
     */
    private function alerts(): array
    {
        $now = Carbon::now();
        $alerts = [];

        $urgent = $this->schoolMetrics()->having('known', '>=', 20)
            ->havingRaw('COUNT(DISTINCT CASE WHEN j.has_ever_paid = 1 THEN j.parent_id END) / COUNT(DISTINCT j.parent_id) < 0.25')
            ->get()->count();
        if ($urgent > 0) {
            $alerts[] = ['level' => 'danger', 'priority' => 'Critique', 'title' => "{$urgent} écoles sous 25 % d'adoption", 'detail' => 'Base significative (≥ 20 parents connus). Intervention prioritaire recommandée.'];
        }

        $registeredNotPaid = (int) DB::table('dim_parents')->where('is_test', false)
            ->whereNotNull('account_created_at')
            ->whereNotExists(fn ($q) => $q->from('fact_parent_journeys as j')
                ->whereColumn('j.parent_id', 'dim_parents.id')->where('j.has_ever_paid', true)->where('j.is_test', false))
            ->count();
        if ($registeredNotPaid > 0) {
            $alerts[] = ['level' => 'warning', 'priority' => 'Élevée', 'title' => "{$registeredNotPaid} parents inscrits sans premier paiement", 'detail' => "Comptes créés mais jamais convertis : cible directe d'activation."];
        }

        $newAccounts = (int) DB::table('dim_parents')->where('is_test', false)
            ->where('account_created_at', '>=', $now->copy()->subDays(30))->count();
        if ($newAccounts > 0) {
            $alerts[] = ['level' => 'info', 'priority' => 'Info', 'title' => "{$newAccounts} nouveaux comptes créés (30 derniers jours)", 'detail' => 'Croissance de la base inscrite sur le dernier mois observé.'];
        }

        $momentum = $this->schoolMetrics()->orderByDesc('recent')->limit(1)->get()
            ->map(fn ($r) => $this->decorateSchool($r))->first();
        if ($momentum && $momentum['recent'] > 0) {
            $alerts[] = ['level' => 'success', 'priority' => 'Positif', 'title' => "Forte progression : {$momentum['name']}", 'detail' => "{$momentum['recent']} nouveaux adoptants sur les 90 derniers jours."];
        }

        return $alerts;
    }

    /* ------------------------------------------------------ Recommandations */

    /**
     * Recommandations dérivées de règles métier (pas d'IA générative : le module
     * Assistant IA viendra plus tard). Chaque carte porte une justification chiffrée.
     */
    private function recommendations(): array
    {
        $recos = [];

        $worst = $this->schoolMetrics()->having('known', '>=', 50)
            ->havingRaw('COUNT(DISTINCT CASE WHEN j.has_ever_paid = 1 THEN j.parent_id END) / COUNT(DISTINCT j.parent_id) < 0.25')
            ->get()->map(fn ($r) => $this->decorateSchool($r))->sortByDesc('nonAdopters')->first();
        if ($worst) {
            $recos[] = [
                'priority' => 'critique',
                'title' => "Lancer une campagne WhatsApp pour {$worst['name']}",
                'why' => "Adoption à {$this->pct($worst['rate'])} sur {$this->num($worst['known'])} parents connus ; {$this->num($worst['nonAdopters'])} restent à convertir.",
            ];
        }

        $relance = (int) DB::table('dim_parents')->where('is_test', false)
            ->whereNull('account_created_at')->count();
        if ($relance > 0) {
            $recos[] = [
                'priority' => 'elevee',
                'title' => 'Relancer les parents connus mais non inscrits',
                'why' => "{$this->num($relance)} numéros figurent sur les listes d'écoles sans compte EcolePay : le plus grand réservoir d'inscription.",
            ];
        }

        $registeredNotPaid = (int) DB::table('dim_parents')->where('is_test', false)
            ->whereNotNull('account_created_at')
            ->whereNotExists(fn ($q) => $q->from('fact_parent_journeys as j')
                ->whereColumn('j.parent_id', 'dim_parents.id')->where('j.has_ever_paid', true)->where('j.is_test', false))
            ->count();
        if ($registeredNotPaid > 0) {
            $recos[] = [
                'priority' => 'elevee',
                'title' => 'Convertir les inscrits inactifs en payeurs',
                'why' => "{$this->num($registeredNotPaid)} parents ont un compte mais n'ont jamais payé : un rappel ciblé peut déclencher le premier paiement.",
            ];
        }

        $opp = $this->opportunities()[0] ?? null;
        if ($opp) {
            $recos[] = [
                'priority' => 'moyenne',
                'title' => "Prioriser {$opp['name']} : fort potentiel de revenu",
                'why' => "Potentiel d'abonnement estimé à {$this->money($opp['potential'])} si les {$this->num($opp['nonAdopters'])} parents restants adoptent.",
            ];
        }

        return $recos;
    }

    /* --------------------------------------------------------------- Outils */

    private function paymentsQuery()
    {
        return DB::table('fact_payments')->where('is_test', false)->where('is_manual', false)->where('status', 'success');
    }

    /** Revenu d'abonnement réalisé : abonnement débloqué par chaque adoptant (parent_paid). */
    private function realizedSubscriptionRevenue(): int
    {
        return (int) DB::table('fact_parent_journeys as j')
            ->join('dim_schools as s', 's.id', '=', 'j.school_id')
            ->where('j.is_test', false)->where('j.has_ever_paid', true)
            ->where('s.subscription_model', 'parent_paid')
            ->sum('s.subscription_amount');
    }

    /** @return list<array{key: string, label: string}> */
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

    /** @param array<string, float|int> $data */
    private function fillMonths(array $keys, $data): array
    {
        return array_map(fn ($k) => (float) ($data[$k['key']] ?? 0), $keys);
    }

    private function countByMonth(string $table, string $col)
    {
        return DB::table($table)->where('is_test', false)->whereNotNull($col)
            ->where($col, '>=', Carbon::now()->startOfMonth()->subMonths(11))
            ->selectRaw("DATE_FORMAT($col, '%Y-%m') as m, COUNT(*) as n")
            ->groupBy('m')->pluck('n', 'm')->map(fn ($v) => (int) $v)->all();
    }

    private function adoptersByMonth()
    {
        return DB::table('fact_parent_journeys')->where('is_test', false)->whereNotNull('first_payment_at')
            ->where('first_payment_at', '>=', Carbon::now()->startOfMonth()->subMonths(11))
            ->selectRaw("DATE_FORMAT(first_payment_at, '%Y-%m') as m, COUNT(DISTINCT parent_id) as n")
            ->groupBy('m')->pluck('n', 'm')->map(fn ($v) => (int) $v)->all();
    }

    private function revenueByMonth()
    {
        return $this->paymentsQuery()->where('paid_at', '>=', Carbon::now()->startOfMonth()->subMonths(11))
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as m, SUM(amount) as v")
            ->groupBy('m')->pluck('v', 'm')->map(fn ($v) => (float) $v)->all();
    }

    private function subRevenueByMonth()
    {
        return DB::table('fact_parent_journeys as j')
            ->join('dim_schools as s', 's.id', '=', 'j.school_id')
            ->where('j.is_test', false)->where('j.has_ever_paid', true)
            ->where('s.subscription_model', 'parent_paid')
            ->whereNotNull('j.first_payment_at')
            ->where('j.first_payment_at', '>=', Carbon::now()->startOfMonth()->subMonths(11))
            ->selectRaw("DATE_FORMAT(j.first_payment_at, '%Y-%m') as m, SUM(s.subscription_amount) as v")
            ->groupBy('m')->pluck('v', 'm')->map(fn ($v) => (float) $v)->all();
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
