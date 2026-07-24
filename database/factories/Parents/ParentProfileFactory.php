<?php

namespace Database\Factories\Parents;

use App\Domains\Parents\Models\ParentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ParentProfile>
 */
class ParentProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_parent_id' => 'PAR-'.Str::upper(Str::random(8)),
            // Empreinte à clé en production ; ici une valeur aléatoire suffit.
            'phone_hash' => random_bytes(32),
            'phone_e164' => '+225'.fake()->numerify('##########'),
            'phone_country' => 'CI',
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'preferred_language' => 'fr',
            'first_known_at' => now()->subMonths(fake()->numberBetween(1, 24)),
            'account_created_at' => now()->subMonths(fake()->numberBetween(0, 12)),
            'last_platform' => fake()->randomElement(['android', 'ios', 'web']),
            'marketing_consent' => fake()->boolean(70),
            'is_pseudonymized' => false,
            'is_test' => false,
            'row_hash' => random_bytes(32),
            'synced_at' => now(),
        ];
    }

    /**
     * « Parent connu » : numéro présent dans la liste d'une école, sans compte
     * EcolePay. C'est le premier étage de l'entonnoir — l'oublier fausserait
     * le dénominateur de tous les taux de conversion.
     */
    public function withoutAccount(): static
    {
        return $this->state(fn () => [
            'source_parent_id' => null,
            'account_created_at' => null,
            'full_name' => null,
            'email' => null,
        ]);
    }

    public function pseudonymized(): static
    {
        return $this->state(fn () => [
            'full_name' => null,
            'email' => null,
            'phone_e164' => null,
            'is_pseudonymized' => true,
            'pseudonymized_at' => now(),
        ]);
    }
}
