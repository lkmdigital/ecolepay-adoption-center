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

    /**
     * Filtres actifs, normalisés (null = pas de filtre). Résolus dans __invoke.
     *
     * @var array{school: ?int, region: ?string, schoolType: ?string, campaign: ?int, operator: ?string, stage: ?int, schoolYear: ?string}
     */
    private array $f = [
        'school' => null, 'region' => null, 'schoolType' => null, 'campaign' => null,
        'operator' => null, 'stage' => null, 'schoolYear' => null,
    ];

    /** Écoles retenues (école/région/type). null = toutes. @var ?list<int> */
    private ?array $schoolIds = null;

    /** id dim_payment_methods correspondant à l'opérateur filtré. */
    private ?int $operatorId = null;

    /**
     * @param  array<string, mixed>  $filters  school, region, schoolType, campaign, operator, stage, schoolYear
     */
    public function __invoke(string $period = 'school_year', string $comparison = 'previous', ?string $from = null, ?string $to = null, array $filters = []): array
    {
        $this->applyFilters($filters);

        [$start, $end] = $this->resolveRange($period, $from, $to);
        [$prevStart, $prevEnd] = $this->comparisonRange($start, $end, $comparison);

        return [
            'period' => $period,
            'activeFilters' => array_filter($this->f, fn ($v) => $v !== null),
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

    /* -------------------------------------------------------------- Filtres */

    /** @param array<string, mixed> $filters */
    private function applyFilters(array $filters): void
    {
        $clean = static fn ($v) => ($v === null || $v === '' || $v === 'all') ? null : $v;

        $this->f = [
            'school' => ($v = $clean($filters['school'] ?? null)) !== null ? (int) $v : null,
            'region' => $clean($filters['region'] ?? null),
            'schoolType' => $clean($filters['schoolType'] ?? null),
            'campaign' => ($v = $clean($filters['campaign'] ?? null)) !== null ? (int) $v : null,
            'operator' => $clean($filters['operator'] ?? null),
            'stage' => ($v = $clean($filters['stage'] ?? null)) !== null ? (int) $v : null,
            'schoolYear' => $clean($filters['schoolYear'] ?? null),
        ];

        // Périmètre d'écoles (école > région/type).
        if ($this->f['school'] !== null) {
            $this->schoolIds = [$this->f['school']];
        } elseif ($this->f['region'] !== null || $this->f['schoolType'] !== null) {
            $q = DB::table('dim_schools')->where('is_test', false)->whereNotNull('is_current');
            if ($this->f['region'] !== null) {
                $q->where('region', $this->f['region']);
            }
            if ($this->f['schoolType'] !== null) {
                $q->where('school_type', $this->f['schoolType']);
            }
            $this->schoolIds = $q->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if ($this->f['operator'] !== null) {
            $this->operatorId = DB::table('dim_payment_methods')->where('code', $this->f['operator'])->value('id');
        }
    }

    /** Contraint une colonne parent_id au périmètre parents (statut + campagne). */
    private function applyParentScope($query, string $parentCol): void
    {
        if ($this->f['stage'] !== null) {
            $query->whereExists(fn ($sub) => $sub->from('fact_parent_journeys as js')
                ->whereColumn('js.parent_id', $parentCol)->where('js.is_test', false)
                ->where('js.current_stage_id', $this->f['stage']));
        }
        if ($this->f['campaign'] !== null) {
            $query->whereExists(fn ($sub) => $sub->from('fact_campaign_contacts as cc')
                ->whereColumn('cc.parent_id', $parentCol)->where('cc.campaign_id', $this->f['campaign']));
        }
    }

    /** dim_parents en périmètre (école via parcours + statut + campagne). */
    private function scopedParents()
    {
        $q = DB::table('dim_parents')->where('dim_parents.is_test', false);

        if ($this->schoolIds !== null) {
            $q->whereExists(fn ($sub) => $sub->from('fact_parent_journeys as j')
                ->whereColumn('j.parent_id', 'dim_parents.id')->where('j.is_test', false)
                ->whereIn('j.school_id', $this->schoolIds));
        }
        $this->applyParentScope($q, 'dim_parents.id');

        return $q;
    }

    /** fact_parent_journeys en périmètre (école + statut + campagne). */
    private function scopedJourneys()
    {
        $q = DB::table('fact_parent_journeys as j')->where('j.is_test', false);

        if ($this->schoolIds !== null) {
            $q->whereIn('j.school_id', $this->schoolIds);
        }
        if ($this->f['stage'] !== null) {
            $q->where('j.current_stage_id', $this->f['stage']);
        }
        if ($this->f['campaign'] !== null) {
            $q->whereExists(fn ($sub) => $sub->from('fact_campaign_contacts as cc')
                ->whereColumn('cc.parent_id', 'j.parent_id')->where('cc.campaign_id', $this->f['campaign']));
        }

        return $q;
    }

    /* ------------------------------------------------------------- Périodes */

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveRange(string $period, ?string $from = null, ?string $to = null): array
    {
        $now = Carbon::now();

        // Plage personnalisée saisie par l'utilisateur.
        if ($period === 'custom' && $from && $to) {
            try {
                $start = Carbon::parse($from)->startOfDay();
                $end = Carbon::parse($to)->endOfDay();
                if ($end->lt($start)) {
                    [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
                }

                return [$start, $end->gt($now) ? $now->copy() : $end];
            } catch (\Throwable $e) {
                // Saisie invalide : on retombe sur l'année scolaire.
            }
        }

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
        $connus = (int) $this->scopedParents()->count();
        $inscrits = (int) $this->scopedParents()->whereNotNull('account_created_at')->count();
        $actifs = (int) $this->scopedJourneys()->where('j.has_ever_paid', true)->distinct()->count('j.parent_id');
        $ecoles = (int) $this->scopedSchools()->count();
        $eleves = (int) $this->scopedStudents()->count();
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
        $connus = (int) $this->scopedParents()->count();
        $actifs = (int) $this->scopedJourneys()->where('j.has_ever_paid', true)->distinct()->count('j.parent_id');
        $rate = $connus > 0 ? round($actifs / $connus * 100, 1) : 0.0;

        // Élan sur 30 jours, exprimé en points d'adoption.
        $adopters30 = (int) $this->scopedJourneys()
            ->where('j.first_payment_at', '>=', Carbon::now()->subDays(30))->distinct()->count('j.parent_id');
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
        $now = (int) $this->scopedJourneys()->whereNotNull('j.first_payment_at')->distinct()->count('j.parent_id');
        $then = (int) $this->scopedJourneys()
            ->whereNotNull('j.first_payment_at')->where('j.first_payment_at', '<=', $prevEnd)->distinct()->count('j.parent_id');

        $pts = round(($now - $then) / $connus * 100, 1);
        if ($pts == 0.0) {
            return null;
        }

        return ['dir' => $pts >= 0 ? 'up' : 'down', 'pts' => abs($pts)];
    }

    private function deltaByDate(string $table, string $col, Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd): ?array
    {
        $base = fn () => $table === 'dim_parents'
            ? $this->scopedParents()
            : DB::table($table)->where('is_test', false);
        $current = (int) $base()->whereBetween($col, [$start, $end])->count();
        $previous = (int) $base()->whereBetween($col, [$prevStart, $prevEnd])->count();

        return $this->delta($current, $previous);
    }

    private function deltaAdopters(Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd): ?array
    {
        $current = (int) $this->scopedJourneys()->whereBetween('j.first_payment_at', [$start, $end])->distinct()->count('j.parent_id');
        $previous = (int) $this->scopedJourneys()->whereBetween('j.first_payment_at', [$prevStart, $prevEnd])->distinct()->count('j.parent_id');

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
        $connus = max(1, (int) $this->scopedParents()->count());

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
        $connus = (int) $this->scopedParents()->count();
        $inscrits = (int) $this->scopedParents()->whereNotNull('account_created_at')->count();
        $adoptants = (int) $this->scopedJourneys()->where('j.has_ever_paid', true)->distinct()->count('j.parent_id');
        $engages = (int) DB::query()->fromSub(
            $this->scopedJourneys()->where('j.has_ever_paid', true)
                ->groupBy('j.parent_id')->havingRaw('SUM(j.successful_payment_count) >= 2')->selectRaw('j.parent_id'),
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
        $connus = (int) $this->scopedParents()->count();
        $inscrits = (int) $this->scopedParents()->whereNotNull('account_created_at')->count();
        $actifs = (int) $this->scopedJourneys()->where('j.has_ever_paid', true)->distinct()->count('j.parent_id');

        $inactifsInner = DB::table('dim_parents')
            ->join('fact_parent_journeys as j', 'j.parent_id', '=', 'dim_parents.id')
            ->where('dim_parents.is_test', false)->where('j.is_test', false)
            ->groupBy('dim_parents.id')
            ->havingRaw('MAX(j.current_stage_id) IN (5, 6)')
            ->selectRaw('dim_parents.id');
        if ($this->schoolIds !== null) {
            $inactifsInner->whereIn('j.school_id', $this->schoolIds);
        }
        if ($this->f['campaign'] !== null) {
            $inactifsInner->whereExists(fn ($sub) => $sub->from('fact_campaign_contacts as cc')
                ->whereColumn('cc.parent_id', 'dim_parents.id')->where('cc.campaign_id', $this->f['campaign']));
        }
        $inactifs = (int) DB::query()->fromSub($inactifsInner, 'sub')->count();

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
        $q = DB::table('dim_schools as s')
            ->leftJoin('fact_parent_journeys as j', function ($join) {
                $join->on('j.school_id', '=', 's.id')->where('j.is_test', false);
                if ($this->f['stage'] !== null) {
                    $join->where('j.current_stage_id', $this->f['stage']);
                }
            })
            ->where('s.is_test', false)->whereNotNull('s.is_current');

        if ($this->schoolIds !== null) {
            $q->whereIn('s.id', $this->schoolIds);
        }

        return $q
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

        $newAccounts = (int) $this->scopedParents()
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
                'link_route' => 'schools.show', 'link_param' => $worst['id'],
            ];
        }

        $relance = (int) $this->scopedParents()->whereNull('account_created_at')->count();
        if ($relance > 0) {
            $recos[] = [
                'priority' => 'elevee',
                'title' => 'Relancer les parents connus mais non inscrits',
                'why' => "{$this->num($relance)} numéros figurent sur les listes d'écoles sans compte EcolePay : le plus grand réservoir d'inscription.",
                'link_route' => 'parents.index', 'link_param' => null,
            ];
        }

        $registeredNotPaid = $this->registeredNotPaidCount();
        if ($registeredNotPaid > 0) {
            $recos[] = [
                'priority' => 'elevee',
                'title' => 'Convertir les inscrits inactifs en payeurs',
                'why' => "{$this->num($registeredNotPaid)} parents ont un compte mais n'ont jamais payé : un rappel ciblé peut déclencher le premier paiement.",
                'link_route' => 'parents.index', 'link_param' => null,
            ];
        }

        $opp = $this->opportunities()[0] ?? null;
        if ($opp) {
            $recos[] = [
                'priority' => 'moyenne',
                'title' => "Prioriser {$opp['name']} : fort potentiel de revenu",
                'why' => "Potentiel d'abonnement estimé à {$this->money($opp['potential'])} si les {$this->num($opp['nonAdopters'])} parents restants adoptent.",
                'link_route' => 'schools.show', 'link_param' => $opp['id'],
            ];
        }

        return $recos;
    }

    /* --------------------------------------------------------------- Outils */

    private function paymentsQuery()
    {
        $q = DB::table('fact_payments')
            ->where('fact_payments.is_test', false)
            ->where('fact_payments.is_manual', false)
            ->where('fact_payments.status', 'success');

        if ($this->schoolIds !== null) {
            $q->whereIn('fact_payments.school_id', $this->schoolIds);
        }
        if ($this->f['operator'] !== null) {
            // Opérateur inconnu (0) => aucun paiement, plutôt que d'ignorer le filtre.
            $q->where('fact_payments.payment_method_id', $this->operatorId ?? 0);
        }
        if ($this->f['schoolYear'] !== null) {
            $q->where('fact_payments.school_year_label', $this->f['schoolYear']);
        }
        $this->applyParentScope($q, 'fact_payments.parent_id');

        return $q;
    }

    /** dim_schools en périmètre (école/région/type). */
    private function scopedSchools()
    {
        $q = DB::table('dim_schools')->where('is_test', false)->whereNotNull('is_current');
        if ($this->schoolIds !== null) {
            $q->whereIn('id', $this->schoolIds);
        }

        return $q;
    }

    /** dim_students en périmètre (école + année scolaire). */
    private function scopedStudents()
    {
        $q = DB::table('dim_students')->where('is_test', false);
        if ($this->schoolIds !== null) {
            $q->whereIn('school_id', $this->schoolIds);
        }
        if ($this->f['schoolYear'] !== null) {
            $q->where('school_year_label', $this->f['schoolYear']);
        }

        return $q;
    }

    private function potentialSubscriptionRevenue(): int
    {
        $q = DB::table('fact_parent_journeys as j')
            ->join('dim_schools as s', 's.id', '=', 'j.school_id')
            ->where('j.is_test', false)->where('j.has_ever_paid', false)
            ->where('s.subscription_model', 'parent_paid');
        if ($this->schoolIds !== null) {
            $q->whereIn('j.school_id', $this->schoolIds);
        }
        if ($this->f['stage'] !== null) {
            $q->where('j.current_stage_id', $this->f['stage']);
        }

        return (int) $q->sum('s.subscription_amount');
    }

    private function registeredNotPaidCount(): int
    {
        return (int) $this->scopedParents()
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
        $q = $table === 'dim_parents'
            ? $this->scopedParents()
            : DB::table($table)->where('is_test', false);

        return $q->whereNotNull($col)
            ->where($col, '>=', Carbon::now()->startOfMonth()->subMonths(11))
            ->selectRaw("DATE_FORMAT($col, '%Y-%m') as m, COUNT(*) as n")
            ->groupBy('m')->pluck('n', 'm')->map(fn ($v) => (int) $v)->all();
    }

    private function adoptersByMonth()
    {
        return $this->scopedJourneys()->whereNotNull('j.first_payment_at')
            ->where('j.first_payment_at', '>=', Carbon::now()->startOfMonth()->subMonths(11))
            ->selectRaw("DATE_FORMAT(j.first_payment_at, '%Y-%m') as m, COUNT(DISTINCT j.parent_id) as n")
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
        $q = DB::table('fact_parent_journeys as j')
            ->join('dim_schools as s', 's.id', '=', 'j.school_id')
            ->where('j.is_test', false)->where('j.has_ever_paid', true)
            ->where('s.subscription_model', 'parent_paid')
            ->whereNotNull('j.first_payment_at')
            ->where('j.first_payment_at', '>=', Carbon::now()->startOfMonth()->subMonths(11));
        if ($this->schoolIds !== null) {
            $q->whereIn('j.school_id', $this->schoolIds);
        }
        if ($this->f['stage'] !== null) {
            $q->where('j.current_stage_id', $this->f['stage']);
        }

        return $q->selectRaw("DATE_FORMAT(j.first_payment_at, '%Y-%m') as m, SUM(s.subscription_amount) as v")
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
