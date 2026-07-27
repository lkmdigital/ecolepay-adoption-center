<?php

namespace App\Domains\Campaigns\Actions;

use App\Domains\Campaigns\Models\Campaign;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mesure l'impact d'une campagne en rapprochant ses contacts importés des parcours
 * parents réels. La campagne tourne dans Perfect CX ; ici on répond à la seule
 * question qui compte : combien de contacts ont créé un compte / payé APRÈS elle ?
 *
 * L'attribution est bornée à [date de campagne ; date + fenêtre d'attribution].
 * Tout est recalculé à la volée : les parcours évoluent à chaque synchronisation.
 */
final class MeasureCampaign
{
    public function __invoke(Campaign $campaign): array
    {
        $d = $campaign->campaign_date ? Carbon::parse($campaign->campaign_date)->startOfDay() : Carbon::parse($campaign->created_at)->startOfDay();
        $end = $d->copy()->addDays($campaign->attribution_window_days);

        return $campaign->channel->isContactBased()
            ? $this->measureContacts($campaign, $d, $end)
            : $this->measureSchool($campaign, $d, $end);
    }

    /** Mesure par rapprochement individuel des contacts importés (canaux à liste). */
    private function measureContacts(Campaign $campaign, Carbon $d, Carbon $end): array
    {
        $schoolId = $campaign->school_id;

        $contacts = (int) DB::table('fact_campaign_contacts')->where('campaign_id', $campaign->id)->where('is_valid', true)->count();
        $matched = (int) DB::table('fact_campaign_contacts')->where('campaign_id', $campaign->id)->where('is_valid', true)->whereNotNull('parent_id')->count();

        // Base des parents rapprochés, avec leur compte et leur parcours (école ciblée si renseignée).
        $rows = DB::table('fact_campaign_contacts as c')
            ->join('dim_parents as p', 'p.id', '=', 'c.parent_id')
            ->leftJoin('fact_parent_journeys as j', function ($join) use ($schoolId) {
                $join->on('j.parent_id', '=', 'p.id')->where('j.is_test', false);
                if ($schoolId) {
                    $join->where('j.school_id', $schoolId);
                }
            })
            ->where('c.campaign_id', $campaign->id)->where('c.is_valid', true)
            ->groupBy('p.id', 'p.account_created_at')
            ->selectRaw('p.id, p.account_created_at, MAX(j.first_payment_at) as first_payment_at, MAX(j.has_ever_paid) as paid, MAX(j.current_stage_id) as stage, MAX(j.successful_payment_count) as pay_count')
            ->get();

        $newAccounts = 0;
        $alreadyRegistered = 0;
        $noAccount = 0;
        $newPayments = 0;
        $paidAllTime = 0;
        $active = 0;
        foreach ($rows as $r) {
            $acc = $r->account_created_at ? Carbon::parse($r->account_created_at) : null;
            if ($acc === null) {
                $noAccount++;
            } elseif ($acc >= $d) {
                $newAccounts++;
            } else {
                $alreadyRegistered++;
            }
            $fp = $r->first_payment_at ? Carbon::parse($r->first_payment_at) : null;
            if ($fp && $fp >= $d && $fp <= $end) {
                $newPayments++;
            }
            if ((int) $r->paid === 1) {
                $paidAllTime++;
            }
            if ((int) $r->stage === 3 || (int) $r->stage === 4) {
                $active++;
            }
        }
        $offBase = $contacts - $matched;

        // Revenu attribué : paiements des parents ciblés dans la fenêtre.
        $revenue = (int) DB::table('fact_campaign_contacts as c')
            ->join('fact_payments as f', 'f.parent_id', '=', 'c.parent_id')
            ->where('c.campaign_id', $campaign->id)->where('c.is_valid', true)
            ->where('f.is_test', false)->where('f.is_manual', false)->where('f.status', 'success')
            ->when($schoolId, fn ($q) => $q->where('f.school_id', $schoolId))
            ->whereBetween('f.paid_at', [$d, $end])
            ->sum('f.amount');

        $conversion = $contacts > 0 ? round($newPayments / $contacts * 100, 1) : 0.0;

        return [
            'mode' => 'contacts',
            'contacts' => $contacts,
            'matched' => $matched,
            'newAccounts' => $newAccounts,
            'newPayments' => $newPayments,
            'active' => $active,
            'revenue' => (int) $revenue,
            'conversion' => $conversion,
            'window' => [$d->toDateString(), $end->toDateString()],
            'funnel' => $this->buildFunnel([
                ['Contacts importés', (int) $campaign->contacts_count],
                ['Numéros valides', $contacts],
                ["Parents connus d'EcolePay", $matched],
                ['Comptes créés', $newAccounts + $alreadyRegistered],
                ['Premier paiement ⭐', $paidAllTime],
                ['Parents engagés', $active],
            ]),
            'repartition' => [
                ['label' => 'Nouveaux inscrits (après campagne)', 'value' => $newAccounts, 'color' => '#22C55E'],
                ['label' => 'Déjà inscrits (avant)', 'value' => $alreadyRegistered, 'color' => '#38BDF8'],
                ['label' => 'Connus sans compte', 'value' => max($noAccount, 0), 'color' => '#94A3B8'],
                ['label' => 'Hors base EcolePay', 'value' => max($offBase, 0), 'color' => '#CBD5E1'],
            ],
            'evolution' => $this->evolution($campaign->id, $d, $schoolId),
        ];
    }

    /**
     * Mesure au niveau de l'école : pour les opérations sans liste de contacts
     * (diffusion, terrain), on mesure l'évolution de l'école dans la fenêtre.
     */
    private function measureSchool(Campaign $campaign, Carbon $d, Carbon $end): array
    {
        $schoolId = $campaign->school_id;
        $window = [$d->toDateString(), $end->toDateString()];

        // Sans école ciblée, une opération de diffusion n'est pas rattachable à une base mesurable.
        if (! $schoolId) {
            return [
                'mode' => 'none', 'contacts' => 0, 'matched' => 0, 'newAccounts' => 0, 'newPayments' => 0,
                'active' => 0, 'revenue' => 0, 'conversion' => 0.0, 'window' => $window,
                'funnel' => [], 'repartition' => [], 'evolution' => ['labels' => [], 'accounts' => [], 'payments' => []],
            ];
        }

        $newAccounts = (int) DB::table('dim_parents as p')->join('fact_parent_journeys as j', 'j.parent_id', '=', 'p.id')
            ->where('j.is_test', false)->where('j.school_id', $schoolId)
            ->whereBetween('p.account_created_at', [$d, $end])->distinct()->count('p.id');
        $newPayments = (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('school_id', $schoolId)
            ->whereBetween('first_payment_at', [$d, $end])->distinct()->count('parent_id');
        $revenue = (int) DB::table('fact_payments')->where('is_test', false)->where('is_manual', false)->where('status', 'success')
            ->where('school_id', $schoolId)->whereBetween('paid_at', [$d, $end])->sum('amount');

        // État actuel de l'école (contexte de l'entonnoir et de la répartition).
        $f = DB::table('fact_parent_journeys as j')->leftJoin('dim_parents as p', 'p.id', '=', 'j.parent_id')
            ->where('j.is_test', false)->where('j.school_id', $schoolId)
            ->selectRaw('COUNT(DISTINCT j.parent_id) as known')
            ->selectRaw('COUNT(DISTINCT CASE WHEN p.account_created_at IS NOT NULL THEN j.parent_id END) as inscrits')
            ->selectRaw('COUNT(DISTINCT CASE WHEN j.has_ever_paid = 1 THEN j.parent_id END) as actifs')
            ->selectRaw('COUNT(DISTINCT CASE WHEN j.current_stage_id IN (5,6) THEN j.parent_id END) as inactifs')
            ->first();
        $known = (int) $f->known;
        $inscrits = (int) $f->inscrits;
        $actifs = (int) $f->actifs;
        $inactifs = (int) $f->inactifs;

        return [
            'mode' => 'school',
            'contacts' => 0,
            'matched' => 0,
            'newAccounts' => $newAccounts,
            'newPayments' => $newPayments,
            'active' => $actifs,
            'revenue' => $revenue,
            'conversion' => $newAccounts > 0 ? round($newPayments / $newAccounts * 100, 1) : 0.0,
            'window' => $window,
            'funnel' => $this->buildFunnel([['Parents connus', $known], ['Comptes créés', $inscrits], ['Ont payé', $actifs]]),
            'repartition' => [
                ['label' => 'Non inscrits', 'value' => max($known - $inscrits, 0), 'color' => '#94A3B8'],
                ['label' => 'Inscrits sans paiement', 'value' => max($inscrits - $actifs, 0), 'color' => '#38BDF8'],
                ['label' => 'Actifs', 'value' => max($actifs - $inactifs, 0), 'color' => '#22C55E'],
                ['label' => 'Inactifs (à risque · perdus)', 'value' => $inactifs, 'color' => '#F59E0B'],
            ],
            'evolution' => $this->schoolEvolution($schoolId, $d),
        ];
    }

    /**
     * @param  list<array{0:string,1:int}>  $stages
     * @return list<array{label:string, value:int, conv:?float}>
     */
    private function buildFunnel(array $stages): array
    {
        $out = [];
        $prev = null;
        foreach ($stages as [$label, $value]) {
            $out[] = ['label' => $label, 'value' => $value, 'conv' => $prev !== null && $prev > 0 ? round($value / $prev * 100, 1) : null];
            $prev = $value;
        }

        return $out;
    }

    private function schoolEvolution(int $schoolId, Carbon $d): array
    {
        $end = $d->copy()->addDays(90);
        $accounts = DB::table('dim_parents as p')->join('fact_parent_journeys as j', 'j.parent_id', '=', 'p.id')
            ->where('j.is_test', false)->where('j.school_id', $schoolId)
            ->whereBetween('p.account_created_at', [$d, $end])
            ->selectRaw('DATEDIFF(p.account_created_at, ?) as day, COUNT(DISTINCT p.id) as n', [$d])->groupBy('day')->pluck('n', 'day');
        $payments = DB::table('fact_parent_journeys')->where('is_test', false)->where('school_id', $schoolId)
            ->whereBetween('first_payment_at', [$d, $end])
            ->selectRaw('DATEDIFF(first_payment_at, ?) as day, COUNT(DISTINCT parent_id) as n', [$d])->groupBy('day')->pluck('n', 'day');

        $labels = [];
        $accSeries = [];
        $paySeries = [];
        $accCum = 0;
        $payCum = 0;
        for ($day = 0; $day <= 90; $day++) {
            $accCum += (int) ($accounts[$day] ?? 0);
            $payCum += (int) ($payments[$day] ?? 0);
            $labels[] = $day;
            $accSeries[] = $accCum;
            $paySeries[] = $payCum;
        }

        return ['labels' => $labels, 'accounts' => $accSeries, 'payments' => $paySeries];
    }

    /**
     * Cumul quotidien sur 90 jours après la campagne : nouvelles inscriptions et
     * premiers paiements des contacts. Le front peut fenêtrer à 7/15/30/90 j.
     *
     * @return array{labels: list<int>, accounts: list<int>, payments: list<int>}
     */
    private function evolution(int $campaignId, Carbon $d, ?int $schoolId): array
    {
        $end = $d->copy()->addDays(90);

        $accounts = DB::table('fact_campaign_contacts as c')
            ->join('dim_parents as p', 'p.id', '=', 'c.parent_id')
            ->where('c.campaign_id', $campaignId)->where('c.is_valid', true)
            ->whereBetween('p.account_created_at', [$d, $end])
            ->selectRaw('DATEDIFF(p.account_created_at, ?) as day, COUNT(DISTINCT p.id) as n', [$d])
            ->groupBy('day')->pluck('n', 'day');

        $payments = DB::table('fact_campaign_contacts as c')
            ->join('fact_parent_journeys as j', 'j.parent_id', '=', 'c.parent_id')
            ->where('c.campaign_id', $campaignId)->where('c.is_valid', true)
            ->where('j.is_test', false)
            ->when($schoolId, fn ($q) => $q->where('j.school_id', $schoolId))
            ->whereBetween('j.first_payment_at', [$d, $end])
            ->selectRaw('DATEDIFF(j.first_payment_at, ?) as day, COUNT(DISTINCT j.parent_id) as n', [$d])
            ->groupBy('day')->pluck('n', 'day');

        $labels = [];
        $accSeries = [];
        $paySeries = [];
        $accCum = 0;
        $payCum = 0;
        for ($day = 0; $day <= 90; $day++) {
            $accCum += (int) ($accounts[$day] ?? 0);
            $payCum += (int) ($payments[$day] ?? 0);
            $labels[] = $day;
            $accSeries[] = $accCum;
            $paySeries[] = $payCum;
        }

        return ['labels' => $labels, 'accounts' => $accSeries, 'payments' => $paySeries];
    }
}
