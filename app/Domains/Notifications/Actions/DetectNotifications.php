<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Campaigns\Actions\ListCampaigns;
use App\Domains\Campaigns\Models\Campaign;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Schools\Actions\ListSchoolsForPilotage;
use Illuminate\Support\Facades\DB;

/**
 * Détecte automatiquement les alertes et événements depuis les données réelles et
 * les upserte par signature : on ne duplique jamais, et le statut lu/résolu d'une
 * alerte déjà vue est conservé d'une détection à l'autre.
 *
 * C'est le cœur « proactif » du centre de supervision : les problèmes sont trouvés
 * pour l'utilisateur, pas cherchés dans les tableaux de bord.
 */
final class DetectNotifications
{
    public function __construct(
        private readonly ListSchoolsForPilotage $schools,
        private readonly ListCampaigns $campaigns,
    ) {}

    public function __invoke(): void
    {
        $seen = [];
        foreach ($this->rules() as $n) {
            $this->upsert($n);
            $seen[] = $n['signature'];
        }

        // Les alertes auto-détectées qui ne sont plus vraies passent en résolues.
        Notification::query()->where('kind', 'alerte')->where('status', '!=', 'resolved')
            ->whereNotIn('signature', $seen)->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    /** @return list<array<string, mixed>> */
    private function rules(): array
    {
        $out = [];
        $schools = collect(($this->schools)()['rows']);

        // Écoles en adoption critique.
        foreach ($schools->filter(fn ($s) => $s['known'] >= 50 && $s['rate'] < 15)->sortByDesc('known')->take(5) as $s) {
            $out[] = $this->n('alerte', 'critique', 'ecoles', "ecole-critique-{$s['id']}",
                "Adoption critique — {$s['name']}", "Seulement {$this->pct($s['rate'])} d'adoption sur {$this->num($s['known'])} parents connus.",
                $s['potential'] > 0 ? "{$this->money((int) $s['potential'])} de potentiel bloqué" : 'Intervention prioritaire',
                'Contacter l\'établissement et lancer une campagne', 'schools.show', $s['id']);
        }

        // Inscriptions sans conversion (blocage au premier paiement).
        foreach ($schools->filter(function ($s) {
            $reg = $s['known'] > 0 ? $s['inscrits'] / $s['known'] * 100 : 0;
            $act = $s['inscrits'] > 0 ? $s['actifs'] / $s['inscrits'] * 100 : 0;

            return $s['inscrits'] >= 40 && $reg >= 55 && $act < 30;
        })->sortByDesc('inscrits')->take(3) as $s) {
            $out[] = $this->n('alerte', 'haute', 'ecoles', "ecole-leaky-{$s['id']}",
                "Inscriptions sans conversion — {$s['name']}", "{$this->num($s['inscrits'])} inscrits mais {$this->num($s['actifs'])} adoptants : blocage au premier paiement.",
                'Activation à renforcer', 'Relancer les inscrits inactifs', 'schools.show', $s['id']);
        }

        // Écoles à fort potentiel inexploité.
        foreach ($schools->filter(fn ($s) => $s['potential'] > 5_000_000)->sortByDesc('potential')->take(3) as $s) {
            $out[] = $this->n('alerte', 'moyenne', 'ecoles', "ecole-potentiel-{$s['id']}",
                "Fort potentiel inexploité — {$s['name']}", "{$this->num($s['known'] - $s['actifs'])} parents restent à convertir.",
                "{$this->money((int) $s['potential'])} de revenus potentiels", 'Prioriser une campagne ciblée', 'schools.show', $s['id']);
        }

        // Forte progression récente.
        foreach ($schools->filter(fn ($s) => $s['recent'] >= 25)->sortByDesc('recent')->take(3) as $s) {
            $out[] = $this->n('notification', 'faible', 'ecoles', "ecole-progression-{$s['id']}",
                "Forte progression — {$s['name']}", "{$this->num($s['recent'])} nouveaux adoptants sur les 90 derniers jours.",
                'Dynamique positive', 'Documenter les bonnes pratiques', 'schools.show', $s['id']);
        }

        // Écoles franchissant 80 % d'adoption.
        foreach ($schools->filter(fn ($s) => $s['rate'] >= 80 && $s['known'] >= 20)->take(3) as $s) {
            $out[] = $this->n('information', 'faible', 'ecoles', "ecole-excellente-{$s['id']}",
                "Adoption &gt; 80 % — {$s['name']}", "{$this->pct($s['rate'])} d'adoption : établissement de référence.",
                'Performance exemplaire', 'À valoriser en interne', 'schools.show', $s['id']);
        }

        // Campagnes.
        $campaigns = ($this->campaigns)()['rows'];
        foreach ($campaigns->filter(fn ($c) => $c['contacts'] >= 50 && $c['conversion'] < 1)->take(3) as $c) {
            $out[] = $this->n('alerte', 'moyenne', 'campagnes', "campagne-faible-{$c['id']}",
                "Campagne peu performante — {$c['name']}", "{$this->num($c['contacts'])} contacts, seulement {$this->pct($c['conversion'])} de conversion.",
                'Retour sur investissement faible', 'Revoir le ciblage / le message', 'campaigns.show', $c['id']);
        }
        foreach ($campaigns->filter(fn ($c) => $c['newPayments'] >= 10 && $c['conversion'] >= 2)->sortByDesc('conversion')->take(2) as $c) {
            $out[] = $this->n('notification', 'faible', 'campagnes', "campagne-forte-{$c['id']}",
                "Campagne performante — {$c['name']}", "{$this->num($c['newPayments'])} nouveaux adoptants ({$this->pct($c['conversion'])} de conversion).",
                'Bon retour', 'Répliquer sur d\'autres écoles', 'campaigns.show', $c['id']);
        }
        foreach (Campaign::query()->where('created_at', '>=', now()->subDays(30))->latest()->take(3)->get() as $c) {
            $out[] = $this->n('notification', 'faible', 'campagnes', "campagne-nouvelle-{$c->id}",
                "Nouvelle opération importée — {$c->name}", 'Une opération marketing a été ajoutée et mesurée.',
                null, 'Consulter l\'analyse', 'campaigns.show', $c->id);
        }

        // Nouveaux adoptants récents.
        $newAdopters = (int) DB::table('fact_parent_journeys')->where('is_test', false)
            ->where('first_payment_at', '>=', now()->subDays(30))->distinct()->count('parent_id');
        if ($newAdopters > 0) {
            $out[] = $this->n('notification', 'faible', 'parents', 'adoptants-recents-30j',
                "{$this->num($newAdopters)} nouveaux parents adoptants (30 j)", 'Des parents ont effectué leur premier paiement ce mois.',
                'Croissance de la base adoptante', 'Voir les parents', 'parents.index', null);
        }

        return $out;
    }

    private function n(string $kind, string $priority, string $module, string $signature, string $title, string $description, ?string $impact, string $action, ?string $route, ?int $param): array
    {
        return compact('kind', 'priority', 'module', 'signature', 'title', 'description', 'impact', 'action') + ['link_route' => $route, 'link_param' => $param];
    }

    private function upsert(array $data): void
    {
        $n = Notification::firstOrNew(['signature' => $data['signature']]);
        $isNew = ! $n->exists;
        $n->fill($data);
        $n->detected_at = now();
        if ($isNew) {
            $n->status = 'unread';
        }
        $n->save();
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
        return $n >= 1_000_000 ? number_format($n / 1_000_000, 1, ',', ' ').' M F' : $this->num($n).' F';
    }
}
