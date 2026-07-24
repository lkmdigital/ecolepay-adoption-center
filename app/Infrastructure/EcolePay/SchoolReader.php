<?php

namespace App\Infrastructure\EcolePay;

use Illuminate\Support\LazyCollection;

/**
 * Lit les écoles depuis `tb_ecole`.
 *
 * `tb_ecole.abonnement` porte le modèle : 0 = école prend en charge (intégré à la
 * scolarité), > 0 = montant payé par le parent. La source ne fournit ni ville ni
 * région : la géographie reste saisie dans EAC.
 */
class SchoolReader
{
    public function __construct(protected readonly EcolePaySource $source) {}

    /**
     * Parcours mémoire-efficace de toutes les écoles.
     *
     * @return LazyCollection<int, object>
     */
    public function all(): LazyCollection
    {
        return $this->source->table('tb_ecole')
            ->select('id', 'code', 'nom', 'email', 'abonnement', 'actif', 'date_add')
            ->orderBy('id')
            ->cursor();
    }

    public function count(): int
    {
        return $this->source->table('tb_ecole')->count();
    }
}
