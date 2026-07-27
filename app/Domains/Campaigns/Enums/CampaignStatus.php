<?php

namespace App\Domains\Campaigns\Enums;

/**
 * Statut d'une campagne importée dans EAC.
 *
 * Les campagnes tournent dans Perfect CX ; on les enregistre le plus souvent
 * « terminées » pour en mesurer l'impact, mais on garde les autres états pour
 * organiser le pipeline.
 */
enum CampaignStatus: string
{
    case Planned = 'planned';
    case Running = 'running';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planifiée',
            self::Running => 'En cours',
            self::Completed => 'Terminée',
            self::Archived => 'Archivée',
        };
    }

    /** @return array{0: string, 1: string} couleur texte, fond */
    public function colors(): array
    {
        return match ($this) {
            self::Planned => ['#1D3F9C', '#EEF3FE'],
            self::Running => ['#B45F04', '#FEF3E2'],
            self::Completed => ['#0F7A44', '#E9F8EF'],
            self::Archived => ['#5B6472', '#F2F3F5'],
        };
    }
}
