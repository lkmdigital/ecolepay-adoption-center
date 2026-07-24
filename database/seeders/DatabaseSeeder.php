<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seule la donnée de référence est chargée par défaut.
 *
 * Les données de démonstration sont volontairement exclues : elles portent
 * `is_test = true` et n'ont rien à faire en production, même filtrées.
 * Les charger explicitement :
 *
 *     php artisan db:seed --class="Database\Seeders\DemoDataSeeder"
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);
    }
}
