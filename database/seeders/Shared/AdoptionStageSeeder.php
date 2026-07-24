<?php

namespace Database\Seeders\Shared;

use App\Shared\Enums\AdoptionStageCode;
use App\Shared\Models\AdoptionStage;
use Illuminate\Database\Seeder;

/**
 * Les six états de l'entonnoir.
 *
 * Pas de ligne « Inconnu » ici : tout parcours a nécessairement un état, et un
 * septième état non ordonné fausserait le rang d'entonnoir.
 */
class AdoptionStageSeeder extends Seeder
{
    private const COLORS = [
        'known' => '#94A3B8',
        'registered' => '#38BDF8',
        'adopter' => '#22C55E',
        'engaged' => '#15803D',
        'at_risk' => '#F59E0B',
        'lost' => '#EF4444',
    ];

    private const DEFINITIONS = [
        'known' => "Le numéro existe dans la base de l'école, sans compte EcolePay.",
        'registered' => 'Le parent a créé un compte EcolePay.',
        'adopter' => 'Le parent a effectué son premier paiement : la conversion réelle.',
        'engaged' => "Le parent continue d'utiliser EcolePay.",
        'at_risk' => "Plus d'activité depuis la période définie par la règle en vigueur.",
        'lost' => "Le parent n'utilise plus EcolePay.",
    ];

    public function run(): void
    {
        foreach (AdoptionStageCode::cases() as $code) {
            AdoptionStage::query()->updateOrCreate(
                ['code' => $code->value],
                [
                    'label_fr' => $code->label(),
                    'definition' => self::DEFINITIONS[$code->value],
                    'funnel_rank' => $code->funnelRank(),
                    'is_converted' => $code->isConverted(),
                    'is_active_state' => $code->isActive(),
                    'is_terminal' => $code === AdoptionStageCode::Lost,
                    'is_derived' => $code->isDerived(),
                    'display_color' => self::COLORS[$code->value],
                ],
            );
        }
    }
}
