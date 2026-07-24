<?php

namespace App\Infrastructure\EcolePay;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Lit le roster scolaire `tb_lkmdigital` : les listes d'élèves fournies par les
 * écoles, avec jusqu'à deux numéros de parent (telephone, telephone2).
 *
 * C'est la source des « parents connus » : un numéro présent ici sans compte
 * EcolePay est un parent connu mais pas encore inscrit.
 */
class RosterReader
{
    public function __construct(protected readonly EcolePaySource $source) {}

    /**
     * @return LazyCollection<int, object>
     */
    public function all(): LazyCollection
    {
        return $this->query()->cursor();
    }

    /**
     * Traite le roster par lots (paginé par id) : mémoire bornée, indispensable sur
     * ~54 k lignes.
     *
     * @param  callable(Collection<int, object>): void  $callback
     */
    public function chunk(int $size, callable $callback): void
    {
        $this->query()->chunkById($size, fn ($rows) => $callback($rows), 'id');
    }

    public function count(): int
    {
        return $this->source->table('tb_lkmdigital')->count();
    }

    private function query(): Builder
    {
        return $this->source->table('tb_lkmdigital')
            ->select('id', 'id_ecole', 'matricule', 'classe', 'nom', 'prenom', 'telephone', 'telephone2')
            ->orderBy('id');
    }
}
