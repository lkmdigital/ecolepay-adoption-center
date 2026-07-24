<?php

namespace App\Shared\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Historisation de type 2, avec l'astuce du NULL.
 *
 * MySQL n'applique pas l'unicité aux NULL : `is_current` vaut 1 pour la ligne
 * courante et NULL pour les versions closes. Un index unique sur
 * (clé source, is_current) garantit alors une seule ligne courante.
 *
 * Conséquence : clôturer une version passe `is_current` à NULL, **jamais à false**.
 * Un 0 stocké entrerait en collision sur l'index dès la deuxième clôture.
 */
trait HasCurrentVersion
{
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNotNull($query->qualifyColumn('is_current'));
    }

    public function scopeHistorical(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('is_current'));
    }

    /**
     * Version en vigueur à une date donnée.
     */
    public function scopeAsOf(Builder $query, Carbon|string $moment): Builder
    {
        return $query
            ->where($query->qualifyColumn('valid_from'), '<=', $moment)
            ->where(fn (Builder $inner) => $inner
                ->whereNull($query->qualifyColumn('valid_to'))
                ->orWhere($query->qualifyColumn('valid_to'), '>', $moment));
    }

    /**
     * Clôture la version courante. Le NULL est impératif, pas un détail de style.
     */
    public function closeVersion(Carbon|string $validTo): bool
    {
        return $this->forceFill([
            'is_current' => null,
            'valid_to' => $validTo,
        ])->save();
    }

    public function isCurrentVersion(): bool
    {
        return $this->is_current !== null;
    }
}
