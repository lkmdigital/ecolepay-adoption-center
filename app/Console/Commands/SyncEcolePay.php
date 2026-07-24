<?php

namespace App\Console\Commands;

use App\Domains\Schools\Actions\SyncSchools;
use App\Infrastructure\EcolePay\EcolePaySource;
use App\Infrastructure\Sync\Models\SyncRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Synchronise les données EcolePay vers l'entrepôt EAC.
 *
 * Chaque entité est tracée dans `sync_runs` (« données à jour au… »). Au fur et à
 * mesure du Sprint 2, on ajoute les entités suivantes (roster, comptes, paiements…).
 */
class SyncEcolePay extends Command
{
    protected $signature = 'eac:sync {entity=all : schools|all}';

    protected $description = 'Synchronise les données EcolePay (lecture seule) vers l\'entrepôt EAC.';

    /** @var array<string, class-string> */
    private const ENTITIES = [
        'schools' => SyncSchools::class,
    ];

    public function handle(EcolePaySource $source): int
    {
        if (! $source->isReachable()) {
            $this->error('Base EcolePay injoignable. Vérifie la connexion « ecolepay » (.env ECOLEPAY_DB_*).');

            return self::FAILURE;
        }

        $entities = $this->argument('entity') === 'all'
            ? array_keys(self::ENTITIES)
            : [$this->argument('entity')];

        foreach ($entities as $entity) {
            if (! isset(self::ENTITIES[$entity])) {
                $this->error("Entité inconnue : {$entity}. Disponibles : ".implode(', ', array_keys(self::ENTITIES)));

                return self::INVALID;
            }

            $this->runEntity($entity);
        }

        return self::SUCCESS;
    }

    private function runEntity(string $entity): void
    {
        $this->info("Synchronisation : {$entity}…");

        $run = SyncRun::query()->create([
            'source' => 'ecolepay',
            'entity' => $entity,
            'status' => 'running',
            'started_at' => Carbon::now(),
        ]);

        try {
            $action = app(self::ENTITIES[$entity]);
            $stats = $action();

            $run->forceFill([
                'status' => 'completed',
                'finished_at' => Carbon::now(),
                'duration_ms' => (int) $run->started_at->diffInMilliseconds(now()),
                'rows_read' => $stats['read'] ?? 0,
                'rows_inserted' => $stats['inserted'] ?? 0,
                'rows_updated' => $stats['updated'] ?? 0,
                'watermark_to' => Carbon::now(),
            ])->save();

            $this->table(
                ['lues', 'insérées', 'mises à jour', 'inchangées'],
                [[$stats['read'] ?? 0, $stats['inserted'] ?? 0, $stats['updated'] ?? 0, $stats['unchanged'] ?? 0]],
            );
        } catch (\Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => Carbon::now(),
                'error_message' => $e->getMessage(),
            ])->save();

            $this->error("Échec ({$entity}) : ".$e->getMessage());
        }
    }
}
