<?php

namespace App\Domains\Reports\Actions;

use App\Domains\Analytics\Actions\ComputeAnalytics;
use App\Domains\Reports\Models\Report;
use App\Domains\Schools\Actions\ComputeSchoolProfile;
use App\Domains\Schools\Models\School;

/**
 * Construit le contenu d'un rapport à la volée depuis les données réelles, dans une
 * structure normalisée que le lecteur rend en HTML (imprimable en PDF).
 *
 * Réutilise ComputeAnalytics (vue agrégée) et ComputeSchoolProfile (par école).
 * Vocabulaire unique : connu → inscrit → adoptant (⭐) → engagé.
 */
final class BuildReport
{
    /** @var array<string, array{label: string, desc: string, icon: string, available: bool, note?: string}> */
    public const TEMPLATES = [
        'executif' => ['label' => 'Rapport Exécutif Intelligent', 'desc' => 'Synthèse complète pour la Direction, générée en un clic.', 'icon' => 'star', 'available' => true],
        'adoption' => ['label' => 'Adoption globale', 'desc' => "Résumé exécutif de l'adoption.", 'icon' => 'target', 'available' => true],
        'ecole' => ['label' => 'Rapport par école', 'desc' => "Analyse détaillée d'un établissement.", 'icon' => 'school', 'available' => true],
        'marketing' => ['label' => 'Rapport marketing', 'desc' => 'Impact des campagnes et opérations.', 'icon' => 'megaphone', 'available' => true],
        'financier' => ['label' => 'Rapport financier', 'desc' => 'Paiements et revenus.', 'icon' => 'money', 'available' => true],
        'personnalise' => ['label' => 'Rapport personnalisé', 'desc' => 'Choisissez librement les indicateurs.', 'icon' => 'sliders', 'available' => true],
        'commercial' => ['label' => 'Rapport commercial', 'desc' => 'Performance par commercial.', 'icon' => 'users', 'available' => false, 'note' => 'Responsable commercial non renseigné'],
        'regional' => ['label' => 'Rapport régional', 'desc' => 'Analyse par région ou ville.', 'icon' => 'map', 'available' => false, 'note' => 'Géographie absente'],
    ];

    public const PERIODS = ['7d' => '7 derniers jours', '30d' => '30 derniers jours', 'this_year' => 'Cette année', 'school_year' => 'Année scolaire'];

    public function __construct(
        private readonly ComputeAnalytics $analytics,
        private readonly ComputeSchoolProfile $schoolProfile,
    ) {}

    public function __invoke(Report $report): array
    {
        $meta = [
            'title' => $report->name,
            'template' => self::TEMPLATES[$report->type]['label'] ?? 'Rapport',
            'type' => $report->type,
            'periodLabel' => self::PERIODS[$report->period] ?? 'Année scolaire',
            'filterLabel' => $this->filterLabel($report),
            'generatedAt' => now(),
        ];

        $content = match ($report->type) {
            'ecole' => $this->ecole($report),
            'marketing' => $this->marketing($report),
            'financier' => $this->financier($report),
            'personnalise' => $this->personnalise($report),
            'adoption' => $this->adoption($report),
            default => $this->executif($report),
        };

        return ['meta' => $meta] + $content;
    }

    private function executif(Report $r): array
    {
        $a = ($this->analytics)($r->period, $r->subscription_model ?? '', $r->school_id);
        $kpi = collect($a['kpis'])->keyBy('key');
        $growth = $kpi['growth']['value'] ?? null;

        $best = collect($a['comparison'])->sortByDesc('adoption')->first();

        $summary = 'Le taux d\'adoption s\'établit à '.$this->pct($kpi['adopt']['value']).' ('.$this->fr($a['funnel']['stages'][2]['value']).' parents adoptants sur '.$this->fr($a['funnel']['stages'][0]['value']).' connus)'
            .($growth !== null ? ', avec une croissance de '.($growth >= 0 ? '+' : '').$this->fr($growth).' % sur 30 jours' : '').'. '
            .($best ? 'La meilleure performance provient de '.$best['name'].' ('.$this->pct($best['adoption']).' d\'adoption). ' : '')
            .'Il reste '.$this->money((int) ($kpi['pot']['value'] ?? 0)).' de potentiel d\'abonnement à convertir.';

        return [
            'summary' => $summary,
            'kpis' => $this->kpiList($a),
            'sections' => array_values(array_filter([
                $this->funnelSection($a['funnel']),
                $this->schoolTable('Meilleures et moins bonnes écoles', collect($a['comparison'])->take(6)),
                $this->campaignSection($a['campaigns']),
                $this->opportunitySection($a['revenue']),
                $this->anomalySection($a['anomalies']),
            ])),
            'reco' => $a['recommendations'],
            'conclusion' => $this->conclusion($a),
        ];
    }

    private function adoption(Report $r): array
    {
        $a = ($this->analytics)($r->period, $r->subscription_model ?? '', $r->school_id);

        return [
            'summary' => 'Vue d\'ensemble de l\'adoption : '.$this->pct(collect($a['kpis'])->firstWhere('key', 'adopt')['value']).' d\'adoption, '
                .$this->pct(collect($a['kpis'])->firstWhere('key', 'reg')['value']).' d\'inscription et '.$this->pct(collect($a['kpis'])->firstWhere('key', 'act')['value']).' d\'activation.',
            'kpis' => $this->kpiList($a),
            'sections' => [$this->funnelSection($a['funnel'])],
            'reco' => $a['recommendations'],
            'conclusion' => $this->conclusion($a),
        ];
    }

    private function ecole(Report $r): array
    {
        $p = $r->school_id ? ($this->schoolProfile)($r->school_id) : null;
        if (! $p) {
            return ['summary' => 'Aucune école sélectionnée pour ce rapport.', 'kpis' => [], 'sections' => [], 'reco' => [], 'conclusion' => ''];
        }
        $k = $p['kpis'];

        return [
            'summary' => $p['diagnostic']['text'],
            'kpis' => [
                ['label' => 'Parents connus', 'value' => $this->fr($k['known'])],
                ['label' => 'Parents inscrits', 'value' => $this->fr($k['inscrits'])],
                ['label' => 'Parents adoptants', 'value' => $this->fr($k['actifs'])],
                ['label' => "Taux d'adoption", 'value' => $this->pct($k['rate'])],
                ['label' => 'Score de santé', 'value' => $p['health']['score'].'/100'],
                ['label' => 'Revenus', 'value' => $this->money($k['revenue'])],
            ],
            'sections' => [['title' => "Entonnoir d'adoption", 'kind' => 'funnel', 'data' => array_map(fn ($s) => ['label' => $s['label'], 'value' => $s['value'], 'conv' => $s['conv']], $p['funnel'])]],
            'reco' => collect($p['opportunities']['actions'])->map(fn ($x) => ['title' => $x['title'], 'why' => $x['why'], 'priority' => 'moyenne'])->all(),
            'conclusion' => 'Priorité : '.$p['diagnostic']['lever'].'.',
        ];
    }

    private function marketing(Report $r): array
    {
        $a = ($this->analytics)($r->period, $r->subscription_model ?? '', $r->school_id);
        $c = $a['campaigns'];

        return [
            'summary' => count($c['ranking']) ? 'Analyse des campagnes : le canal le plus performant est '.($c['byChannel'][0]['channel'] ?? '—').' ('.$this->pct($c['byChannel'][0]['conversion'] ?? 0).' de conversion). Délai moyen campagne → premier paiement : '.($c['avgDaysToPayment'] ?? '—').' jours.' : 'Aucune campagne mesurée sur la période.',
            'kpis' => [],
            'sections' => array_values(array_filter([$this->campaignSection($c)])),
            'reco' => collect($a['recommendations'])->take(2)->all(),
            'conclusion' => 'Concentrer les prochains efforts sur les canaux et opérations à meilleure conversion vers le premier paiement.',
        ];
    }

    private function financier(Report $r): array
    {
        $a = ($this->analytics)($r->period, $r->subscription_model ?? '', $r->school_id);
        $rev = $a['revenue'];
        $kpi = collect($a['kpis'])->keyBy('key');

        return [
            'summary' => 'Revenus générés et potentiel restant. Revenu moyen par adoptant : '.$this->money((int) ($kpi['arpa']['value'] ?? 0)).'. Potentiel d\'abonnement restant : '.$this->money((int) ($kpi['pot']['value'] ?? 0)).'.',
            'kpis' => [
                ['label' => 'Revenu moyen / adoptant', 'value' => $this->money((int) ($kpi['arpa']['value'] ?? 0))],
                ['label' => 'Potentiel restant', 'value' => $this->money((int) ($kpi['pot']['value'] ?? 0))],
                ['label' => 'Prévision revenus (mois +1)', 'value' => $rev['forecast'].' M F'],
            ],
            'sections' => [
                ['title' => 'Revenus par mode d\'abonnement', 'kind' => 'table', 'columns' => ['Mode', 'Revenus'], 'rows' => collect($rev['bySubscription'])->map(fn ($s) => [$s['label'], $this->money((int) $s['value'])])->all()],
                ['title' => 'Top écoles par revenus', 'kind' => 'table', 'columns' => ['École', 'Revenus'], 'rows' => collect($rev['bySchool'])->map(fn ($s) => [$s['name'], $this->money((int) $s['value'])])->all()],
            ],
            'reco' => collect($a['recommendations'])->take(2)->all(),
            'conclusion' => 'Le revenu d\'abonnement dormant représente le principal levier de croissance à court terme.',
        ];
    }

    private function personnalise(Report $r): array
    {
        $a = ($this->analytics)($r->period, $r->subscription_model ?? '', $r->school_id);
        $indicators = $r->indicators ?: ['adopt', 'reg', 'act'];
        $map = collect($a['kpis'])->keyBy('key');
        $labels = ['connus' => 'Parents connus', 'inscrits' => 'Parents inscrits', 'adoptants' => 'Parents adoptants', 'eng' => 'Parents engagés', 'reg' => "Taux d'inscription", 'act' => "Taux d'activation", 'adopt' => "Taux d'adoption", 'arpa' => 'Revenu / adoptant', 'pot' => 'Potentiel restant'];

        $kpis = [];
        foreach ($indicators as $key => $ind) {
            $k = is_string($ind) ? $ind : $key;
            if ($m = $map[$k] ?? null) {
                $kpis[] = ['label' => $m['label'], 'value' => $m['format'] === 'pct' ? $this->pct($m['value']) : ($m['format'] === 'money' ? $this->money((int) $m['value']) : $this->fr($m['value']))];
            }
        }

        return [
            'summary' => 'Rapport personnalisé sur les indicateurs sélectionnés.',
            'kpis' => $kpis,
            'sections' => [$this->funnelSection($a['funnel'])],
            'reco' => collect($a['recommendations'])->take(2)->all(),
            'conclusion' => '',
        ];
    }

    /* --------------------------------------------------------- Sections */

    private function kpiList(array $a): array
    {
        $m = collect($a['kpis'])->keyBy('key');

        return [
            ['label' => "Taux d'inscription", 'value' => $this->pct($m['reg']['value'])],
            ['label' => "Taux d'activation", 'value' => $this->pct($m['act']['value'])],
            ['label' => "Taux d'adoption", 'value' => $this->pct($m['adopt']['value'])],
            ['label' => 'Parents engagés', 'value' => $this->fr($m['eng']['value'])],
            ['label' => 'Revenu / adoptant', 'value' => $this->money((int) $m['arpa']['value'])],
            ['label' => 'Potentiel restant', 'value' => $this->money((int) $m['pot']['value'])],
        ];
    }

    private function funnelSection(array $funnel): array
    {
        return ['title' => "Entonnoir d'adoption", 'kind' => 'funnel', 'data' => array_map(fn ($s) => ['label' => $s['label'], 'value' => $s['value'], 'conv' => $s['conv']], $funnel['stages'])];
    }

    private function schoolTable(string $title, $schools): array
    {
        return [
            'title' => $title,
            'kind' => 'table',
            'columns' => ['École', 'Adoption', 'Engagés', 'Santé'],
            'rows' => $schools->map(fn ($s) => [$s['name'], $this->pct($s['adoption']), $this->fr($s['engages']), $s['health'].'/100'])->all(),
        ];
    }

    private function campaignSection(array $campaigns): array
    {
        if (empty($campaigns['ranking'])) {
            return [];
        }

        return ['title' => 'Campagnes les plus performantes', 'kind' => 'table', 'columns' => ['Campagne', 'Contacts', 'Adoptants', 'Conversion'],
            'rows' => collect($campaigns['ranking'])->take(5)->map(fn ($c) => [$c['name'], $c['channel']->isContactBased() ? $this->fr($c['contacts']) : '—', $this->fr($c['newPayments']), $this->pct($c['conversion'])])->all()];
    }

    private function opportunitySection(array $revenue): array
    {
        return ['title' => 'Opportunités de revenus', 'kind' => 'table', 'columns' => ['École', 'Revenus'],
            'rows' => collect($revenue['bySchool'])->take(5)->map(fn ($s) => [$s['name'], $this->money((int) $s['value'])])->all()];
    }

    private function anomalySection(array $anomalies): array
    {
        if (empty($anomalies)) {
            return [];
        }

        return ['title' => 'Anomalies détectées', 'kind' => 'list', 'items' => collect($anomalies)->map(fn ($x) => $x['title'].' — '.$x['detail'])->all()];
    }

    private function conclusion(array $a): string
    {
        $urgent = collect($a['anomalies'])->count();

        return 'En synthèse, l\'adoption progresse mais le potentiel restant ('.$this->money((int) collect($a['kpis'])->firstWhere('key', 'pot')['value']).') justifie des actions ciblées'
            .($urgent > 0 ? ', notamment sur les établissements signalés en anomalie' : '').'. Les recommandations prioritaires ci-dessus indiquent les leviers les plus rentables.';
    }

    /* ------------------------------------------------------------ Outils */

    private function filterLabel(Report $r): string
    {
        $parts = [];
        if ($r->school_id) {
            $parts[] = School::query()->whereKey($r->school_id)->value('name') ?? 'École';
        }
        if ($r->subscription_model) {
            $parts[] = $r->subscription_model === 'parent_paid' ? 'Abonnement parent' : 'Abonnement intégré';
        }

        return $parts ? implode(' · ', $parts) : 'Toutes écoles';
    }

    private function fr($n): string
    {
        return number_format((float) $n, 0, ',', ' ');
    }

    private function pct($n): string
    {
        return number_format((float) $n, 1, ',', ' ').' %';
    }

    private function money($n): string
    {
        return $n >= 1_000_000 ? number_format($n / 1_000_000, 1, ',', ' ').' M F' : $this->fr($n).' F';
    }
}
