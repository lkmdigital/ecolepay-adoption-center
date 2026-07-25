<?php

namespace App\Domains\Parents\Support;

use App\Shared\Enums\AdoptionStageCode;
use Illuminate\Support\Carbon;

/**
 * Détermine le statut vivant d'un parent à une école, PAR ANNÉE SCOLAIRE.
 *
 * EcolePay étant une app de paiement saisonnière, l'inactivité en jours n'est pas un
 * décrochage. Ce qui compte : le parent a-t-il payé via l'app cette année scolaire ?
 *
 * Règle pure (aucun accès base), donc testable isolément.
 */
final class AdoptionStatusResolver
{
    public function __construct(
        private readonly int $paymentWindowEndMonth = 1,
        private readonly int $schoolYearStartMonth = 9,
    ) {}

    /**
     * @param  int|null  $firstPaymentYear  Année de début de la 1re année scolaire avec paiement app validé
     * @param  int|null  $lastPaymentYear  Idem pour le dernier paiement
     * @param  int  $currentYear  Année de début de l'année scolaire courante
     */
    public function resolve(
        bool $isInRoster,
        bool $hasAccount,
        ?int $firstPaymentYear,
        ?int $lastPaymentYear,
        int $currentYear,
        Carbon $now,
    ): AdoptionStageCode {
        // Jamais de paiement app validé → connu ou inscrit.
        if ($lastPaymentYear === null) {
            return $hasAccount ? AdoptionStageCode::Registered : AdoptionStageCode::Known;
        }

        $gap = $currentYear - $lastPaymentYear;

        // A payé cette année scolaire : adoptant (1re année) ou engagé (a un historique).
        if ($gap <= 0) {
            return $firstPaymentYear === $lastPaymentYear
                ? AdoptionStageCode::Adopter
                : AdoptionStageCode::Engaged;
        }

        // A payé l'an dernier seulement : encore engagé dans la fenêtre, sinon à risque.
        if ($gap === 1) {
            return $this->withinPaymentWindow($now)
                ? AdoptionStageCode::Engaged
                : AdoptionStageCode::AtRisk;
        }

        // Deux années scolaires ou plus sans paiement app : perdu.
        return AdoptionStageCode::Lost;
    }

    /**
     * Sommes-nous dans la fenêtre de renouvellement (rentrée → fin janvier) ?
     */
    public function withinPaymentWindow(Carbon $now): bool
    {
        $month = $now->month;

        return $month >= $this->schoolYearStartMonth || $month <= $this->paymentWindowEndMonth;
    }
}
