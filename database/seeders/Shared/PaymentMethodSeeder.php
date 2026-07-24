<?php

namespace Database\Seeders\Shared;

use App\Shared\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * Moyens de paiement.
 *
 * Une ligne « Inconnu » est créée ici — contrairement aux états d'adoption : la
 * source peut transmettre un opérateur non encore répertorié, et un paiement dont
 * la dimension serait NULL disparaîtrait de toute jointure interne. Le chiffre
 * deviendrait faux, et silencieusement.
 */
class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $country = config('eac.country_code');

        $methods = [
            [
                'code' => 'unknown',
                'label_fr' => 'Inconnu',
                'category' => 'unknown',
                'provider' => null,
                'country_code' => null,
                'is_digital' => false,
                'is_instant' => false,
            ],
            [
                'code' => 'orange_money',
                'label_fr' => 'Orange Money',
                'category' => 'mobile_money',
                'provider' => 'Orange',
                'country_code' => $country,
                'is_digital' => true,
                'is_instant' => true,
                'default_fee_percentage' => 0.0150,
            ],
            [
                'code' => 'mtn_money',
                'label_fr' => 'MTN Mobile Money',
                'category' => 'mobile_money',
                'provider' => 'MTN',
                'country_code' => $country,
                'is_digital' => true,
                'is_instant' => true,
                'default_fee_percentage' => 0.0150,
            ],
            [
                'code' => 'moov_money',
                'label_fr' => 'Moov Money',
                'category' => 'mobile_money',
                'provider' => 'Moov Africa',
                'country_code' => $country,
                'is_digital' => true,
                'is_instant' => true,
                'default_fee_percentage' => 0.0150,
            ],
            [
                'code' => 'wave',
                'label_fr' => 'Wave',
                'category' => 'mobile_money',
                'provider' => 'Wave',
                'country_code' => $country,
                'is_digital' => true,
                'is_instant' => true,
                'default_fee_percentage' => 0.0100,
            ],
            [
                'code' => 'card',
                'label_fr' => 'Carte bancaire',
                'category' => 'card',
                'provider' => null,
                'country_code' => null,
                'is_digital' => true,
                'is_instant' => true,
                'default_fee_percentage' => 0.0250,
            ],
            [
                'code' => 'bank_transfer',
                'label_fr' => 'Virement bancaire',
                'category' => 'bank_transfer',
                'provider' => null,
                'country_code' => null,
                'is_digital' => true,
                'is_instant' => false,
                'default_fee_fixed' => 500.00,
            ],
            [
                'code' => 'cash',
                'label_fr' => 'Espèces',
                'category' => 'cash',
                'provider' => null,
                'country_code' => null,
                'is_digital' => false,
                'is_instant' => true,
            ],
        ];

        foreach ($methods as $method) {
            PaymentMethod::query()->updateOrCreate(
                ['code' => $method['code'], 'country_code' => $method['country_code'] ?? null],
                [...$method, 'is_active' => $method['code'] !== 'unknown'],
            );
        }
    }
}
