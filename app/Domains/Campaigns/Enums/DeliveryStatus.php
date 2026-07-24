<?php

namespace App\Domains\Campaigns\Enums;

enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Failed = 'failed';
    case Bounced = 'bounced';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En file',
            self::Sent => 'Envoyé',
            self::Delivered => 'Remis',
            self::Opened => 'Ouvert',
            self::Clicked => 'Cliqué',
            self::Failed => 'Échec',
            self::Bounced => 'Rejeté',
        };
    }

    public function isBillable(): bool
    {
        return $this !== self::Queued && $this !== self::Failed;
    }

    public function showsEngagement(): bool
    {
        return in_array($this, [self::Opened, self::Clicked], true);
    }
}
