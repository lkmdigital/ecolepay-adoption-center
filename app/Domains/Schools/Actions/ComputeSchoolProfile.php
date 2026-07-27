<?php

namespace App\Domains\Schools\Actions;

use App\Domains\Schools\Support\HealthScore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assemble la fiche complète d'une école : en-tête, résumé exécutif, KPI, séries
 * d'adoption, entonnoir, répartition, opportunités, activité récente et chronologie.
 *
 * Tout est calculé sur les vraies données de l'entrepôt, borné à une école. Les
 * sections campagnes restent vides tant que le module n'existe pas.
 */
final class ComputeSchoolProfile
{
    private const MOIS_FR = [
        1 => 'jan.', 2 => 'fév.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin',
        7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.',
    ];

    public function __invoke(int $schoolId): ?array
    {
        $s = DB::table('dim_schools')->where('is_test', false)->whereNotNull('is_current')->where('id', $schoolId)->first();
        if (! $s) {
            return null;
        }

        $f = $this->funnelCounts($schoolId);
        $pay = $this->paymentAggregates($schoolId);
        $rate = $f['known'] > 0 ? round($f['actifs'] / $f['known'] * 100, 1) : 0.0;
        $nonAdopters = max($f['known'] - $f['actifs'], 0);
        $potential = $s->subscription_model === 'parent_paid' ? $nonAdopters * (int) $s->subscription_amount : 0;
        $students = (int) DB::table('dim_students')->where('is_test', false)->where('school_id', $schoolId)->count();

        $health = HealthScore::compute($rate, $f['known'], $f['inscrits'], $f['actifs'], $f['recent'], (int) $pay['revenue'], $pay['last']);

        return [
            'school' => [
                'id' => $s->id,
                'name' => $s->name,
                'code' => $s->school_code,
                'city' => $s->city,
                'region' => $s->region,
                'status' => $s->status,
                'subscriptionModel' => $s->subscription_model,
                'subscriptionAmount' => (int) $s->subscription_amount,
                'syncedAt' => $s->synced_at,
            ],
            'kpis' => [
                'students' => $students,
                'known' => $f['known'],
                'inscrits' => $f['inscrits'],
                'actifs' => $f['actifs'],
                'abonnes' => $s->subscription_model === 'parent_paid' ? $f['actifs'] : 0,
                'paymentsCount' => (int) $pay['count'],
                'revenue' => (int) $pay['revenue'],
                'potential' => $potential,
                'rate' => $rate,
                'nonAdopters' => $nonAdopters,
                'inscritsInactifs' => max($f['inscrits'] - $f['actifs'], 0),
            ],
            'health' => $health,
            'diagnostic' => $this->diagnostic($f, $rate, $nonAdopters, (int) $s->subscription_amount, $s->subscription_model),
            'adoption' => $this->adoptionSeries($schoolId, $f['known']),
            'funnel' => $this->funnel($f),
            'repartition' => $this->repartition($f),
            'timeline' => $this->timeline($schoolId, $s, $pay),
            'recent' => $this->recentActivity($schoolId),
            'opportunities' => $this->opportunities($f, $nonAdopters, $potential, $s->subscription_model),
        ];
    }

    /** @return array{known:int, inscrits:int, actifs:int, recurrents:int, fideles:int, inactifs:int, recent:int} */
    private function funnelCounts(int $schoolId): array
    {
        $row = DB::table('fact_parent_journeys as j')
            ->leftJoin('dim_parents as p', 'p.id', '=', 'j.parent_id')
            ->where('j.is_test', false)->where('j.school_id', $schoolId)
            ->selectRaw('COUNT(DISTINCT j.parent_id) as known')
            ->selectRaw('COUNT(DISTINCT CASE WHEN p.account_created_at IS NOT NULL THEN j.parent_id END) as inscrits')
            ->selectRaw('COUNT(DISTINCT CASE WHEN j.has_ever_paid = 1 THEN j.parent_id END) as actifs')
            ->selectRaw('COUNT(DISTINCT CASE WHEN j.successful_payment_count >= 2 THEN j.parent_id END) as recurrents')
            ->selectRaw('COUNT(DISTINCT CASE WHEN j.successful_payment_count >= 2 AND j.current_stage_id IN (3,4) THEN j.parent_id END) as fideles')
            ->selectRaw('COUNT(DISTINCT CASE WHEN j.current_stage_id IN (5,6) THEN j.parent_id END) as inactifs')
            ->selectRaw('COUNT(DISTINCT CASE WHEN j.first_payment_at >= ? THEN j.parent_id END) as recent', [Carbon::now()->subDays(90)])
            ->first();

        return [
            'known' => (int) $row->known, 'inscrits' => (int) $row->inscrits, 'actifs' => (int) $row->actifs,
            'recurrents' => (int) $row->recurrents, 'fideles' => (int) $row->fideles,
            'inactifs' => (int) $row->inactifs, 'recent' => (int) $row->recent,
        ];
    }

    /** @return array{revenue:int, count:int, first:?string, last:?string} */
    private function paymentAggregates(int $schoolId): array
    {
        $row = DB::table('fact_payments')->where('is_test', false)->where('is_manual', false)->where('status', 'success')
            ->where('school_id', $schoolId)
            ->selectRaw('COALESCE(SUM(amount),0) as revenue, COUNT(*) as c, MIN(paid_at) as first_at, MAX(paid_at) as last_at')
            ->first();

        return ['revenue' => (int) $row->revenue, 'count' => (int) $row->c, 'first' => $row->first_at, 'last' => $row->last_at];
    }

    /**
     * Diagnostic d'adoption : interprète l'entonnoir plutôt que de le décrire.
     * Localise le frein principal (inscription vs activation), formule la priorité
     * et chiffre le gain atteignable (parents supplémentaires + revenu annuel estimé).
     *
     * @return array{headline: string, text: string, registrationRate: float, activationRate: float,
     *     registrationLabel: string, activationLabel: string, bottleneck: string, tone: string,
     *     color: string, bg: string, targetParents: int, annualRevenue: int, lever: string}
     */
    private function diagnostic(array $f, float $rate, int $nonAdopters, int $subAmount, string $model): array
    {
        $reg = $f['known'] > 0 ? round($f['inscrits'] / $f['known'] * 100, 1) : 0.0;
        $act = $f['inscrits'] > 0 ? round($f['actifs'] / $f['inscrits'] * 100, 1) : 0.0;
        $regL = $this->rateLabel($reg);
        $actL = $this->rateLabel($act);
        $pp = $model === 'parent_paid';
        $pct = fn ($v) => number_format($v, 0, ',', ' ').' %';
        $rev = fn ($n) => $n >= 1_000_000 ? number_format($n / 1_000_000, 1, ',', ' ').' millions FCFA' : number_format($n, 0, ',', ' ').' FCFA';

        // Base insuffisante : aucun diagnostic fiable possible.
        if ($f['known'] < 15) {
            return [
                'headline' => 'Base insuffisante pour conclure',
                'text' => "La base est trop faible ({$f['known']} parents connus) pour un diagnostic fiable. La priorité est d'importer la liste complète des parents de l'établissement et de fiabiliser les numéros avant toute campagne.",
                'registrationRate' => $reg, 'activationRate' => $act,
                'registrationLabel' => $regL, 'activationLabel' => $actL,
                'bottleneck' => 'base', 'tone' => 'Base à consolider', 'color' => '#B91C1C', 'bg' => '#FDECEC',
                'targetParents' => 0, 'annualRevenue' => 0, 'lever' => 'Importer et fiabiliser la liste des parents',
            ];
        }

        // Le frein est l'étape la plus faible de l'entonnoir, sous le seuil « bon ».
        if ($reg <= $act && $reg < 55) {
            $target = max($f['known'] - $f['inscrits'], 0);
            $revenue = $pp ? $target * $subAmount : 0;
            // Contraste seulement si l'activation est réellement bonne ; sinon les deux étapes sont faibles.
            $middle = $act >= 55
                ? "mais un taux d'activation {$actL} ({$pct($act)} des parents inscrits effectuent un paiement). Cela indique que le principal problème se situe avant la création du compte."
                : "et un taux d'activation lui aussi {$actL} ({$pct($act)}). Les deux étapes sont à renforcer, en commençant par l'inscription : on ne peut pas activer des parents qui n'ont pas encore de compte.";

            return [
                'headline' => "Frein principal : l'inscription ({$pct($reg)})",
                'text' => "L'école présente un taux d'inscription {$regL} ({$pct($reg)}), {$middle} La priorité est d'augmenter le nombre de parents inscrits grâce à une campagne WhatsApp ciblée et à une meilleure communication de l'établissement. Le potentiel estimé est de ".number_format($target, 0, ',', ' ').' parents supplémentaires'.($pp ? ", représentant environ {$rev($revenue)} de revenus annuels." : '.'),
                'registrationRate' => $reg, 'activationRate' => $act,
                'registrationLabel' => $regL, 'activationLabel' => $actL,
                'bottleneck' => 'registration', 'tone' => 'Frein : inscription', 'color' => '#B45F04', 'bg' => '#FEF3E2',
                'targetParents' => $target, 'annualRevenue' => $revenue, 'lever' => 'Lancer une campagne WhatsApp d\'inscription',
            ];
        }

        if ($act < 55) {
            $target = max($f['inscrits'] - $f['actifs'], 0);
            $revenue = $pp ? $target * $subAmount : 0;

            return [
                'headline' => "Frein principal : l'activation ({$pct($act)})",
                'text' => "L'école affiche un taux d'inscription {$regL} ({$pct($reg)}), mais un taux d'activation {$actL} : seuls {$pct($act)} des inscrits effectuent un premier paiement. Le problème ne se situe donc pas à l'inscription mais au moment du premier paiement. La priorité est d'accompagner l'activation — rappel ciblé des inscrits inactifs et appui au guichet de l'établissement. Le potentiel immédiat est de ".number_format($target, 0, ',', ' ').' inscrits à convertir'.($pp ? ", soit environ {$rev($revenue)} de revenus annuels." : '.'),
                'registrationRate' => $reg, 'activationRate' => $act,
                'registrationLabel' => $regL, 'activationLabel' => $actL,
                'bottleneck' => 'activation', 'tone' => 'Frein : activation', 'color' => '#B45F04', 'bg' => '#FEF3E2',
                'targetParents' => $target, 'annualRevenue' => $revenue, 'lever' => 'Programmer une relance des inscrits inactifs',
            ];
        }

        // Entonnoir sain : le levier n'est plus la conversion mais le volume.
        $revenue = $pp ? $nonAdopters * $subAmount : 0;

        return [
            'headline' => 'Entonnoir sain — enjeu de volume',
            'text' => "L'entonnoir est sain : inscription {$regL} ({$pct($reg)}) et activation {$actL} ({$pct($act)}). L'école convertit bien à chaque étape ; l'enjeu n'est plus la conversion mais le volume. La priorité est d'élargir la base de parents connus (import des nouvelles classes, mise à jour des numéros) et de fidéliser les payeurs. Potentiel restant : ".number_format($nonAdopters, 0, ',', ' ').' parents'.($pp ? ", soit environ {$rev($revenue)} de revenus annuels." : '.'),
            'registrationRate' => $reg, 'activationRate' => $act,
            'registrationLabel' => $regL, 'activationLabel' => $actL,
            'bottleneck' => 'healthy', 'tone' => 'Entonnoir sain', 'color' => '#0F7A44', 'bg' => '#E9F8EF',
            'targetParents' => $nonAdopters, 'annualRevenue' => $revenue, 'lever' => 'Élargir la base et fidéliser les payeurs',
        ];
    }

    private function rateLabel(float $rate): string
    {
        return match (true) {
            $rate >= 75 => 'excellent',
            $rate >= 55 => 'bon',
            $rate >= 35 => 'moyen',
            default => 'faible',
        };
    }

    /** @return array{labels: list<string>, rate: list<float>, events: list<array{index:int, label:string}>} */
    private function adoptionSeries(int $schoolId, int $known): array
    {
        $known = max(1, $known);
        $keys = [];
        $cursor = Carbon::now()->startOfMonth()->subMonths(11);
        for ($i = 0; $i < 12; $i++) {
            $keys[] = ['key' => $cursor->format('Y-m'), 'month' => $cursor->month];
            $cursor->addMonth();
        }

        $byMonth = DB::table('fact_parent_journeys')->where('is_test', false)->where('school_id', $schoolId)
            ->whereNotNull('first_payment_at')->where('first_payment_at', '>=', Carbon::now()->startOfMonth()->subMonths(11))
            ->selectRaw("DATE_FORMAT(first_payment_at, '%Y-%m') as m, COUNT(DISTINCT parent_id) as n")->groupBy('m')->pluck('n', 'm');
        $before = (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('school_id', $schoolId)
            ->whereNotNull('first_payment_at')->where('first_payment_at', '<', Carbon::now()->startOfMonth()->subMonths(11))->distinct()->count('parent_id');

        $labels = [];
        $series = [];
        $events = [];
        $cumulative = $before;
        $startMonth = (int) config('eac.school_year_start_month', 9);
        foreach ($keys as $i => $k) {
            $labels[] = self::MOIS_FR[$k['month']];
            $cumulative += (int) ($byMonth[$k['key']] ?? 0);
            $series[] = round($cumulative / $known * 100, 1);
            if ($k['month'] === $startMonth) {
                $events[] = ['index' => $i, 'label' => 'Rentrée scolaire'];
            }
        }

        return ['labels' => $labels, 'rate' => $series, 'events' => $events];
    }

    /** @return list<array{label:string, value:int, conv:?float}> */
    private function funnel(array $f): array
    {
        $stages = [
            ['Parents connus', $f['known']],
            ['Comptes créés', $f['inscrits']],
            ['Premier paiement ⭐', $f['actifs']],
            ['Paiements récurrents', $f['recurrents']],
            ['Parents engagés', $f['fideles']],
        ];

        $out = [];
        $prev = null;
        foreach ($stages as [$label, $value]) {
            $out[] = ['label' => $label, 'value' => $value, 'conv' => $prev !== null && $prev > 0 ? round($value / $prev * 100, 1) : null];
            $prev = $value;
        }

        return $out;
    }

    /** @return list<array{label:string, value:int, color:string}> */
    private function repartition(array $f): array
    {
        return [
            ['label' => 'Non inscrits', 'value' => max($f['known'] - $f['inscrits'], 0), 'color' => '#94A3B8'],
            ['label' => 'Inscrits sans paiement', 'value' => max($f['inscrits'] - $f['actifs'], 0), 'color' => '#38BDF8'],
            ['label' => 'Actifs', 'value' => max($f['actifs'] - $f['inactifs'], 0), 'color' => '#22C55E'],
            ['label' => 'Inactifs (à risque · perdus)', 'value' => $f['inactifs'], 'color' => '#F59E0B'],
        ];
    }

    /** @return list<array{label:string, date:?string, icon:string, available:bool}> */
    private function timeline(int $schoolId, object $s, array $pay): array
    {
        return [
            ['label' => 'Signature de l\'école', 'date' => null, 'icon' => 'signature', 'available' => false],
            ['label' => 'Première importation des élèves', 'date' => null, 'icon' => 'import', 'available' => false],
            ['label' => 'Premier paiement enregistré', 'date' => $pay['first'], 'icon' => 'payment', 'available' => (bool) $pay['first']],
            ['label' => 'Première campagne', 'date' => null, 'icon' => 'campaign', 'available' => false],
            ['label' => 'Dernier paiement enregistré', 'date' => $pay['last'], 'icon' => 'payment', 'available' => (bool) $pay['last']],
            ['label' => 'Dernière synchronisation', 'date' => $s->synced_at, 'icon' => 'sync', 'available' => (bool) $s->synced_at],
        ];
    }

    /** @return list<array{type:string, label:string, date:string, amount:?int}> */
    private function recentActivity(int $schoolId): array
    {
        return DB::table('fact_payments')->where('is_test', false)->where('is_manual', false)->where('status', 'success')
            ->where('school_id', $schoolId)->whereNotNull('paid_at')
            ->orderByDesc('paid_at')->limit(8)
            ->get(['amount', 'paid_at', 'is_first_payment'])
            ->map(fn ($p) => [
                'type' => $p->is_first_payment ? 'first' : 'payment',
                'label' => $p->is_first_payment ? 'Premier paiement d\'un parent' : 'Paiement reçu',
                'date' => $p->paid_at,
                'amount' => (int) $p->amount,
            ])->all();
    }

    private function opportunities(array $f, int $nonAdopters, int $potential, string $model): array
    {
        $reg = $f['known'] > 0 ? $f['inscrits'] / $f['known'] * 100 : 0;
        $priority = $f['known'] >= 20 && $f['actifs'] / max($f['known'], 1) * 100 < 20 ? 'Critique'
            : ($potential > 5_000_000 ? 'Élevée' : 'Moyenne');

        $actions = [];
        if ($reg < 45 && $f['known'] >= 15) {
            $actions[] = ['title' => 'Organiser une campagne WhatsApp', 'impact' => 'Élevé', 'difficulty' => 'Faible', 'why' => number_format($nonAdopters, 0, ',', ' ').' parents connus restent à inscrire.'];
            $actions[] = ['title' => 'Communiquer auprès des parents via l\'école', 'impact' => 'Moyen', 'difficulty' => 'Faible', 'why' => 'Relais institutionnel pour crédibiliser l\'application.'];
        }
        if ($f['inscrits'] - $f['actifs'] > 0) {
            $actions[] = ['title' => 'Programmer une relance des inscrits inactifs', 'impact' => 'Élevé', 'difficulty' => 'Faible', 'why' => number_format(max($f['inscrits'] - $f['actifs'], 0), 0, ',', ' ').' inscrits n\'ont jamais payé.'];
        }
        $actions[] = ['title' => 'Former le personnel administratif', 'impact' => 'Moyen', 'difficulty' => 'Moyenne', 'why' => 'Accompagnement au guichet pour lever les blocages du premier paiement.'];

        return [
            'nonInscrits' => max($f['known'] - $f['inscrits'], 0),
            'inscritsInactifs' => max($f['inscrits'] - $f['actifs'], 0),
            'potential' => $potential,
            'priority' => $priority,
            'actions' => array_slice($actions, 0, 4),
        ];
    }
}
