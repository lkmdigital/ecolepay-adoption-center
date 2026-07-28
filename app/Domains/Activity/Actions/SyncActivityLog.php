<?php

namespace App\Domains\Activity\Actions;

use App\Domains\Activity\Models\ActivityLog;
use App\Domains\Campaigns\Actions\ListCampaigns;
use App\Domains\Campaigns\Models\Campaign;
use App\Domains\Reports\Models\Report;
use App\Domains\Schools\Actions\ListSchoolsForPilotage;
use Illuminate\Support\Facades\DB;

/**
 * Alimente le journal d'activité, idempotent par signature :
 *  - « métier » : jalons de l'adoption détectés depuis les données (paliers
 *    d'adoption, caps de campagne, seuils de revenus, écoles critiques, chute
 *    des premiers paiements) ;
 *  - « technique » : reconstruit depuis les vraies traces de l'app (rapports
 *    générés, opérations importées, analyses enregistrées, alertes résolues).
 *
 * Aucune donnée inventée : tout provient de l'entrepôt ou des enregistrements
 * réels. L'acteur reste « Système » tant que l'authentification n'est pas posée.
 */
final class SyncActivityLog
{
    private const REVENUE_STEPS = [500_000_000, 1_000_000_000, 2_000_000_000, 3_000_000_000];

    public function __construct(
        private readonly ListSchoolsForPilotage $schools,
        private readonly ListCampaigns $campaigns,
    ) {}

    public function __invoke(): void
    {
        $this->businessEvents();
        $this->technicalBackfill();
    }

    private function businessEvents(): void
    {
        $schools = collect(($this->schools)()['rows']);

        // Paliers d'adoption franchis (on retient le plus haut atteint par école).
        foreach ($schools->filter(fn ($s) => $s['known'] >= 20) as $s) {
            $threshold = collect([80, 70, 50])->first(fn ($t) => $s['rate'] >= $t);
            if ($threshold) {
                $this->log("metier-palier-{$s['id']}-{$threshold}", 'metier', $threshold >= 70 ? 'info' : 'info', 'ecoles', 'milestone',
                    "{$s['name']} franchit {$threshold} % d'adoption", "L'établissement atteint {$this->pct($s['rate'])} de parents adoptants.", 'schools.show', $s['id']);
            }
        }

        // Écoles devenues critiques.
        foreach ($schools->filter(fn ($s) => $s['known'] >= 50 && $s['rate'] < 15) as $s) {
            $this->log("metier-critique-{$s['id']}", 'metier', 'critique', 'ecoles', 'alerte',
                "{$s['name']} en situation critique", "Adoption tombée à {$this->pct($s['rate'])} sur {$this->num($s['known'])} parents connus.", 'schools.show', $s['id']);
        }

        // Campagnes atteignant un cap d'adoptants.
        foreach (($this->campaigns)()['rows'] as $c) {
            $cap = collect([100, 50, 25])->first(fn ($t) => $c['newPayments'] >= $t);
            if ($cap) {
                $this->log("metier-campagne-{$c['id']}-{$cap}", 'metier', 'info', 'campagnes', 'milestone',
                    "{$c['name']} atteint {$cap} parents adoptants", "L'opération a généré {$this->num($c['newPayments'])} premiers paiements.", 'campaigns.show', $c['id']);
            }
        }

        // Seuils de revenus franchis.
        $revenue = (int) DB::table('fact_payments')->where('is_test', false)->where('is_manual', false)->where('status', 'success')->sum('amount');
        foreach (self::REVENUE_STEPS as $step) {
            if ($revenue >= $step) {
                $this->log("metier-revenu-{$step}", 'metier', 'info', 'revenus', 'milestone',
                    'Seuil de revenus franchi : '.$this->money($step), 'Le volume cumulé payé via l\'app a dépassé ce palier.', null, null);
            }
        }

        // Chute des premiers paiements (30 j vs 30 j précédents).
        $cur = (int) DB::table('fact_parent_journeys')->where('is_test', false)->whereBetween('first_payment_at', [now()->subDays(30), now()])->distinct()->count('parent_id');
        $prev = (int) DB::table('fact_parent_journeys')->where('is_test', false)->whereBetween('first_payment_at', [now()->subDays(60), now()->subDays(30)])->distinct()->count('parent_id');
        if ($prev > 0 && ($cur - $prev) / $prev <= -0.2) {
            $drop = round(abs(($cur - $prev) / $prev) * 100);
            $this->log('metier-chute-'.now()->format('Y-m'), 'metier', 'critique', 'parents', 'alerte',
                "Chute de {$drop} % des premiers paiements", "Sur 30 jours : {$this->num($cur)} nouveaux adoptants contre {$this->num($prev)} sur la période précédente.", 'analytics.index', null);
        }
    }

    private function technicalBackfill(): void
    {
        foreach (Report::query()->latest()->take(50)->get() as $r) {
            $this->log("tech-report-{$r->id}", 'technique', 'info', 'rapports', 'creation',
                "Rapport « {$r->name} » généré", 'Génération d\'un rapport d\'analyse.', 'reports.show', $r->id, $r->created_at);
        }
        foreach (Campaign::query()->latest()->take(50)->get() as $c) {
            $this->log("tech-campaign-{$c->id}", 'technique', 'info', 'campagnes', 'import',
                "Opération « {$c->name} » importée", $c->valid_count ? $this->num($c->valid_count).' contacts valides enregistrés.' : 'Opération marketing enregistrée.', 'campaigns.show', $c->id, $c->created_at);
        }
        if (DB::getSchemaBuilder()->hasTable('saved_analyses')) {
            foreach (DB::table('saved_analyses')->latest()->take(50)->get() as $a) {
                $this->log("tech-analysis-{$a->id}", 'technique', 'info', 'analytics', 'creation',
                    "Analyse « {$a->name} » enregistrée", 'Vue personnalisée créée dans le Laboratoire.', 'analytics.lab', null, $a->created_at);
            }
        }
        if (DB::getSchemaBuilder()->hasTable('eac_notifications')) {
            foreach (DB::table('eac_notifications')->where('status', 'resolved')->latest('resolved_at')->take(50)->get() as $n) {
                $this->log("tech-notif-{$n->id}", 'technique', 'info', 'notifications', 'modification',
                    'Alerte résolue', $n->title, null, null, $n->resolved_at);
            }
        }
    }

    private function log(string $signature, string $category, string $level, string $module, string $action, string $title, string $description, ?string $route, ?int $param, $occurredAt = null): void
    {
        $log = ActivityLog::firstOrNew(['signature' => $signature]);
        $log->fill([
            'category' => $category, 'level' => $level, 'module' => $module, 'action' => $action,
            'title' => $title, 'description' => $description, 'link_route' => $route,
            'resource_id' => $param, 'result' => 'success', 'actor' => 'Système',
        ]);
        if (! $log->exists) {
            $log->occurred_at = $occurredAt ?? now();
        }
        $log->save();
    }

    private function num($n): string
    {
        return number_format((float) $n, 0, ',', ' ');
    }

    private function pct($n): string
    {
        return number_format((float) $n, 1, ',', ' ').' %';
    }

    private function money($n): string
    {
        return $n >= 1_000_000 ? number_format($n / 1_000_000, 0, ',', ' ').' M F' : $this->num($n).' F';
    }
}
