<?php

namespace App\Console\Commands;

use App\Domains\Parents\Actions\BuildParentJourneys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Telescope\Telescope;

/**
 * Calcule les parcours d'adoption à partir des faits synchronisés, puis affiche
 * l'entonnoir et les trois taux clés.
 *
 * À lancer après `eac:sync` (schools, roster, accounts, payments).
 */
class ComputeAdoption extends Command
{
    protected $signature = 'eac:compute {what=journeys : journeys}';

    protected $description = 'Calcule les parcours d\'adoption et les KPI (3 taux).';

    public function handle(): int
    {
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }

        $this->info('Calcul des parcours…');
        $stats = app(BuildParentJourneys::class)();

        $this->table(
            ['parcours', 'connus', 'inscrits', 'adoptants', 'engagés', 'à risque', 'perdus'],
            [[
                $stats['journeys'], $stats['connus'], $stats['inscrits'], $stats['adoptants'],
                $stats['engages'], $stats['a_risque'], $stats['perdus'],
            ]],
        );

        $this->rates();

        return self::SUCCESS;
    }

    /**
     * Les trois taux, au niveau parent (dédupliqué), sur données de production.
     */
    private function rates(): void
    {
        $connus = (int) DB::table('dim_parents')->where('is_test', false)->count();
        $inscrits = (int) DB::table('dim_parents')->where('is_test', false)->whereNotNull('account_created_at')->count();
        $adoptants = (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('has_ever_paid', true)->distinct()->count('parent_id');

        $pct = fn (int $n, int $d): string => $d > 0 ? number_format($n / $d * 100, 1).' %' : '—';

        $this->newLine();
        $this->line('  <fg=cyan>Les 3 taux (niveau parent)</>');
        $this->table(
            ['Indicateur', 'Calcul', 'Valeur'],
            [
                ["Taux d'inscription", "inscrits / connus  ({$inscrits} / {$connus})", $pct($inscrits, $connus)],
                ["Taux d'adoption ★", "adoptants / connus  ({$adoptants} / {$connus})", $pct($adoptants, $connus)],
                ["Taux d'activation", "adoptants / inscrits  ({$adoptants} / {$inscrits})", $pct($adoptants, $inscrits)],
            ],
        );
    }
}
