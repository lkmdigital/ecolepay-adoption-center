<?php

namespace App\Domains\Campaigns\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Scheduled => 'Planifiée',
            self::Sending => 'En cours',
            self::Sent => 'Envoyée',
            self::Cancelled => 'Annulée',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled], true);
    }

    public function hasLeftTheBuilding(): bool
    {
        return in_array($this, [self::Sending, self::Sent], true);
    }
}
