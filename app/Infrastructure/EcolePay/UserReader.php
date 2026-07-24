<?php

namespace App\Infrastructure\EcolePay;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Lit les comptes parents `users` (identifiant de connexion = téléphone).
 *
 * Un compte fait passer un parent de « connu » à « inscrit ». Un même numéro peut
 * porter plusieurs comptes (un par école) : le rapprochement se fait par empreinte
 * du téléphone, pas par `id_user`.
 */
class UserReader
{
    public function __construct(protected readonly EcolePaySource $source) {}

    /**
     * @param  callable(Collection<int, object>): void  $callback
     */
    public function chunk(int $size, callable $callback): void
    {
        $this->query()->chunkById($size, fn ($rows) => $callback($rows), 'id_user', 'id_user');
    }

    public function count(): int
    {
        return $this->source->table('users')->count();
    }

    private function query(): Builder
    {
        return $this->source->table('users')
            ->select('id_user', 'id_ecole', 'nom', 'prenom', 'email', 'telephone', 'genre', 'date_ajout', 'status')
            ->orderBy('id_user');
    }
}
