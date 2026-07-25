<?php

namespace Tests\Feature\Adoption;

use App\Domains\Parents\Actions\BuildParentJourneys;
use App\Domains\Parents\Models\ParentJourney;
use App\Domains\Parents\Models\ParentProfile;
use App\Domains\Schools\Models\School;
use App\Domains\Schools\Models\Student;
use App\Shared\Enums\AdoptionStageCode;
use App\Shared\Models\AdoptionStage;
use App\Shared\Models\CalendarDate;
use Database\Seeders\Shared\AdoptionRuleVersionSeeder;
use Database\Seeders\Shared\AdoptionStageSeeder;
use Database\Seeders\Shared\CalendarSeeder;
use Database\Seeders\Shared\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BuildParentJourneysTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        // Année scolaire courante = 2025-2026.
        Carbon::setTestNow('2026-03-01 10:00:00');
        config()->set('eac.calendar.start_year', 2025);
        config()->set('eac.calendar.end_year', 2026);

        $this->seed([AdoptionStageSeeder::class, PaymentMethodSeeder::class, CalendarSeeder::class, AdoptionRuleVersionSeeder::class]);
        $this->school = School::factory()->create(['is_test' => false, 'is_current' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_classifies_the_full_funnel_from_the_facts(): void
    {
        $connu = $this->parentInRoster('known', hasAccount: false);
        $inscrit = $this->parentInRoster('registered', hasAccount: true);
        $adoptant = $this->parentInRoster('adopter', hasAccount: true);
        $this->payment($adoptant, '2026-02-01 09:00:00'); // paie cette année scolaire

        $stats = app(BuildParentJourneys::class)();

        $this->assertSame(3, $stats['journeys']);
        $this->assertSame(AdoptionStageCode::Known, $this->stageOf($connu));
        $this->assertSame(AdoptionStageCode::Registered, $this->stageOf($inscrit));
        $this->assertSame(AdoptionStageCode::Adopter, $this->stageOf($adoptant));

        $journey = ParentJourney::query()->where('parent_id', $adoptant->id)->first();
        $this->assertTrue($journey->is_converted);
        $this->assertTrue($journey->has_ever_paid);
        $this->assertSame(1, $journey->successful_payment_count);
        $this->assertNotNull($journey->first_payment_date_id);
    }

    #[Test]
    public function a_manual_payment_does_not_trigger_adoption(): void
    {
        $parent = $this->parentInRoster('registered', hasAccount: true);
        $this->payment($parent, '2026-02-01 09:00:00', manual: true);

        app(BuildParentJourneys::class)();

        // Paiement espèces : reste inscrit, pas adoptant.
        $this->assertSame(AdoptionStageCode::Registered, $this->stageOf($parent));
        $this->assertFalse(ParentJourney::query()->where('parent_id', $parent->id)->first()->has_ever_paid);
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $adoptant = $this->parentInRoster('adopter', hasAccount: true);
        $this->payment($adoptant, '2026-02-01 09:00:00');

        app(BuildParentJourneys::class)();
        app(BuildParentJourneys::class)();

        $this->assertSame(1, ParentJourney::query()->count());
    }

    private function parentInRoster(string $tag, bool $hasAccount): ParentProfile
    {
        $parent = ParentProfile::query()->create([
            'source_parent_id' => $hasAccount ? 'U'.uniqid() : null,
            'phone_hash' => random_bytes(32),
            'first_known_at' => now()->subMonths(3),
            'account_created_at' => $hasAccount ? now()->subMonths(2) : null,
            'marketing_consent' => false, 'is_pseudonymized' => false, 'is_test' => false,
            'row_hash' => random_bytes(32), 'synced_at' => now(),
        ]);

        $student = Student::factory()->create([
            'school_id' => $this->school->id, 'is_test' => false, 'school_year_label' => '2025-2026',
        ]);
        $student->parents()->attach($parent->id, [
            'is_primary_payer' => true, 'valid_from' => now()->subMonths(3)->toDateString(),
        ]);

        return $parent;
    }

    private function payment(ParentProfile $parent, string $date, bool $manual = false): void
    {
        DB::table('fact_payments')->insert([
            'source_payment_id' => 'PAY'.uniqid(),
            'date_id' => CalendarDate::keyFor($date),
            'paid_at' => $date,
            'parent_id' => $parent->id,
            'school_id' => $this->school->id,
            'payment_method_id' => DB::table('dim_payment_methods')->where('code', 'wave')->value('id'),
            'amount' => 13940, 'net_amount' => 4000, 'subscription_amount' => 9900, 'fee_amount' => 40,
            'currency' => 'XOF', 'status' => 'success', 'is_manual' => $manual, 'is_first_payment' => false,
            'is_test' => false, 'synced_at' => now(),
        ]);
    }

    private function stageOf(ParentProfile $parent): AdoptionStageCode
    {
        $journey = ParentJourney::query()->where('parent_id', $parent->id)->firstOrFail();

        return AdoptionStage::query()->find($journey->current_stage_id)->code;
    }
}
