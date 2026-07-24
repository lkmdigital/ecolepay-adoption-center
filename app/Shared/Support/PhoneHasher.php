<?php

namespace App\Shared\Support;

use App\Shared\ValueObjects\PhoneNumber;
use RuntimeException;

/**
 * Produit l'empreinte HMAC-SHA256 (32 octets) d'un téléphone normalisé.
 *
 * C'est la clé d'identité du parent dans `dim_parents`. Le secret est obligatoire :
 * sans lui, un hachage nu se casse par force brute et la pseudonymisation ne
 * protège plus rien.
 */
final class PhoneHasher
{
    private readonly string $key;

    public function __construct(?string $key = null)
    {
        $key ??= (string) config('eac.phone_hash_key');

        if ($key === '') {
            throw new RuntimeException('EAC_PHONE_HASH_KEY manquant : impossible de hacher les téléphones.');
        }

        // Supporte le format « base64:xxxx » à la manière d'APP_KEY.
        $this->key = str_starts_with($key, 'base64:')
            ? (base64_decode(substr($key, 7), true) ?: $key)
            : $key;
    }

    /**
     * @return string 32 octets bruts, à stocker en BINARY(32).
     */
    public function hash(PhoneNumber $phone): string
    {
        return hash_hmac('sha256', $phone->canonical, $this->key, true);
    }
}
