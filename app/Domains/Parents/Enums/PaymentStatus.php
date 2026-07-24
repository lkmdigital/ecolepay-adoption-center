<?php

namespace App\Domains\Parents\Enums;

enum PaymentStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Pending = 'pending';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Abouti',
            self::Failed => 'Échoué',
            self::Pending => 'En attente',
            self::Refunded => 'Remboursé',
            self::Cancelled => 'Annulé',
        };
    }

    /**
     * Seul un paiement abouti déclenche la conversion.
     */
    public function countsAsConversion(): bool
    {
        return $this === self::Success;
    }

    /**
     * Un premier paiement échoué est le signal le plus actionnable du dispositif :
     * le parent a essayé et n'y est pas parvenu.
     */
    public function isActionable(): bool
    {
        return in_array($this, [self::Failed, self::Pending], true);
    }
}
