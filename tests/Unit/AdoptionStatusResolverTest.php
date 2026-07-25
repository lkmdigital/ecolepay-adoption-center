<?php

namespace Tests\Unit;

use App\Domains\Parents\Support\AdoptionStatusResolver;
use App\Shared\Enums\AdoptionStageCode;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La règle métier centrale : le statut vivant se détermine par année scolaire,
 * pas par jours d'inactivité.
 */
class AdoptionStatusResolverTest extends TestCase
{
    private AdoptionStatusResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        // Fenêtre de renouvellement : septembre → janvier.
        $this->resolver = new AdoptionStatusResolver(paymentWindowEndMonth: 1, schoolYearStartMonth: 9);
    }

    #[Test]
    public function a_number_in_a_roster_without_account_is_known(): void
    {
        $stage = $this->resolver->resolve(
            isInRoster: true, hasAccount: false,
            firstPaymentYear: null, lastPaymentYear: null, currentYear: 2025, now: Carbon::parse('2026-07-01'),
        );

        $this->assertSame(AdoptionStageCode::Known, $stage);
    }

    #[Test]
    public function an_account_without_payment_is_registered(): void
    {
        $stage = $this->resolver->resolve(
            isInRoster: true, hasAccount: true,
            firstPaymentYear: null, lastPaymentYear: null, currentYear: 2025, now: Carbon::parse('2026-07-01'),
        );

        $this->assertSame(AdoptionStageCode::Registered, $stage);
    }

    #[Test]
    public function a_first_payment_this_school_year_is_adopter(): void
    {
        // Paie pour la première fois cette année scolaire.
        $stage = $this->resolver->resolve(
            isInRoster: true, hasAccount: true,
            firstPaymentYear: 2025, lastPaymentYear: 2025, currentYear: 2025, now: Carbon::parse('2026-03-01'),
        );

        $this->assertSame(AdoptionStageCode::Adopter, $stage);
    }

    #[Test]
    public function paying_this_year_with_prior_history_is_engaged(): void
    {
        // A payé les années précédentes ET cette année : fidèle.
        $stage = $this->resolver->resolve(
            isInRoster: true, hasAccount: true,
            firstPaymentYear: 2023, lastPaymentYear: 2025, currentYear: 2025, now: Carbon::parse('2026-03-01'),
        );

        $this->assertSame(AdoptionStageCode::Engaged, $stage);
    }

    #[Test]
    public function paid_last_year_within_the_window_stays_engaged(): void
    {
        // Écart d'un an, mais on est en novembre (fenêtre ouverte) : tolérance.
        $stage = $this->resolver->resolve(
            isInRoster: true, hasAccount: true,
            firstPaymentYear: 2024, lastPaymentYear: 2024, currentYear: 2025, now: Carbon::parse('2025-11-15'),
        );

        $this->assertSame(AdoptionStageCode::Engaged, $stage);
    }

    #[Test]
    public function paid_last_year_after_the_window_is_at_risk(): void
    {
        // Écart d'un an, on est en juillet (fenêtre fermée) : il aurait dû renouveler.
        $stage = $this->resolver->resolve(
            isInRoster: true, hasAccount: true,
            firstPaymentYear: 2024, lastPaymentYear: 2024, currentYear: 2025, now: Carbon::parse('2026-07-01'),
        );

        $this->assertSame(AdoptionStageCode::AtRisk, $stage);
    }

    #[Test]
    public function no_payment_for_two_school_years_is_lost(): void
    {
        $stage = $this->resolver->resolve(
            isInRoster: true, hasAccount: true,
            firstPaymentYear: 2023, lastPaymentYear: 2023, currentYear: 2025, now: Carbon::parse('2026-07-01'),
        );

        $this->assertSame(AdoptionStageCode::Lost, $stage);
    }

    #[Test]
    public function a_parent_who_paid_but_left_the_roster_still_counts_as_adopter(): void
    {
        // Pas dans le roster, pas de compte connu, mais a payé : adoptant direct.
        $stage = $this->resolver->resolve(
            isInRoster: false, hasAccount: false,
            firstPaymentYear: 2025, lastPaymentYear: 2025, currentYear: 2025, now: Carbon::parse('2026-03-01'),
        );

        $this->assertSame(AdoptionStageCode::Adopter, $stage);
    }

    #[Test]
    public function the_payment_window_wraps_around_the_new_year(): void
    {
        $this->assertTrue($this->resolver->withinPaymentWindow(Carbon::parse('2025-09-15')));  // rentrée
        $this->assertTrue($this->resolver->withinPaymentWindow(Carbon::parse('2025-12-31')));  // décembre
        $this->assertTrue($this->resolver->withinPaymentWindow(Carbon::parse('2026-01-20')));  // janvier
        $this->assertFalse($this->resolver->withinPaymentWindow(Carbon::parse('2026-02-01'))); // février : fermée
        $this->assertFalse($this->resolver->withinPaymentWindow(Carbon::parse('2026-07-01'))); // juillet : fermée
    }
}
