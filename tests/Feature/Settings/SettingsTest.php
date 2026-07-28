<?php

namespace Tests\Feature\Settings;

use App\Domains\Settings\Models\AppSetting;
use App\Domains\Settings\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_falls_back_to_defaults_when_nothing_is_stored(): void
    {
        $this->assertSame('Adoption Center', Settings::get('platform_name'));
        $this->assertSame(2, Settings::get('engaged_min_payments'));
        $this->assertSame(0, AppSetting::count());
    }

    #[Test]
    public function it_persists_values_and_invalidates_the_cache(): void
    {
        Settings::get('platform_name'); // amorce le cache

        Settings::save(['platform_name' => 'Pilotage EcolePay', 'kpi_green_min' => 55]);

        $this->assertSame('Pilotage EcolePay', Settings::get('platform_name'));
        $this->assertSame(55, Settings::get('kpi_green_min'));
        // Les clés non touchées gardent leur valeur par défaut.
        $this->assertSame(25, Settings::get('kpi_orange_min'));
    }

    #[Test]
    public function the_page_saves_editable_settings(): void
    {
        Livewire::test('settings::index')
            ->set('form.platform_name', 'Centre EcolePay')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Centre EcolePay', Settings::get('platform_name'));
    }

    #[Test]
    public function it_rejects_a_green_threshold_below_the_orange_threshold(): void
    {
        Livewire::test('settings::index')
            ->set('form.kpi_green_min', 20)
            ->set('form.kpi_orange_min', 30)
            ->call('save')
            ->assertHasErrors('form.kpi_green_min');

        // Rien n'a été persisté puisque la validation a échoué.
        $this->assertSame(0, AppSetting::count());
    }

    #[Test]
    public function every_section_renders_without_error(): void
    {
        $component = Livewire::test('settings::index');

        foreach (['general', 'adoption', 'campaigns', 'notifications', 'reports', 'integrations', 'security', 'appearance', 'maintenance', 'about'] as $section) {
            $component->set('section', $section)->assertOk();
        }
    }
}
