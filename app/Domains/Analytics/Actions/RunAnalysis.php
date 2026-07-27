<?php

namespace App\Domains\Analytics\Actions;

use App\Domains\Campaigns\Actions\ListCampaigns;
use App\Domains\Schools\Actions\ListSchoolsForPilotage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moteur du Laboratoire d'Analyses : construit une analyse sans SQL à partir d'une
 * dimension et de mesures choisies. Chaque ligne = une valeur de la dimension avec
 * ses mesures. Les mesures indisponibles pour une dimension valent null.
 *
 * Vocabulaire unique : connu → inscrit → adoptant (⭐) → engagé.
 */
final class RunAnalysis
{
    /** @var array<string, array{label: string, available: bool, note?: string}> */
    public const DIMENSIONS = [
        'school' => ['label' => 'École', 'available' => true],
        'month' => ['label' => 'Mois', 'available' => true],
        'campaign' => ['label' => 'Campagne', 'available' => true],
        'level' => ['label' => 'Niveau scolaire', 'available' => false, 'note' => 'Libellés de classe non normalisés dans la source'],
        'region' => ['label' => 'Région', 'available' => false, 'note' => 'Géographie absente'],
        'city' => ['label' => 'Ville', 'available' => false, 'note' => 'Géographie absente'],
        'commercial' => ['label' => 'Commercial', 'available' => false, 'note' => 'Non renseigné'],
    ];

    /** @var array<string, array{label: string, format: string}> */
    public const MEASURES = [
        'connus' => ['label' => 'Parents connus', 'format' => 'int'],
        'inscrits' => ['label' => 'Parents inscrits', 'format' => 'int'],
        'adoptants' => ['label' => 'Parents adoptants', 'format' => 'int'],
        'engages' => ['label' => 'Parents engagés', 'format' => 'int'],
        'paiements' => ['label' => 'Paiements', 'format' => 'int'],
        'revenus' => ['label' => 'Revenus', 'format' => 'money'],
        'reg' => ['label' => "Taux d'inscription", 'format' => 'pct'],
        'act' => ['label' => "Taux d'activation", 'format' => 'pct'],
        'adopt' => ['label' => "Taux d'adoption", 'format' => 'pct'],
    ];

    public function __construct(
        private readonly ListSchoolsForPilotage $schools,
        private readonly ListCampaigns $campaigns,
    ) {}

    /**
     * @param  list<string>  $measures
     * @return list<array{label: string, id: ?int, values: array<string, float|int|null>}>
     */
    public function __invoke(string $dimension, array $measures): array
    {
        $rows = match ($dimension) {
            'month' => $this->byMonth(),
            'campaign' => $this->byCampaign(),
            default => $this->bySchool(),
        };

        // Ne conserver que les mesures demandées + dériver les taux.
        return array_map(function ($r) use ($measures) {
            $b = $r['base'];
            $full = $b + [
                'reg' => ($b['connus'] ?? 0) > 0 ? round(($b['inscrits'] ?? 0) / $b['connus'] * 100, 1) : null,
                'act' => ($b['inscrits'] ?? 0) > 0 ? round(($b['adoptants'] ?? 0) / $b['inscrits'] * 100, 1) : null,
                'adopt' => ($b['connus'] ?? 0) > 0 ? round(($b['adoptants'] ?? 0) / $b['connus'] * 100, 1) : null,
            ];
            $values = [];
            foreach ($measures as $m) {
                $values[$m] = $full[$m] ?? null;
            }

            return ['label' => $r['label'], 'id' => $r['id'] ?? null, 'values' => $values];
        }, $rows);
    }

    private function bySchool(): array
    {
        $schools = collect(($this->schools)()['rows']);
        $payCounts = DB::table('fact_payments')->where('is_test', false)->where('is_manual', false)->where('status', 'success')
            ->selectRaw('school_id, COUNT(*) as c')->groupBy('school_id')->pluck('c', 'school_id');

        return $schools->sortByDesc('actifs')->map(fn ($s) => [
            'label' => $s['name'], 'id' => $s['id'],
            'base' => ['connus' => $s['known'], 'inscrits' => $s['inscrits'], 'adoptants' => $s['actifs'], 'engages' => $s['engages'], 'paiements' => (int) ($payCounts[$s['id']] ?? 0), 'revenus' => $s['revenue']],
        ])->values()->all();
    }

    private function byMonth(): array
    {
        $mois = [1 => 'jan.', 2 => 'fév.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin', 7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.'];
        $since = Carbon::now()->startOfMonth()->subMonths(11);
        $inscrits = DB::table('dim_parents')->where('is_test', false)->whereNotNull('account_created_at')->where('account_created_at', '>=', $since)
            ->selectRaw("DATE_FORMAT(account_created_at,'%Y-%m') m, COUNT(*) n")->groupBy('m')->pluck('n', 'm');
        $adoptants = DB::table('fact_parent_journeys')->where('is_test', false)->whereNotNull('first_payment_at')->where('first_payment_at', '>=', $since)
            ->selectRaw("DATE_FORMAT(first_payment_at,'%Y-%m') m, COUNT(DISTINCT parent_id) n")->groupBy('m')->pluck('n', 'm');
        $engages = DB::table('fact_payments')->where('is_test', false)->where('is_manual', false)->where('status', 'success')->where('is_first_payment', false)->where('paid_at', '>=', $since)
            ->selectRaw("DATE_FORMAT(paid_at,'%Y-%m') m, COUNT(DISTINCT parent_id) n")->groupBy('m')->pluck('n', 'm');
        $pay = DB::table('fact_payments')->where('is_test', false)->where('is_manual', false)->where('status', 'success')->where('paid_at', '>=', $since)
            ->selectRaw("DATE_FORMAT(paid_at,'%Y-%m') m, COUNT(*) c, SUM(amount) v")->groupBy('m')->get()->keyBy('m');

        $out = [];
        $cursor = $since->copy();
        for ($i = 0; $i < 12; $i++) {
            $key = $cursor->format('Y-m');
            $out[] = ['label' => $mois[$cursor->month].' '.substr($cursor->year, 2), 'id' => null, 'base' => [
                'inscrits' => (int) ($inscrits[$key] ?? 0), 'adoptants' => (int) ($adoptants[$key] ?? 0), 'engages' => (int) ($engages[$key] ?? 0),
                'paiements' => (int) ($pay[$key]->c ?? 0), 'revenus' => (int) ($pay[$key]->v ?? 0),
            ]];
            $cursor->addMonth();
        }

        return $out;
    }

    private function byCampaign(): array
    {
        return collect(($this->campaigns)()['rows'])->map(fn ($c) => [
            'label' => $c['name'], 'id' => $c['id'],
            'base' => ['connus' => $c['contacts'], 'inscrits' => $c['newAccounts'], 'adoptants' => $c['newPayments'], 'paiements' => $c['newPayments'], 'revenus' => $c['revenue']],
        ])->values()->all();
    }
}
