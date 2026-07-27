<?php

namespace Database\Factories\Campaigns;

use App\Domains\Campaigns\Enums\CampaignChannel;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        $name = 'Relance '.fake()->monthName();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'owner' => fake()->name(),
            'channel' => CampaignChannel::WhatsApp,
            'status' => CampaignStatus::Completed,
            'campaign_date' => now()->subDays(20)->toDateString(),
            'attribution_window_days' => 30,
        ];
    }

    public function planned(): static
    {
        return $this->state(fn () => ['status' => CampaignStatus::Planned, 'campaign_date' => null]);
    }
}
