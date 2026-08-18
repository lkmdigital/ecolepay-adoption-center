<?php

namespace App\Infrastructure\EcolePay;

use RuntimeException;

/**
 * Levée quand une requête d'écriture est tentée sur la base EcolePay.
 *
 * EAC ne doit JAMAIS modifier la source : cette exception signale un garde-fou
 * déclenché (voir {@see ReadOnlyGuard}). Si elle apparaît, c'est un bug à
 * corriger côté EAC — jamais une action légitime.
 */
final class ReadOnlyViolationException extends RuntimeException
{
}
