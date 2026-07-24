<?php

namespace Tests\Feature\Seeders;

use App\Shared\Enums\AdoptionStageCode;
use App\Shared\Models\AdoptionRuleVersion;
use App\Shared\Models\AdoptionStage;
use App\Shared\Models\CalendarDate;
use App\Shared\Models\Channel;
use App\Shared\Models\EventType;
use App\Shared\Models\PaymentMethod;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\Shared\CalendarSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReferenceDataSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Plage courte : générer treize ans à chaque test coûterait six secondes.
        config()->set('eac.calendar.start_year', 2025);
        config()->set('eac.calendar.end_year', 2027);

        $this->seed(ReferenceDataSeeder::class);
    }

    #[Test]
    public function it_seeds_every_reference_dimension(): void
    {
        $this->assertSame(count(AdoptionStageCode::cases()), AdoptionStage::query()->count());
        $this->assertSame(5, Channel::query()->count());
        $this->assertGreaterThan(5, PaymentMethod::query()->count());
        $this->assertGreaterThan(10, EventType::query()->count());
        $this->assertSame(1, AdoptionRuleVersion::query()->count());
    }

    #[Test]
    public function the_seeders_are_idempotent(): void
    {
        $before = [
            AdoptionStage::query()->count(),
            Channel::query()->count(),
            PaymentMethod::query()->count(),
            EventType::query()->count(),
            AdoptionRuleVersion::query()->count(),
            CalendarDate::query()->count(),
        ];

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        $this->assertSame($before, [
            AdoptionStage::query()->count(),
            Channel::query()->count(),
            PaymentMethod::query()->count(),
            EventType::query()->count(),
            AdoptionRuleVersion::query()->count(),
            CalendarDate::query()->count(),
        ]);
    }

    #[Test]
    public function the_funnel_is_ordered_and_consistent_with_the_enum(): void
    {
        $stages = AdoptionStage::query()->inFunnelOrder()->get();

        $this->assertSame(
            array_map(fn (AdoptionStageCode $c) => $c->value, AdoptionStageCode::cases()),
            $stages->pluck('code')->map(fn (AdoptionStageCode $c) => $c->value)->all(),
        );

        foreach ($stages as $stage) {
            $this->assertSame($stage->code->funnelRank(), $stage->funnel_rank);
            $this->assertSame($stage->code->isConverted(), $stage->is_converted);
            $this->assertSame($stage->code->isDerived(), $stage->is_derived);
        }

        // Seuls « à risque » et « perdu » sont déduits d'une règle.
        $this->assertSame(2, AdoptionStage::query()->derived()->count());
    }

    #[Test]
    public function the_rule_version_thresholds_are_ordered(): void
    {
        $rule = AdoptionRuleVersion::active();

        $this->assertNotNull($rule);
        $this->assertGreaterThan($rule->at_risk_after_days, $rule->lost_after_days);
        $this->assertNotEmpty($rule->qualifying_event_types);
    }

    #[Test]
    public function every_qualifying_event_code_in_the_rule_actually_exists(): void
    {
        $rule = AdoptionRuleVersion::active();
        $known = EventType::query()->pluck('code')->all();

        foreach ($rule->qualifying_event_types as $code) {
            $this->assertContains($code, $known, "Le code {$code} de la règle n'existe pas dans dim_event_types.");
            $this->assertTrue($rule->counts($code));
        }
    }

    #[Test]
    public function passive_reception_is_not_activity(): void
    {
        // La distinction qui fonde la frontière entre « engagé » et « à risque ».
        $this->assertFalse(EventType::query()->where('code', 'notification_received')->value('counts_as_activity'));
        $this->assertTrue(EventType::query()->where('code', 'open_notification')->value('counts_as_activity'));
    }

    #[Test]
    public function unmapped_source_values_have_a_landing_row(): void
    {
        // Sans elle, un paiement dont le moyen est inconnu disparaîtrait de toute
        // jointure interne — le chiffre deviendrait faux, et silencieusement.
        $this->assertTrue(PaymentMethod::query()->where('code', 'unknown')->exists());
        $this->assertTrue(EventType::query()->where('code', 'unknown')->exists());
    }

    #[Test]
    public function the_calendar_covers_the_configured_range(): void
    {
        // Carbon 3 renvoie un flottant.
        $expected = (int) Carbon::create(2025, 1, 1)->diffInDays(Carbon::create(2027, 12, 31)) + 1;

        $this->assertSame($expected, CalendarDate::query()->count());
        $this->assertSame(20250101, (int) CalendarDate::query()->min('id'));
        $this->assertSame(20271231, (int) CalendarDate::query()->max('id'));
        // Aucun trou : le nombre de lignes égale l'étendue de la plage.
        $this->assertSame(1095, $expected);
    }

    #[Test]
    public function the_school_year_switches_at_the_configured_boundary(): void
    {
        $before = CalendarDate::query()->find(CalendarDate::keyFor('2026-09-14'));
        $after = CalendarDate::query()->find(CalendarDate::keyFor('2026-09-15'));

        $this->assertSame('2025-2026', $before->school_year_label);
        $this->assertSame('2026-2027', $after->school_year_label);
        $this->assertSame(1, $after->school_term);
    }

    #[Test]
    public function the_iso_year_differs_from_the_calendar_year_at_the_boundary(): void
    {
        $date = CalendarDate::query()->find(CalendarDate::keyFor('2025-12-29'));

        // La semaine 1 de 2026 commence en décembre 2025 : regrouper par année
        // civile produirait des cohortes hebdomadaires fausses.
        $this->assertSame(2025, $date->year);
        $this->assertSame(2026, $date->iso_year);
        $this->assertSame(1, $date->week_of_year);
    }

    #[Test]
    public function movable_christian_feasts_are_computed_from_easter(): void
    {
        // Pâques 2026 tombe le 5 avril.
        $easterMonday = CalendarDate::query()->find(CalendarDate::keyFor('2026-04-06'));
        $ascension = CalendarDate::query()->find(CalendarDate::keyFor('2026-05-14'));

        $this->assertTrue($easterMonday->is_public_holiday);
        $this->assertSame('Lundi de Pâques', $easterMonday->holiday_name);
        $this->assertSame('Ascension', $ascension->holiday_name);
    }

    #[Test]
    public function a_public_holiday_is_never_a_school_day(): void
    {
        $holidays = CalendarDate::query()->where('is_public_holiday', true)->get();

        $this->assertNotEmpty($holidays);
        foreach ($holidays as $day) {
            $this->assertFalse($day->is_school_day, "Le {$day->full_date->toDateString()} est férié mais marqué jour d'école.");
        }
    }

    #[Test]
    public function the_calendar_can_be_extended_without_rebuilding(): void
    {
        config()->set('eac.calendar.end_year', 2028);

        $this->seed(CalendarSeeder::class);

        $this->assertSame(20281231, (int) CalendarDate::query()->max('id'));
        // Les lignes existantes ont été mises à jour, pas dupliquées.
        $this->assertSame(1, CalendarDate::query()->where('id', 20260101)->count());
    }
}
