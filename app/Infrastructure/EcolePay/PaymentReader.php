<?php

namespace App\Infrastructure\EcolePay;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Lit les paiements `payer`.
 *
 * `is_manuel = 1` (espèces/chèque saisis via le module comptable) est conservé mais
 * exclu de tous les calculs. Le montant se décompose : montant_initial (frais
 * scolaires) + abonnement (part EcolePay) + frais de transaction.
 */
class PaymentReader
{
    public function __construct(protected readonly EcolePaySource $source) {}

    /**
     * @param  callable(Collection<int, object>): void  $callback
     */
    public function chunk(int $size, callable $callback): void
    {
        $this->query()->chunkById($size, fn ($rows) => $callback($rows), 'id');
    }

    public function count(): int
    {
        return $this->source->table('payer')->count();
    }

    private function query(): Builder
    {
        return $this->source->table('payer')
            ->select(
                'id', 'id_ecole', 'id_eleve', 'id_user', 'mode_paiement',
                'montant_initial', 'montant', 'abonnement', 'reference',
                'date_transaction', 'statut', 'type', 'annee_scolaire', 'is_manuel',
            )
            ->orderBy('id');
    }
}
