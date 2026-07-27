<?php

namespace App\Domains\Campaigns\Enums;

/**
 * Type d'une opération marketing.
 *
 * Le module n'est pas limité à WhatsApp : il mesure l'impact de toute action
 * menée pour augmenter l'adoption. Deux familles selon la façon de mesurer :
 *
 *  - à liste de contacts (WhatsApp, SMS, Email) : on importe la liste et on
 *    mesure par rapprochement individuel (empreinte téléphone) ;
 *  - diffusion / terrain (push, réseaux sociaux, portes ouvertes, réunions,
 *    formations) : pas de liste individuelle, on mesure l'impact au niveau de
 *    l'école (évolution des inscriptions et paiements dans la fenêtre).
 */
enum CampaignChannel: string
{
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';
    case Email = 'email';
    case Push = 'push';
    case Social = 'social';
    case OpenHouse = 'open_house';
    case ParentMeeting = 'parent_meeting';
    case StaffTraining = 'staff_training';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp',
            self::Sms => 'SMS',
            self::Email => 'Email',
            self::Push => 'Notification push',
            self::Social => 'Réseaux sociaux',
            self::OpenHouse => 'Journée portes ouvertes',
            self::ParentMeeting => 'Réunion parents',
            self::StaffTraining => 'Formation du personnel',
            self::Other => 'Autre action',
        };
    }

    /** Regroupement pour les menus : canal digital vs action terrain. */
    public function category(): string
    {
        return match ($this) {
            self::WhatsApp, self::Sms, self::Email, self::Push, self::Social => 'Canaux digitaux',
            default => 'Actions terrain',
        };
    }

    /**
     * Dispose-t-on d'une liste de contacts individuels à importer et rapprocher ?
     * Sinon l'impact se mesure au niveau de l'école.
     */
    public function isContactBased(): bool
    {
        return in_array($this, [self::WhatsApp, self::Sms, self::Email], true);
    }
}
