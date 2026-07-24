<?php

namespace Database\Factories\Schools;

use App\Domains\Schools\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_school_id' => 'SCH-'.Str::upper(Str::random(8)),
            'school_code' => Str::upper(Str::random(6)),
            'name' => 'École '.fake()->lastName(),
            'school_type' => fake()->randomElement(['public', 'prive', 'confessionnel']),
            'has_preschool' => fake()->boolean(40),
            'has_primary' => true,
            'has_secondary' => fake()->boolean(60),
            'country_code' => 'CI',
            'region' => fake()->randomElement(['Abidjan', 'Yamoussoukro', 'Bouaké', 'San-Pédro']),
            'city' => fake()->city(),
            'student_count' => fake()->numberBetween(80, 2400),
            'onboarded_at' => fake()->dateTimeBetween('-3 years', '-1 month'),
            'contract_tier' => fake()->randomElement(['standard', 'premium']),
            'status' => 'active',
            'is_test' => false,
            'is_current' => true,
            // Décalé aléatoirement : l'unicité porte sur (source, valid_from), et
            // deux versions successives de la même école ne peuvent pas partager
            // la même date de début.
            'valid_from' => now()->subYear()->subMinutes(fake()->numberBetween(0, 500_000)),
            'valid_to' => null,
            'version' => 1,
            'row_hash' => random_bytes(32),
            'synced_at' => now(),
        ];
    }

    /**
     * Version close. `is_current` doit valoir NULL, jamais false : un 0 stocké
     * entrerait en collision sur l'index unique.
     */
    public function historical(): static
    {
        return $this->state(fn () => [
            'is_current' => null,
            'valid_to' => now()->subMonth(),
        ]);
    }

    public function test(): static
    {
        return $this->state(fn () => ['is_test' => true]);
    }
}
