<?php

namespace App\Domains\Campaigns\Enums;

/**
 * Canal d'une campagne. WhatsApp aujourd'hui (via Perfect CX) ; les autres sont
 * prévus pour l'évolution future.
 */
enum CampaignChannel: string
{
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';
    case Email = 'email';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp',
            self::Sms => 'SMS',
            self::Email => 'Email',
            self::Other => 'Autre',
        };
    }
}
