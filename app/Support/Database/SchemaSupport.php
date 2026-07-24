<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aides de migration portables entre MySQL (exécution réelle) et SQLite
 * (suite de tests en mémoire).
 */
final class SchemaSupport
{
    public static function isMySql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    /**
     * Collation binaire pour les colonnes de code technique.
     *
     * La base est en utf8mb4_0900_ai_ci : « ECOLE » et « école » y sont
     * identiques, ce qui ferait entrer en collision deux codes distincts sur un
     * index unique. Retourne null hors MySQL, où le modificateur est alors
     * ignoré par Laravel.
     */
    public static function binaryCollation(): ?string
    {
        return self::isMySql() ? 'utf8mb4_bin' : null;
    }

    /**
     * Ajoute une contrainte CHECK, sans effet sur les moteurs qui ne savent pas
     * l'ajouter après création (SQLite).
     */
    public static function addCheck(string $table, string $name, string $expression): void
    {
        if (! self::isMySql()) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)',
            Schema::getConnection()->getTablePrefix().$table,
            $name,
            $expression,
        ));
    }
}
