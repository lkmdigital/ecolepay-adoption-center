<?php

namespace Database\Factories\Shared;

use App\Shared\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'ch_'.Str::lower(Str::random(6)),
            'label_fr' => 'Canal de test',
            'provider' => fake()->company(),
            'default_unit_cost' => 25.0000,
            'currency' => 'XOF',
            'max_message_length' => 160,
            'supports_rich_content' => false,
            'requires_opt_in' => false,
            'is_active' => true,
        ];
    }

    public function sms(): static
    {
        return $this->state(fn () => [
            'code' => 'sms',
            'label_fr' => 'SMS',
            'max_message_length' => 160,
        ]);
    }

    public function whatsapp(): static
    {
        return $this->state(fn () => [
            'code' => 'whatsapp',
            'label_fr' => 'WhatsApp',
            'supports_rich_content' => true,
            'requires_opt_in' => true,
        ]);
    }
}
