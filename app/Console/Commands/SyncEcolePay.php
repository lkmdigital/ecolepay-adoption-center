<?php

namespace App\Console\Commands;

use App\Domains\Schools\Actions\SyncRoster;
use App\Domains\Schools\Actions\SyncSchools;
use App\Infrastructure\EcolePay\EcolePaySource;
use App\Infrastructure\Sync\Models\SyncRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Telescope\Telescope;

/**
 * Synchronise les données EcolePay vers l'entrepôt EAC.
 *
 * Chaque entité est tracée dans `sync_runs` (« données à jour au… »). Au fur et à
 * mesure du Sprint 2, on ajoute les entités suivantes (roster, comptes, paiements…).
 */
class SyncEcolePay extends Command
{
    protected $signature = 'eac:sync {entity=all : schools|roster|all}';

    protected $description = 'Synchronise les données EcolePay (lecture seule) vers l\'entrepôt EAC.';

    /**
     * Ordre de dépendance : le roster référence les écoles déjà synchronisées.
     *
     * @var array<string, class-string>
     */
    private const ENTITIES = [
        'schools' => SyncSchools::class,
        'roster' => SyncRoster::class,
    ];

    public function handle(EcolePaySource $source): int
    {
        // Telescope met en file EN MÉMOIRE chaque requête : sur une synchro de
        // dizaines de milliers de lignes, sa file épuise la mémoire du process.
        // On le coupe pour toute la durée de la commande.
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }

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
            $stats = $action($run);

            $run->forceFill([
                'status' => 'completed',
                'finished_at' => Carbon::now(),
                'duration_ms' => (int) $run->started_at->diffInMilliseconds(now()),
                'rows_read' => $stats['read'] ?? 0,
                // Somme des lignes écrites, quelle que soit l'entité.
                'rows_inserted' => array_sum(array_intersect_key(
                    $stats,
                    array_flip(['inserted', 'students', 'known_parents', 'links']),
                )),
                'rows_updated' => $stats['updated'] ?? 0,
                'rows_rejected' => $stats['rejected'] ?? 0,
                'watermark_to' => Carbon::now(),
            ])->save();

            // Sortie générique : une colonne par clé de statistiques.
            $this->table(array_keys($stats), [array_values($stats)]);
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
