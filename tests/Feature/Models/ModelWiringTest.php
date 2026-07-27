<?php

namespace Tests\Feature\Models;

use App\Domains\Campaigns\Enums\CampaignChannel;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\Campaign;
use App\Domains\Parents\Models\ParentProfile;
use App\Domains\Schools\Models\School;
use App\Domains\Schools\Models\Student;
use App\Domains\Users\Models\User;
use App\Shared\Models\CalendarDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Vérifie le câblage des modèles : noms de tables, casts, relations et scopes
 * encodant les règles de conception.
 */
class ModelWiringTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function models_point_at_their_prefixed_tables(): void
    {
        $this->assertSame('dim_schools', (new School)->getTable());
        $this->assertSame('dim_parents', (new ParentProfile)->getTable());
        $this->assertSame('dim_students', (new Student)->getTable());
        $this->assertSame('dim_campaigns', (new Campaign)->getTable());
        $this->assertSame('dim_dates', (new CalendarDate)->getTable());
        $this->assertSame('users', (new User)->getTable());
    }

    #[Test]
    public function a_school_belongs_to_its_account_manager(): void
    {
        $commercial = User::factory()->create(['department' => 'Commercial']);
        $school = School::factory()->create(['account_manager_user_id' => $commercial->id]);

        $this->assertTrue($school->accountManager->is($commercial));
        $this->assertTrue($commercial->managedSchools->contains($school));
        $this->assertTrue(School::query()->managedBy($commercial)->exists());
    }

    #[Test]
    public function a_student_links_a_parent_to_a_school(): void
    {
        $school = School::factory()->create();
        $student = Student::factory()->create(['school_id' => $school->id]);
        $mother = ParentProfile::factory()->create();
        $father = ParentProfile::factory()->create();

        $student->parents()->attach($mother->id, [
            'relationship' => 'mere',
            'is_primary_payer' => true,
            'valid_from' => now()->toDateString(),
        ]);
        $student->parents()->attach($father->id, [
            'relationship' => 'pere',
            'is_primary_payer' => false,
            'valid_from' => now()->toDateString(),
        ]);

        // Un élève a souvent deux responsables : une clé étrangère unique aurait
        // rendu le second invisible.
        $this->assertCount(2, $student->parents);
        $this->assertCount(1, $student->primaryPayer);
        $this->assertTrue($student->primaryPayer->first()->is($mother));
        $this->assertTrue($student->school->is($school));
        $this->assertTrue($mother->students->contains($student));
    }

    #[Test]
    public function a_known_parent_has_no_ecolepay_account(): void
    {
        ParentProfile::factory()->withoutAccount()->count(3)->create();
        ParentProfile::factory()->count(2)->create();

        // Le premier étage de l'entonnoir : sans lui, le dénominateur des taux
        // de conversion serait faux.
        $this->assertSame(3, ParentProfile::query()->withoutAccount()->count());
        $this->assertSame(2, ParentProfile::query()->registered()->count());
        $this->assertFalse(ParentProfile::query()->withoutAccount()->first()->hasAccount());
    }

    #[Test]
    public function closing_a_version_sets_is_current_to_null_not_false(): void
    {
        $school = School::factory()->create([
            'source_school_id' => 'SCH-X',
            'valid_from' => now()->subYear(),
        ]);

        $school->closeVersion(now());

        $this->assertNull($school->fresh()->is_current);
        $this->assertFalse($school->fresh()->isCurrentVersion());

        // Une nouvelle version courante peut alors coexister : c'est exactement ce
        // que l'astuce du NULL permet.
        $next = School::factory()->create([
            'source_school_id' => 'SCH-X',
            'valid_from' => now(),
            'version' => 2,
        ]);

        $this->assertSame(2, School::query()->sameSource('SCH-X')->count());
        $this->assertSame(1, School::query()->sameSource('SCH-X')->current()->count());
        $this->assertTrue(School::query()->sameSource('SCH-X')->current()->first()->is($next));
    }

    #[Test]
    public function test_data_is_excluded_only_when_asked(): void
    {
        School::factory()->count(2)->create();
        School::factory()->test()->count(3)->create();

        $this->assertSame(5, School::query()->count());
        $this->assertSame(2, School::query()->production()->count());
        $this->assertSame(3, School::query()->onlyTestData()->count());
    }

    #[Test]
    public function campaign_casts_status_and_channel(): void
    {
        $campaign = Campaign::factory()->create();

        $this->assertInstanceOf(CampaignStatus::class, $campaign->status);
        $this->assertSame(CampaignStatus::Completed, $campaign->status);
        $this->assertInstanceOf(CampaignChannel::class, $campaign->channel);
        $this->assertSame(CampaignChannel::WhatsApp, $campaign->channel);
    }

    #[Test]
    public function a_campaign_is_soft_deleted_to_preserve_its_facts(): void
    {
        $campaign = Campaign::factory()->create();

        $campaign->delete();

        $this->assertSoftDeleted('dim_campaigns', ['id' => $campaign->id]);
        $this->assertSame(0, Campaign::query()->count());
        $this->assertSame(1, Campaign::withTrashed()->count());
    }

    #[Test]
    public function a_user_is_deactivated_rather_than_deleted(): void
    {
        $user = User::factory()->create();

        $user->deactivate();

        $this->assertFalse($user->fresh()->is_active);
        $this->assertNotNull($user->fresh()->deactivated_at);
        $this->assertSame(0, User::query()->active()->count());
        // La ligne demeure : la paternité de ses campagnes est préservée.
        $this->assertSame(1, User::query()->count());
    }

    #[Test]
    public function the_calendar_key_is_a_readable_date(): void
    {
        $this->assertSame(20260723, CalendarDate::keyFor('2026-07-23'));
    }
}
