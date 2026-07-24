<?php

namespace Tests\Feature\Seeders;

use App\Domains\Campaigns\Models\Campaign;
use App\Domains\Campaigns\Models\CampaignContact;
use App\Domains\Parents\Models\ParentJourney;
use App\Domains\Parents\Models\ParentProfile;
use App\Domains\Parents\Models\Payment;
use App\Domains\Schools\Models\School;
use App\Shared\Enums\AdoptionStageCode;
use App\Shared\Models\AdoptionStage;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Un jeu de démonstration incohérent produit des écrans plausibles mais
 * indébogables : on ne sait plus si une anomalie vient du code ou des données.
 */
class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('eac.calendar.start_year', now()->subYears(4)->year);
        config()->set('eac.calendar.end_year', now()->year);

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(DemoDataSeeder::class);
    }

    #[Test]
    public function everything_it_creates_is_flagged_as_test_data(): void
    {
        // La production ne doit rien voir de ce jeu.
        $this->assertSame(0, School::query()->production()->count());
        $this->assertSame(0, ParentProfile::query()->production()->count());
        $this->assertSame(0, Payment::query()->production()->count());
        $this->assertSame(0, ParentJourney::query()->production()->count());

        $this->assertGreaterThan(0, School::query()->onlyTestData()->count());
    }

    #[Test]
    public function it_can_be_replayed_without_violating_constraints(): void
    {
        $before = School::query()->onlyTestData()->count();

        $this->seed(DemoDataSeeder::class);

        $this->assertSame($before, School::query()->onlyTestData()->count());
        // Une seule ligne courante par école, malgré deux passages.
        $this->assertSame($before, School::query()->onlyTestData()->current()->count());
    }

    #[Test]
    public function a_converted_journey_always_has_a_successful_payment(): void
    {
        $this->assertSame(
            0,
            ParentJourney::query()->onlyTestData()
                ->where('is_converted', true)
                ->where('successful_payment_count', 0)
                ->count(),
        );
    }

    #[Test]
    public function nobody_pays_before_registering(): void
    {
        $this->assertSame(
            0,
            ParentJourney::query()->onlyTestData()
                ->whereNull('registered_at')
                ->whereNotNull('first_payment_at')
                ->count(),
        );
    }

    #[Test]
    public function known_parents_have_no_ecolepay_account(): void
    {
        $knownStageId = AdoptionStage::idFor(AdoptionStageCode::Known);

        $parentIds = ParentJourney::query()->onlyTestData()
            ->where('current_stage_id', $knownStageId)
            ->pluck('parent_id');

        $this->assertGreaterThan(0, $parentIds->count());
        $this->assertSame(
            0,
            ParentProfile::query()->whereIn('id', $parentIds)->whereNotNull('source_parent_id')->count(),
        );
    }

    #[Test]
    public function inactivity_matches_the_stage_it_is_supposed_to_produce(): void
    {
        $atRisk = config('eac.adoption.at_risk_after_days');
        $lost = config('eac.adoption.lost_after_days');

        $inStage = fn (AdoptionStageCode $code) => ParentJourney::query()->onlyTestData()
            ->where('current_stage_id', AdoptionStage::idFor($code));

        // Un parcours actif ne doit pas dépasser le seuil « à risque », sans quoi
        // le traitement d'inactivité l'aurait déjà fait basculer.
        foreach ([AdoptionStageCode::Registered, AdoptionStageCode::Adopter, AdoptionStageCode::Engaged] as $code) {
            $this->assertSame(
                0,
                (clone $inStage($code))->where('days_since_last_activity', '>=', $atRisk)->count(),
                "Un parcours « {$code->label()} » dépasse le seuil d'inactivité.",
            );
        }

        $this->assertSame(0, (clone $inStage(AdoptionStageCode::AtRisk))->where('days_since_last_activity', '<', $atRisk)->count());
        $this->assertSame(0, (clone $inStage(AdoptionStageCode::AtRisk))->where('days_since_last_activity', '>=', $lost)->count());
        $this->assertSame(0, (clone $inStage(AdoptionStageCode::Lost))->where('days_since_last_activity', '<', $lost)->count());
    }

    #[Test]
    public function the_funnel_is_populated_at_every_stage(): void
    {
        foreach (AdoptionStageCode::cases() as $code) {
            $this->assertGreaterThan(
                0,
                ParentJourney::query()->onlyTestData()
                    ->where('current_stage_id', AdoptionStage::idFor($code))
                    ->count(),
                "Aucun parcours à l'état « {$code->label()} » : les écrans ne pourront pas être vérifiés.",
            );
        }
    }

    #[Test]
    public function campaign_contacts_freeze_the_stage_at_send_time(): void
    {
        $contacts = CampaignContact::query()->onlyTestData()->get();

        $this->assertGreaterThan(0, $contacts->count());
        foreach ($contacts as $contact) {
            $this->assertNotNull($contact->stage_id_at_send);
        }
    }

    #[Test]
    public function it_creates_one_account_per_role(): void
    {
        foreach (['super-admin', 'direction', 'marketing', 'commercial', 'support', 'analyst', 'developer'] as $role) {
            $this->assertDatabaseHas('users', ['email' => $role.'@demo.eac']);
        }
    }

    #[Test]
    public function sent_campaigns_have_contacts(): void
    {
        $sent = Campaign::query()->sent()->get();

        $this->assertGreaterThan(0, $sent->count());
        foreach ($sent as $campaign) {
            $this->assertGreaterThan(0, $campaign->contacts()->count());
            $this->assertSame($campaign->contacts()->count(), $campaign->recipient_count);
        }
    }
}
