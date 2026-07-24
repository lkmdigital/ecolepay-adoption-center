<?php

namespace Database\Factories\Campaigns;

use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\Campaign;
use App\Shared\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Relance '.fake()->monthName();

        return [
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'objective' => fake()->randomElement(['activation', 'reactivation', 'conversion', 'information']),
            'channel_id' => Channel::factory(),
            'target_segment' => ['stage' => 'registered', 'region' => 'Abidjan'],
            'message_template' => 'Bonjour, réglez vos frais de scolarité via EcolePay.',
            'status' => CampaignStatus::Draft,
            'currency' => 'XOF',
            'attribution_window_days' => 14,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Sent,
            'started_at' => now()->subDays(20),
            'completed_at' => now()->subDays(20),
            'recipient_count' => fake()->numberBetween(100, 5000),
        ]);
    }

    /**
     * Envoyée mais fenêtre d'attribution encore ouverte : le taux de conversion
     * n'est pas encore mesurable.
     */
    public function pendingAttribution(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Sent,
            'started_at' => now()->subDays(2),
            'completed_at' => now()->subDays(2),
            'recipient_count' => fake()->numberBetween(100, 5000),
        ]);
    }
}
