<?php

namespace Database\Seeders\Shared;

use App\Shared\Models\Channel;
use Illuminate\Database\Seeder;

/**
 * Canaux de communication.
 *
 * Les coûts unitaires sont indicatifs : le montant réellement facturé est figé sur
 * chaque ligne de `fact_campaign_contacts` à l'envoi.
 */
class ChannelSeeder extends Seeder
{
    public function run(): void
    {
        $currency = config('eac.currency');

        $channels = [
            [
                'code' => 'sms',
                'label_fr' => 'SMS',
                'provider' => null,
                'default_unit_cost' => 25.0000,
                'max_message_length' => 160,
                'supports_rich_content' => false,
                'requires_opt_in' => false,
            ],
            [
                'code' => 'whatsapp',
                'label_fr' => 'WhatsApp',
                'provider' => null,
                'default_unit_cost' => 15.0000,
                'max_message_length' => 1024,
                'supports_rich_content' => true,
                'requires_opt_in' => true,
            ],
            [
                'code' => 'email',
                'label_fr' => 'E-mail',
                'provider' => null,
                'default_unit_cost' => 1.0000,
                'max_message_length' => null,
                'supports_rich_content' => true,
                'requires_opt_in' => true,
            ],
            [
                'code' => 'push',
                'label_fr' => 'Notification push',
                'provider' => null,
                'default_unit_cost' => 0.0000,
                'max_message_length' => 178,
                'supports_rich_content' => false,
                'requires_opt_in' => true,
            ],
            [
                'code' => 'in_app',
                'label_fr' => 'Message intégré',
                'provider' => null,
                'default_unit_cost' => 0.0000,
                'max_message_length' => null,
                'supports_rich_content' => true,
                'requires_opt_in' => false,
            ],
        ];

        foreach ($channels as $channel) {
            Channel::query()->updateOrCreate(
                ['code' => $channel['code']],
                [...$channel, 'currency' => $currency, 'is_active' => true],
            );
        }
    }
}
