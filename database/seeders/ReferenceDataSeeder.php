<?php

namespace Database\Seeders;

use Database\Seeders\Shared\AdoptionRuleVersionSeeder;
use Database\Seeders\Shared\AdoptionStageSeeder;
use Database\Seeders\Shared\CalendarSeeder;
use Database\Seeders\Shared\ChannelSeeder;
use Database\Seeders\Shared\EventTypeSeeder;
use Database\Seeders\Shared\PaymentMethodSeeder;
use Database\Seeders\Users\RolePermissionSeeder;
use Illuminate\Database\Seeder;

/**
 * Données de référence : indispensables au fonctionnement, dans tous les
 * environnements.
 *
 * L'ordre compte — la règle d'adoption référence les codes d'événements, qui
 * doivent donc exister d'abord.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            AdoptionStageSeeder::class,
            ChannelSeeder::class,
            PaymentMethodSeeder::class,
            EventTypeSeeder::class,
            AdoptionRuleVersionSeeder::class,
            CalendarSeeder::class,
        ]);
    }
}
