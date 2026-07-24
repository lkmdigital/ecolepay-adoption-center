<?php

namespace Database\Factories\Shared;

use App\Shared\Enums\AdoptionStageCode;
use App\Shared\Models\AdoptionStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdoptionStage>
 */
class AdoptionStageFactory extends Factory
{
    public function definition(): array
    {
        return $this->attributesFor(AdoptionStageCode::Known);
    }

    public function code(AdoptionStageCode $code): static
    {
        return $this->state(fn () => $this->attributesFor($code));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFor(AdoptionStageCode $code): array
    {
        return [
            'code' => $code->value,
            'label_fr' => $code->label(),
            'definition' => $code->label(),
            'funnel_rank' => $code->funnelRank(),
            'is_converted' => $code->isConverted(),
            'is_active_state' => $code->isActive(),
            'is_terminal' => $code === AdoptionStageCode::Lost,
            'is_derived' => $code->isDerived(),
            'display_color' => '#4F46E5',
        ];
    }
}
