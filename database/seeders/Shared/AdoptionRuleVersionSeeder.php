<?php

namespace Database\Seeders\Shared;

use App\Shared\Models\AdoptionRuleVersion;
use Illuminate\Database\Seeder;

/**
 * Première version de la règle produisant « à risque » et « perdu ».
 *
 * Ne crée la version initiale que si aucune n'existe : modifier les seuils suppose
 * de créer une **nouvelle** version, jamais d'écraser celle-ci. Sans quoi une
 * inflexion de courbe ne se distinguerait plus d'un changement de définition.
 */
class AdoptionRuleVersionSeeder extends Seeder
{
    public function run(): void
    {
        if (AdoptionRuleVersion::query()->exists()) {
            return;
        }

        AdoptionRuleVersion::query()->create([
            'version_label' => 'v1',
            'at_risk_after_days' => config('eac.adoption.at_risk_after_days'),
            'lost_after_days' => config('eac.adoption.lost_after_days'),
            'engaged_min_payments' => config('eac.adoption.engaged_min_payments'),
            'qualifying_event_types' => config('eac.adoption.qualifying_events'),
            'effective_from' => now()->startOfYear()->toDateString(),
            'effective_to' => null,
            // 1 ou NULL, jamais false : l'index unique repose sur le NULL.
            'is_current' => true,
            'notes' => 'Version initiale. Seuils calibrés sur un cycle de paiement '
                .'trimestriel : un seuil fondé sur les seuls paiements classerait '
                .'tout le monde « à risque » entre deux trimestres.',
            'created_at' => now(),
        ]);
    }
}
