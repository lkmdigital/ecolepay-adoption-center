<?php

namespace App\Domains\AI\Support;

use App\Domains\Campaigns\Actions\ListCampaigns;
use App\Domains\Schools\Actions\ListSchoolsForPilotage;
use Illuminate\Support\Facades\DB;

/**
 * Construit un instantané compact des VRAIES données EcolePay à injecter dans le
 * prompt système de l'Assistant IA. L'assistant répond uniquement à partir de ce
 * contexte : il n'invente aucun chiffre.
 *
 * Le snapshot est mis en cache quelques minutes (les données bougent lentement).
 */
final class EacContext
{
    public function __construct(
        private readonly ListSchoolsForPilotage $schools,
        private readonly ListCampaigns $campaigns,
    ) {}

    public function build(): string
    {
        $rows = collect(($this->schools)()['rows']);
        $summary = ($this->schools)()['summary'];

        // Effectifs canoniques (dim_parents) — cohérents avec le Dashboard.
        $connus = (int) DB::table('dim_parents')->where('is_test', false)->count();
        $inscrits = (int) DB::table('dim_parents')->where('is_test', false)->whereNotNull('account_created_at')->count();
        $adoptants = (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('has_ever_paid', true)->distinct()->count('parent_id');
        $engages = (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('successful_payment_count', '>=', 2)->distinct()->count('parent_id');
        $revenue = (int) DB::table('fact_payments')->where('is_test', false)->where('is_manual', false)->where('status', 'success')->sum('amount');
        $potential = (int) $rows->where('subscriptionModel', 'parent_paid')->sum('potential');

        $tauxInscription = $connus > 0 ? round($inscrits / $connus * 100, 1) : 0;
        $tauxActivation = $inscrits > 0 ? round($adoptants / $inscrits * 100, 1) : 0;
        $tauxAdoption = $connus > 0 ? round($adoptants / $connus * 100, 1) : 0;

        $money = fn ($n) => number_format($n / 1_000_000, 1, ',', ' ').' M FCFA';
        $num = fn ($n) => number_format((float) $n, 0, ',', ' ');
        $pct = fn ($n) => number_format((float) $n, 1, ',', ' ').' %';

        $qualified = $rows->where('known', '>=', 20);
        $topSchools = $qualified->sortByDesc('rate')->take(6)
            ->map(fn ($s) => "- {$s['name']} : {$pct($s['rate'])} d'adoption ({$num($s['actifs'])}/{$num($s['known'])} parents), santé {$s['healthScore']}/100".($s['potential'] > 0 ? ", potentiel {$money($s['potential'])}" : ''))
            ->implode("\n");
        $criticalSchools = $qualified->where('rate', '<', 20)->sortBy('rate')->take(8)
            ->map(fn ($s) => "- {$s['name']} : {$pct($s['rate'])} d'adoption ({$num($s['actifs'])}/{$num($s['known'])}), santé {$s['healthScore']}/100")
            ->implode("\n");
        $topPotential = $rows->where('subscriptionModel', 'parent_paid')->sortByDesc('potential')->take(6)
            ->map(fn ($s) => "- {$s['name']} : potentiel {$money($s['potential'])} ({$num($s['known'] - $s['actifs'])} parents connus non encore adoptants)")
            ->implode("\n");

        $campaigns = collect(($this->campaigns)()['rows'] ?? [])
            ->sortByDesc('newPayments')->take(6)
            ->map(function ($c) use ($num) {
                $channel = is_object($c['channel'] ?? null) && method_exists($c['channel'], 'label') ? $c['channel']->label() : (string) ($c['channel'] ?? '—');

                return "- {$c['name']} ({$channel}) : {$num($c['newPayments'])} nouveaux adoptants, conversion {$c['conversion']} %";
            })->implode("\n");

        $date = now()->translatedFormat('d F Y');

        return <<<TXT
        DONNÉES RÉELLES ECOLEPAY (instantané du {$date}) — n'utilise QUE ces chiffres.

        ## Vue d'ensemble (effectifs canoniques)
        - Parents connus : {$num($connus)}
        - Parents inscrits : {$num($inscrits)}
        - Parents adoptants (ont fait leur 1er paiement) : {$num($adoptants)}
        - Parents engagés (paiements récurrents) : {$num($engages)}
        - Revenu cumulé via l'app : {$money($revenue)}
        - Potentiel de revenu restant (écoles parent-payant) : {$money($potential)}

        ## Taux clés
        - Taux d'inscription (inscrits/connus) : {$pct($tauxInscription)}
        - Taux d'activation (adoptants/inscrits) : {$pct($tauxActivation)}
        - Taux d'adoption (adoptants/connus) — KPI clé : {$pct($tauxAdoption)}

        ## Écoles ({$summary['total']} au total, {$summary['critical']} critiques)
        ### Meilleures écoles
        {$topSchools}
        ### Écoles critiques (à accompagner)
        {$criticalSchools}
        ### Plus fort potentiel de revenu
        {$topPotential}

        ## Opérations marketing (meilleures)
        {$campaigns}
        TXT;
    }
}
