<?php

namespace Database\Factories\Schools;

use App\Domains\Schools\Models\School;
use App\Domains\Schools\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    public function definition(): array
    {
        $levels = ['CP' => 1, 'CE1' => 2, 'CE2' => 3, 'CM1' => 4, 'CM2' => 5, '6e' => 6, '5e' => 7];
        $level = fake()->randomElement(array_keys($levels));

        return [
            'source_student_id' => 'STU-'.Str::upper(Str::random(8)),
            'school_id' => School::factory(),
            'display_reference' => 'MAT-'.fake()->numerify('######'),
            'education_level' => $level,
            // Rang ordinal : les nomenclatures ne se trient pas alphabétiquement.
            'level_rank' => $levels[$level],
            'class_label' => $level.' '.fake()->randomElement(['A', 'B', 'C']),
            'school_year_label' => '2025-2026',
            'enrollment_status' => 'enrolled',
            'enrolled_at' => now()->subMonths(10),
            'is_test' => false,
            'row_hash' => random_bytes(32),
            'synced_at' => now(),
        ];
    }

    public function test(): static
    {
        return $this->state(fn () => ['is_test' => true]);
    }
}
