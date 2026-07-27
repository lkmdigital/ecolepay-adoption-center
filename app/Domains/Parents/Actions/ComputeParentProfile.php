<?php

namespace App\Domains\Parents\Actions;

use App\Domains\Parents\Support\ParentLifecycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fiche CRM complète d'un parent : identité, parcours d'adoption, diagnostic,
 * chronologie, paiements, campagnes reçues, score et recommandations.
 *
 * Adoption = premier paiement ; engagement = paiements récurrents. Tout est
 * calculé à la volée depuis les parcours et paiements réels.
 */
final class ComputeParentProfile
{
    public function __invoke(int $parentId): ?array
    {
        $p = DB::table('dim_parents')->where('is_test', false)->where('id', $parentId)->first();
        if (! $p) {
            return null;
        }

        $pay = DB::table('fact_payments')->where('parent_id', $parentId)
            ->where('is_test', false)->where('is_manual', false)->where('status', 'success')
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(amount),0) as total, MIN(paid_at) as first_at, MAX(paid_at) as last_at')->first();

        $payCount = (int) $pay->c;
        $hasAccount = $p->account_created_at !== null;
        $hasPaid = $payCount > 0;
        $recurring = $payCount >= 2;
        $lifecycle = ParentLifecycle::of($hasAccount, $hasPaid, $recurring);

        $lastActivity = DB::table('fact_parent_journeys')->where('parent_id', $parentId)->where('is_test', false)->max('last_activity_at');
        $school = DB::table('fact_parent_journeys as j')->join('dim_schools as s', 's.id', '=', 'j.school_id')
            ->where('j.parent_id', $parentId)->where('j.is_test', false)
            ->orderByDesc('j.has_ever_paid')->first(['s.id', 's.name', 's.subscription_model']);

        return [
            'parent' => [
                'id' => $p->id,
                'name' => $p->full_name ?: 'Parent sans nom',
                'phone' => $p->phone_e164,
                'email' => $p->email,
                'lang' => $p->preferred_language,
                'school' => $school?->name,
                'schoolId' => $school?->id,
                'subscriptionModel' => $school?->subscription_model,
                'firstKnownAt' => $p->first_known_at,
                'accountCreatedAt' => $p->account_created_at,
                'syncedAt' => $p->synced_at,
            ],
            'lifecycle' => $lifecycle,
            'kpis' => [
                'payCount' => $payCount,
                'total' => (int) $pay->total,
                'firstPayment' => $pay->first_at,
                'lastPayment' => $pay->last_at,
                'lastActivity' => $lastActivity,
                'children' => (int) DB::table('bridge_student_parents')->where('parent_id', $parentId)->count(),
            ],
            'journey' => $this->journey($p, $pay, $recurring),
            'children' => $this->children($parentId),
            'payments' => $this->payments($parentId),
            'campaigns' => $this->campaigns($parentId, $p),
            'engagement' => $this->engagementScore($p, $pay, $recurring, $lastActivity),
            'analysis' => $this->paymentActivity($parentId),
            'diagnostic' => $this->diagnostic($lifecycle, $p, $pay),
            'recommendations' => $this->recommendations($lifecycle, $p, $pay),
        ];
    }

    /** Barre de progression : les 4 étapes, atteinte ou non, avec leur date. */
    private function journey(object $p, object $pay, bool $recurring): array
    {
        return [
            ['label' => 'Parent connu', 'sub' => 'numéro fourni par l\'école', 'reached' => true, 'date' => $p->first_known_at, 'star' => false],
            ['label' => 'Compte créé', 'sub' => 'inscription sur EcolePay', 'reached' => $p->account_created_at !== null, 'date' => $p->account_created_at, 'star' => false],
            ['label' => 'Premier paiement', 'sub' => 'adoption réelle', 'reached' => (int) $pay->c > 0, 'date' => $pay->first_at, 'star' => true],
            ['label' => 'Paiements réguliers', 'sub' => 'engagement continu', 'reached' => $recurring, 'date' => $recurring ? $pay->last_at : null, 'star' => false],
        ];
    }

    private function children(int $parentId): array
    {
        return DB::table('bridge_student_parents as b')
            ->join('dim_students as s', 's.id', '=', 'b.student_id')
            ->leftJoin('dim_schools as sc', 'sc.id', '=', 's.school_id')
            ->where('b.parent_id', $parentId)
            ->limit(20)->get(['s.display_reference', 's.class_label', 's.education_level', 'sc.name as school'])
            ->map(fn ($s) => [
                'ref' => $s->display_reference,
                'class' => $s->class_label ?: $s->education_level,
                'school' => $s->school,
            ])->all();
    }

    private function payments(int $parentId): array
    {
        return DB::table('fact_payments as f')
            ->leftJoin('dim_students as s', 's.id', '=', 'f.student_id')
            ->where('f.parent_id', $parentId)->where('f.is_test', false)
            ->orderByDesc('f.paid_at')->limit(30)
            ->get(['f.paid_at', 'f.amount', 'f.status', 'f.is_manual', 'f.installment_label', 'f.is_first_payment', 's.display_reference'])
            ->map(fn ($f) => [
                'date' => $f->paid_at,
                'amount' => (int) $f->amount,
                'status' => $f->status,
                'label' => $f->installment_label ?: ($f->is_first_payment ? 'Premier paiement' : 'Paiement'),
                'student' => $f->display_reference,
            ])->all();
    }

    private function campaigns(int $parentId, object $p): array
    {
        return DB::table('fact_campaign_contacts as cc')
            ->join('dim_campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->where('cc.parent_id', $parentId)
            ->orderByDesc('c.campaign_date')
            ->get(['c.id', 'c.name', 'c.channel', 'c.campaign_date'])
            ->map(function ($c) use ($p) {
                $d = $c->campaign_date ? Carbon::parse($c->campaign_date) : null;
                $accountAfter = $d && $p->account_created_at && Carbon::parse($p->account_created_at) >= $d;
                $firstPay = DB::table('fact_parent_journeys')->where('parent_id', $p->id)->where('is_test', false)->min('first_payment_at');
                $paymentAfter = $d && $firstPay && Carbon::parse($firstPay) >= $d;

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'channel' => $c->channel,
                    'date' => $c->campaign_date,
                    'accountAfter' => $accountAfter,
                    'paymentAfter' => $paymentAfter,
                ];
            })->all();
    }

    /** Activité de paiement par mois (12 mois) — graphique d'engagement. */
    private function paymentActivity(int $parentId): array
    {
        $byMonth = DB::table('fact_payments')->where('parent_id', $parentId)
            ->where('is_test', false)->where('is_manual', false)->where('status', 'success')
            ->where('paid_at', '>=', Carbon::now()->startOfMonth()->subMonths(11))
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as m, COUNT(*) as n")->groupBy('m')->pluck('n', 'm');

        $labels = [];
        $series = [];
        $cursor = Carbon::now()->startOfMonth()->subMonths(11);
        $mois = [1 => 'jan.', 2 => 'fév.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin', 7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.'];
        for ($i = 0; $i < 12; $i++) {
            $labels[] = $mois[$cursor->month];
            $series[] = (int) ($byMonth[$cursor->format('Y-m')] ?? 0);
            $cursor->addMonth();
        }

        return ['labels' => $labels, 'payments' => $series];
    }

    /**
     * Score d'engagement (0–100) : paiements réguliers, ancienneté, activité
     * récente, adoption, réaction après campagne.
     */
    private function engagementScore(object $p, object $pay, bool $recurring, ?string $lastActivity): array
    {
        $payCount = (int) $pay->c;
        $paiements = $recurring ? 30 : ($payCount === 1 ? 15 : 0);
        $months = $p->account_created_at ? Carbon::parse($p->account_created_at)->diffInMonths(Carbon::now()) : 0;
        $anciennete = (int) min(15, $months * 2);
        $recent = $lastActivity && Carbon::parse($lastActivity)->gt(Carbon::now()->subDays(60)) ? 25 : ($lastActivity && Carbon::parse($lastActivity)->gt(Carbon::now()->subDays(120)) ? 12 : 0);
        $adoption = $payCount > 0 ? 15 : 0;
        $postCampaign = $this->reactedAfterCampaign($p) ? 15 : 0;

        $breakdown = [
            ['label' => 'Paiements réguliers', 'score' => $paiements, 'weight' => 30],
            ['label' => 'Ancienneté', 'score' => $anciennete, 'weight' => 15],
            ['label' => 'Activité récente', 'score' => $recent, 'weight' => 25],
            ['label' => 'Adoption (1er paiement)', 'score' => $adoption, 'weight' => 15],
            ['label' => 'Réaction après campagne', 'score' => $postCampaign, 'weight' => 15],
        ];
        $score = array_sum(array_column($breakdown, 'score'));

        [$level, $color, $bg] = match (true) {
            $score >= 80 => ['Excellent', '#0F7A44', '#E9F8EF'],
            $score >= 60 => ['Bon', '#0B6A3B', '#DDF3E7'],
            $score >= 40 => ['Moyen', '#B45F04', '#FEF3E2'],
            $score >= 20 => ['Faible', '#B45309', '#FEF9E7'],
            default => ['Critique', '#B91C1C', '#FDECEC'],
        };

        return ['score' => $score, 'level' => $level, 'color' => $color, 'bg' => $bg, 'breakdown' => $breakdown];
    }

    private function reactedAfterCampaign(object $p): bool
    {
        $campaignDate = DB::table('fact_campaign_contacts as cc')->join('dim_campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->where('cc.parent_id', $p->id)->min('c.campaign_date');
        if (! $campaignDate) {
            return false;
        }
        $firstPay = DB::table('fact_parent_journeys')->where('parent_id', $p->id)->where('is_test', false)->min('first_payment_at');

        return ($p->account_created_at && $p->account_created_at >= $campaignDate)
            || ($firstPay && $firstPay >= $campaignDate);
    }

    private function diagnostic(array $lifecycle, object $p, object $pay): string
    {
        $ago = fn ($d) => $d ? Carbon::parse($d)->locale('fr')->diffForHumans() : null;

        return match ($lifecycle['level']) {
            'engage' => "Parent engagé : {$pay->c} paiements effectués sur EcolePay, dernier ".($ago($pay->last_at) ?? 'récemment').". Excellent niveau d'utilisation continue — à fidéliser.",
            'adoptant' => 'Ce parent a adopté EcolePay : premier paiement '.($ago($pay->first_at) ?? '').". L'enjeu est désormais de le faire revenir régulièrement (renouvellement, prochaines tranches).",
            'inscrit' => 'Ce parent a créé son compte '.($ago($p->account_created_at) ?? '').", mais n'a jamais effectué de premier paiement. Le frein se situe à l'activation : un rappel ciblé et un accompagnement au premier paiement sont recommandés.",
            default => "Numéro connu (fourni par l'école), sans compte EcolePay à ce jour. Une relance — WhatsApp ou via l'établissement — est recommandée pour déclencher l'inscription.",
        };
    }

    private function recommendations(array $lifecycle, object $p, object $pay): array
    {
        return match ($lifecycle['level']) {
            'engage' => [
                ['priority' => 'faible', 'title' => 'Aucun suivi nécessaire', 'why' => 'Parent engagé et régulier ; le maintenir informé des nouveautés suffit.'],
            ],
            'adoptant' => [
                ['priority' => 'moyenne', 'title' => 'Encourager les paiements suivants', 'why' => 'A payé une fois ; un rappel avant la prochaine échéance favorise la fidélisation.'],
            ],
            'inscrit' => [
                ['priority' => 'elevee', 'title' => 'Proposer une assistance à l\'inscription / au paiement', 'why' => 'Compte créé mais aucun paiement : le blocage est au premier paiement.'],
                ['priority' => 'moyenne', 'title' => 'Relancer par WhatsApp', 'why' => 'Un rappel ciblé peut déclencher le premier paiement.'],
            ],
            default => [
                ['priority' => 'elevee', 'title' => 'Relancer ce parent par WhatsApp', 'why' => 'Numéro connu sans compte : cible directe d\'inscription.'],
                ['priority' => 'moyenne', 'title' => 'Contacter l\'établissement', 'why' => 'Le relais de l\'école crédibilise l\'application auprès du parent.'],
            ],
        };
    }
}
