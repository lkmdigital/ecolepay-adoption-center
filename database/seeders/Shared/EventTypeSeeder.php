<?php

namespace Database\Seeders\Shared;

use App\Shared\Models\EventType;
use Illuminate\Database\Seeder;

/**
 * Catalogue des événements d'usage.
 *
 * `counts_as_activity` définit la frontière entre « engagé » et « à risque ».
 * Recevoir une notification n'est pas un usage — l'ouvrir en est un. C'est
 * précisément la distinction que porte `notification_received` face à
 * `open_notification`.
 */
class EventTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            // code, libellé, catégorie, activité, acte de valeur, poids
            ['unknown', 'Inconnu', 'unknown', false, false, 0.00],

            ['login', 'Connexion', 'auth', true, false, 1.00],
            ['logout', 'Déconnexion', 'auth', false, false, 0.00],
            ['password_reset', 'Réinitialisation du mot de passe', 'auth', true, false, 0.50],

            ['view_dashboard', 'Consultation du tableau de bord', 'consultation', true, false, 1.00],
            ['view_invoice', 'Consultation d\'une facture', 'consultation', true, true, 2.00],
            ['view_payment_history', 'Consultation de l\'historique', 'consultation', true, false, 1.50],
            ['download_receipt', 'Téléchargement d\'un reçu', 'consultation', true, true, 2.50],

            ['payment_initiated', 'Paiement initié', 'transaction', true, true, 4.00],
            ['payment_completed', 'Paiement abouti', 'transaction', true, true, 5.00],
            ['payment_failed', 'Paiement échoué', 'transaction', true, true, 3.00],

            // Réception passive : ne compte pas comme activité.
            ['notification_received', 'Notification reçue', 'communication', false, false, 0.00],
            ['open_notification', 'Notification ouverte', 'communication', true, false, 1.00],
            ['message_read', 'Message lu', 'communication', true, false, 1.00],

            ['contact_support', 'Contact du support', 'support', true, true, 2.00],
            ['ticket_opened', 'Ticket ouvert', 'support', true, true, 2.00],
        ];

        foreach ($types as [$code, $label, $category, $countsAsActivity, $isValueAction, $weight]) {
            EventType::query()->updateOrCreate(
                ['code' => $code],
                [
                    'label_fr' => $label,
                    'category' => $category,
                    'counts_as_activity' => $countsAsActivity,
                    'is_value_action' => $isValueAction,
                    'activity_weight' => $weight,
                    'is_active' => $code !== 'unknown',
                ],
            );
        }
    }
}
