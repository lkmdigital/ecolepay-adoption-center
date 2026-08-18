<?php

namespace App\Infrastructure\EcolePay;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * Garde-fou matériel : force la connexion EcolePay en LECTURE SEULE.
 *
 * Les identifiants EcolePay du .env peuvent avoir tous les privilèges MySQL
 * (l'hébergement mutualisé ne permet pas de créer un utilisateur SELECT-only).
 * Ce garde intercepte donc CHAQUE requête AVANT son exécution et rejette tout
 * ce qui n'est pas une lecture. Conséquence : EAC est physiquement incapable
 * d'écrire, modifier, supprimer ou altérer quoi que ce soit dans EcolePay,
 * même en cas de bug ou de modification future du code.
 *
 * Branché sur l'évènement ConnectionEstablished dans AppServiceProvider, il
 * s'applique à chaque (re)connexion de la source.
 */
final class ReadOnlyGuard
{
    /**
     * Seuls ces verbes SQL (lecture) sont autorisés sur la source.
     */
    private const READ_ONLY = '/^\(*\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i';

    public static function protect(Connection $connection): void
    {
        $connection->beforeExecuting(static function (string $query) use ($connection): void {
            if (preg_match(self::READ_ONLY, ltrim($query)) === 1) {
                return;
            }

            throw new ReadOnlyViolationException(sprintf(
                'EcolePay est en LECTURE SEULE : requête d’écriture bloquée par EAC sur la connexion "%s" → %s',
                $connection->getName(),
                Str::limit(trim($query), 200),
            ));
        });
    }
}
