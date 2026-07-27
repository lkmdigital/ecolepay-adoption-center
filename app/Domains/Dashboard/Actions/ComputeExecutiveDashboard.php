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

    /** @var array<string, string> périodes proposées dans le panneau de filtres. */
    public const PERIODS = [
        'today' => "Aujourd'hui",
        'yesterday' => 'Hier',
        '7d' => '7 derniers jours',
        '30d' => '30 derniers jours',
        'this_month' => 'Ce mois',
        'last_month' => 'Mois précédent',
        'this_year' => 'Cette année',
        'school_year' => 'Année scolaire',
    ];

    public function __invoke(string $period = 'school_year', string $comparison = 'previous'): array
    {
        [$start, $end] = $this->resolveRange($period);
        [$prevStart, $prevEnd] = $this->comparisonRange($start, $end, $comparison);

        return [
            'period' => $period,
            'kpis' => $this->kpis($start, $end, $prevStart, $prevEnd),
            'situation' => $this->situation(),
            'funnel' => $this->funnel(),
            'health' => $this->health(),
            'repartition' => $this->repartition(),
            'topSchools' => $this->topSchools(),
            'actionSchools' => $this->actionSchools(),
            'opportunities' => $this->opportunities(),
            'alerts' => $this->alerts(),
            'recommendations' => $this->recommendations(),
        ];
    }

    /* ------------------------------------------------------------- Périodes */

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveRange(string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            '7d' => [$now->copy()->subDays(7), $now->copy()],
            '30d' => [$now->copy()->subDays(30), $now->copy()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()],
            default => [$this->schoolYearStart($now), $now->copy()],
        };
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function comparisonRange(Carbon $start, Carbon $end, string $comparison): array
    {
        if ($comparison === 'year') {
            return [$start->copy()->subYear(), $end->copy()->subYear()];
        }

        // Période précédente : même durée, juste avant.
        $length = $start->diffInSeconds($end);

        return [$start->copy()->subSeconds($length), $start->copy()];
    }

    private function schoolYearStart(Carbon $now): Carbon
    {
        $startMonth = (int) config('eac.school_year_start_month', 9);
        $year = $now->month >= $startMonth ? $now->year : $now->year - 1;

        return Carbon::create($year, $startMonth, 1)->startOfDay();
    }

    /* ---------------------------------------------------------------- KPIs */

    /**
     * KPI hiérarchisés : d'abord les résultats stratégiques que le DG regarde,
     * puis la volumétrie de contexte.
     */
    private function kpis(Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd): array
    {
        $connus = (int) DB::table('dim_parents')->where('is_test', false)->count();
        $inscrits = (int) DB::table('dim_parents')->where('is_test', false)->whereNotNull('account_created_at')->count();
        $actifs = (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('has_ever_paid', true)->distinct()->count('parent_id');
        $ecoles = (int) DB::table('dim_schools')->where('is_test', false)->whereNotNull('is_current')->count();
        $eleves = (int) DB::table('dim_students')->where('is_test', false)->count();
        $revenue = (int) $this->paymentsQuery()->sum('amount');
        $potential = $this->potentialSubscriptionRevenue();
        $adoption = $connus > 0 ? round($actifs / $connus * 100, 1) : 0.0;

        $spark = $this->sparklines();

        return [
            'strategic' => [
                ['key' => 'adoption', 'label' => 'Adoption globale', 'value' => $adoption, 'format' => 'pct', 'delta' => $this->adoptionDelta($connus, $prevEnd), 'spark' => $spark['adoption'], 'sub' => 'le chiffre suivi par la Direction'],
                ['key' => 'actifs', 'label' => 'Parents adoptants', 'value' => $actifs, 'format' => 'int', 'delta' => $this->deltaAdopters($start, $end, $prevStart, $prevEnd), 'spark' => $spark['actifs'], 'sub' => '1ᵉʳ paiement effectué (adoption)'],
                ['key' => 'ca_pay', 'label' => 'Revenu paiements', 'value' => $revenue, 'format' => 'money', 'delta' => $this->deltaRevenue($start, $end, $prevStart, $prevEnd), 'spark' => $spark['revenue'], 'sub' => "volume payé via l'app"],
                ['key' => 'potentiel', 'label' => 'Potentiel restant', 'value' => $potential, 'format' => 'money', 'delta' => null, 'spark' => null, 'sub' => 'abonnements non débloqués · estimé'],
            ],
            'secondary' => [
                ['key' => 'ecoles', 'label' => 'Écoles', 'value' => $ecoles, 'format' => 'int', 'delta' => null, 'spark' => null, 'sub' => 'établissements suivis'],
                ['key' => 'eleves', 'label' => 'Élèves', 'value' => $eleves, 'format' => 'int', 'delta' => null, 'spark' => null, 'sub' => 'sur les listes des écoles'],
                ['key' => 'parents', 'label' => 'Parents connus', 'value' => $connus, 'format' => 'int', 'delta' => null, 'spark' => null, 'sub' => 'contacts identifiés'],
                ['key' => 'inscrits', 'label' => 'Parents inscrits', 'value' => $inscrits, 'format' => 'int', 'delta' => $this->deltaByDate('dim_parents', 'account_created_at', $start, $end, $prevStart, $prevEnd), 'spark' => $spark['inscrits'], 'sub' => 'ont créé un compte'],
            ],
        ];
    }

    /**
     * Carte « Situation actuelle » : le résumé unique que le DG lit en premier.
     */
    private function situation(): array
    {
        $connus = (int) DB::table('dim_parents')->where('is_test', false)->count();
        $actifs = (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('has_ever_paid', true)->distinct()->count('parent_id');
        $rate = $connus > 0 ? round($actifs / $connus * 100, 1) : 0.0;

        // Élan sur 30 jours, exprimé en points d'adoption.
        $adopters30 = (int) DB::table('fact_parent_journeys')->where('is_test', false)
            ->where('first_payment_at', '>=', Carbon::now()->subDays(30))->distinct()->count('parent_id');
        $deltaPts = $connus > 0 ? round($adopters30 / $connus * 100, 1) : 0.0;

        return [
            'adoptionRate' => $rate,
            'deltaPts' => $deltaPts,
            'nonAdopters' => max($connus - $actifs, 0),
            'potentialRevenue' => $this->potentialSubscriptionRevenue(),
            'urgentSchools' => $this->urgentSchoolsCount(),
        ];
    }

    /** Variation d'adoption en points : taux actuel vs taux à la fin de la période de comparaison. */
    private function adoptionDelta(int $connus, Carbon $prevEnd): ?array
    {
        if ($connus === 0) {
            return null;
        }
        $now = (int) DB::table('fact_parent_journeys')->where('is_test', false)->whereNotNull('first_payment_at')->distinct()->count('parent_id');
        $then = (int) DB::table('fact_parent_journeys')->where('is_test', false)
            ->whereNotNull('first_payment_at')->where('first_payment_at', '<=', $prevEnd)->distinct()->count('parent_id');

        $pts = round(($now - $then) / $connus * 100, 1);
        if ($pts == 0.0) {
            return null;
        }

        return ['dir' => $pts >= 0 ? 'up' : 'down', 'pts' => abs($pts)];
    }

    private function deltaByDate(string $table, string $col, Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd): ?array
    {
        $current = (int) DB::table($table)->where('is_test', false)->whereBetween($col, [$start, $end])->count();
        $previous = (int) DB::table($table)->where('is_test', false)->whereBetween($col, [$prevStart, $prevEnd])->count();

        return $this->delta($current, $previous);
    }

    private function deltaAdopters(Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd): ?array
    {
        $current = (int) DB::table('fact_parent_journeys')->where('is_test', false)->whereBetween('first_payment_at', [$start, $end])->distinct()->count('parent_id');
        $previous = (int) DB::table('fact_parent_journeys')->where('is_test', false)->whereBetween('first_payment_at', [$prevStart, $prevEnd])->distinct()->count('parent_id');

        return $this->delta($current, $previous);
    }

    private function deltaRevenue(Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd): ?array
    {
        $current = (int) $this->paymentsQuery()->whereBetween('paid_at', [$start, $end])->sum('amount');
        $previous = (int) $this->paymentsQuery()->whereBetween('paid_at', [$prevStart, $prevEnd])->sum('amount');

        return $this->delta($current, $previous);
    }

    /**
     * Variation en %. On renvoie null quand la période de comparaison est vide :
     * l'historique source ne couvre qu'~15 mois, un faux « nouveau » induirait en erreur.
     */
    private function delta(int $current, int $previous): ?array
    {
        if ($previous === 0) {
            return null;
        }
        $pct = round(($current - $previous) / $previous * 100, 1);

        return ['dir' => $pct >= 0 ? 'up' : 'down', 'pct' => abs($pct)];
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
     * (paiements réels + abonnements estimés) sur 12 mois, plus les événements
     * métier réels (rentrée scolaire) à annoter sous la courbe.
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

        // Événements réels : la rentrée scolaire (mois de septembre présents dans la fenêtre).
        $startMonth = (int) config('eac.school_year_start_month', 9);
        $events = [];
        foreach ($keys as $i => $k) {
            if ((int) substr($k['key'], 5, 2) === $startMonth) {
                $events[] = ['index' => $i, 'label' => 'Rentrée scolaire'];
            }
        }

        return [
            'labels' => array_column($keys, 'label'),
            'adoptionRate' => $adoptionRate,
            'newAdopters' => $newAdopters,
            'revenue' => array_map(fn ($v) => round($v / 1_000_000, 2), $revenue),
            'subRevenue' => array_map(fn ($v) => round($v / 1_000_000, 2), $subRevenue),
            'events' => $events,
        ];
    }

    /* --------------------------------------------------------------- Funnel */

    /**
     * Entonnoir d'adoption officiel : connus → inscrits → adoptants (⭐ 1ᵉʳ
     * paiement) → engagés (récurrents), avec le taux de conversion entre étapes.
     *
     * @return list<array{label: string, value: int, conv: ?float, star: bool}>
     */
    private function funnel(): array
    {
        $connus = (int) DB::table('dim_parents')->where('is_test', false)->count();
        $inscrits = (int) DB::table('dim_parents')->where('is_test', false)->whereNotNull('account_created_at')->count();
        $adoptants = (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('has_ever_paid', true)->distinct()->count('parent_id');
        $engages = (int) DB::query()->fromSub(
            DB::table('fact_parent_journeys')->where('is_test', false)->where('has_ever_paid', true)
                ->groupBy('parent_id')->havingRaw('SUM(successful_payment_count) >= 2')->selectRaw('parent_id'),
            'sub'
        )->count();

        $stages = [
            ['Parents connus', $connus, false],
            ['Parents inscrits', $inscrits, false],
            ['Parents adoptants', $adoptants, true],
            ['Parents engagés', $engages, false],
        ];
        $out = [];
        $prev = null;
        foreach ($stages as [$label, $value, $star]) {
            $out[] = ['label' => $label, 'value' => $value, 'conv' => $prev !== null && $prev > 0 ? round($value / $prev * 100, 1) : null, 'star' => $star];
            $prev = $value;
        }

        return $out;
    }

    /* ---------------------------------------------------------- Répartition */

    /**
     * Donut des parents, cohérent avec les KPI de tête (la somme = total parents).
     * On ne suit pas de « parents inconnus » : le premier segment est donc les
     * connus non inscrits.
     */
    private function repartition(): array
    {
        $connus = (int) DB::table('dim_parents')->where('is_test', false)->count();
        $inscrits = (int) DB::table('dim_parents')->where('is_test', false)->whereNotNull('account_created_at')->count();
        $actifs = (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('has_ever_paid', true)->distinct()->count('parent_id');
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
            ['label' => 'Adoptants', 'value' => max($actifs - $inactifs, 0), 'color' => '#22C55E'],
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

    private function topSchools(): array
    {
        return $this->schoolMetrics()->orderByDesc('adopters')->limit(10)->get()
            ->map(fn ($r) => $this->decorateSchool($r))->all();
    }

    private function actionSchools(): array
    {
        return $this->schoolMetrics()->having('known', '>=', 20)
            ->havingRaw('COUNT(DISTINCT CASE WHEN j.has_ever_paid = 1 THEN j.parent_id END) / COUNT(DISTINCT j.parent_id) < 0.25')
            ->get()
            ->map(fn ($r) => $this->decorateSchool($r))
            ->map(function ($s) {
                $s['priority'] = $s['known'] >= 100 && $s['rate'] < 15 ? 'critique'
                    : ($s['known'] >= 50 || $s['rate'] < 15 ? 'elevee' : 'moyenne');

                return $s;
            })
            ->sortByDesc('potential')->take(6)->values()->all();
    }

    private function opportunities(): array
    {
        return $this->schoolMetrics()->having('known', '>=', 20)->get()
            ->map(fn ($r) => $this->decorateSchool($r))
            ->filter(fn ($s) => $s['potential'] > 0)
            ->sortByDesc('potential')->take(5)->values()->all();
    }

    /** Nombre d'écoles à intervention prioritaire (adoption < 25 %, base ≥ 20). */
    private function urgentSchoolsCount(): int
    {
        return $this->schoolMetrics()->having('known', '>=', 20)
            ->havingRaw('COUNT(DISTINCT CASE WHEN j.has_ever_paid = 1 THEN j.parent_id END) / COUNT(DISTINCT j.parent_id) < 0.25')
            ->get()->count();
    }

    /* -------------------------------------------------------------- Alertes */

    private function alerts(): array
    {
        $now = Carbon::now();
        $alerts = [];

        $urgent = $this->urgentSchoolsCount();
        if ($urgent > 0) {
            $alerts[] = ['level' => 'danger', 'priority' => 'Critique', 'title' => "{$urgent} écoles sous 25 % d'adoption", 'detail' => 'Base significative (≥ 20 parents connus). Intervention prioritaire recommandée.'];
        }

        $registeredNotPaid = $this->registeredNotPaidCount();
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

        $relance = (int) DB::table('dim_parents')->where('is_test', false)->whereNull('account_created_at')->count();
        if ($relance > 0) {
            $recos[] = [
                'priority' => 'elevee',
                'title' => 'Relancer les parents connus mais non inscrits',
                'why' => "{$this->num($relance)} numéros figurent sur les listes d'écoles sans compte EcolePay : le plus grand réservoir d'inscription.",
            ];
        }

        $registeredNotPaid = $this->registeredNotPaidCount();
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

    private function potentialSubscriptionRevenue(): int
    {
        return (int) DB::table('fact_parent_journeys as j')
            ->join('dim_schools as s', 's.id', '=', 'j.school_id')
            ->where('j.is_test', false)->where('j.has_ever_paid', false)
            ->where('s.subscription_model', 'parent_paid')
            ->sum('s.subscription_amount');
    }

    private function registeredNotPaidCount(): int
    {
        return (int) DB::table('dim_parents')->where('is_test', false)
            ->whereNotNull('account_created_at')
            ->whereNotExists(fn ($q) => $q->from('fact_parent_journeys as j')
                ->whereColumn('j.parent_id', 'dim_parents.id')->where('j.has_ever_paid', true)->where('j.is_test', false))
            ->count();
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
