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
            'summary' => $this->execSummary($f, $rate, $health['score']),
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

    /** Résumé exécutif généré : identifie le frein principal de l'entonnoir. */
    private function execSummary(array $f, float $rate, int $score): string
    {
        $reg = $f['known'] > 0 ? $f['inscrits'] / $f['known'] * 100 : 0;
        $act = $f['inscrits'] > 0 ? $f['actifs'] / $f['inscrits'] * 100 : 0;
        $r = number_format($rate, 0, ',', ' ');

        if ($f['known'] < 15) {
            return "Base trop faible pour conclure ({$f['known']} parents connus). Priorité : importer la liste complète des parents et fiabiliser les numéros avant toute campagne.";
        }

        if ($reg < 45) {
            return "L'adoption est de {$r} %. Le principal frein est le faible nombre de comptes créés (".number_format($reg, 0, ',', ' ')." % des parents connus). Une fois inscrits, les parents activent l'application à ".number_format($act, 0, ',', ' ')." %. Une campagne WhatsApp ciblée et une communication renforcée avec l'établissement sont recommandées.";
        }

        if ($act < 45) {
            return "L'adoption est de {$r} %. Les parents s'inscrivent bien (".number_format($reg, 0, ',', ' ').' %), mais peu franchissent le premier paiement ('.number_format($act, 0, ',', ' ')." % des inscrits). Le frein est l'activation : un rappel ciblé et un accompagnement au premier paiement sont recommandés.";
        }

        return "L'adoption est de {$r} %, avec un entonnoir sain (inscription ".number_format($reg, 0, ',', ' ').' %, activation '.number_format($act, 0, ',', ' ')." %). L'école est bien engagée ; l'enjeu est d'élargir la base de parents connus et de fidéliser les payeurs.";
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
            ['Numéros connus', $f['known']],
            ['Comptes créés', $f['inscrits']],
            ['Premier paiement', $f['actifs']],
            ['Paiements récurrents', $f['recurrents']],
            ['Parents fidèles', $f['fideles']],
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
