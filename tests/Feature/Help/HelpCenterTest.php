<?php

namespace Tests\Feature\Help;

use App\Domains\Help\Models\HelpMessage;
use App\Domains\Help\Support\HelpContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpCenterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_glossary_covers_every_required_term(): void
    {
        $terms = array_column(HelpContent::glossary(), 'term');
        foreach (['Parent connu', 'Parent inscrit', 'Parent engagé', "Taux d'adoption", 'Score de santé', 'Revenu potentiel'] as $expected) {
            $this->assertTrue(collect($terms)->contains(fn ($t) => str_starts_with($t, $expected)), "Terme manquant : $expected");
        }
    }

    #[Test]
    public function it_renders_and_searches_instantly(): void
    {
        $component = Livewire::test('help::index')->assertOk();

        $component->set('q', 'adoption');
        $this->assertGreaterThan(0, $component->instance()->resultCount());

        $component->set('q', 'terme-qui-nexiste-pas-xyz');
        $this->assertSame(0, $component->instance()->resultCount());
    }

    #[Test]
    public function it_opens_a_guide_and_handles_an_unknown_article(): void
    {
        Livewire::test('help::index')
            ->call('open', 'comprendre-kpi')
            ->assertSet('article', 'comprendre-kpi')
            ->assertOk()
            ->call('open', 'article-inexistant')
            ->assertOk(); // affiche l'état « article introuvable » sans erreur
    }

    #[Test]
    public function a_positive_rating_is_persisted(): void
    {
        Livewire::test('help::index')->call('rate', 'comprendre-kpi', true);

        $this->assertDatabaseHas('help_messages', ['kind' => 'feedback', 'article_key' => 'comprendre-kpi', 'helpful' => true]);
    }

    #[Test]
    public function a_support_request_is_persisted_and_validated(): void
    {
        $component = Livewire::test('help::index')
            ->set('support.subject', '')
            ->set('support.body', '')
            ->call('submitSupport')
            ->assertHasErrors(['support.subject', 'support.body']);

        $component->set('support.type', 'probleme')
            ->set('support.subject', 'Un souci')
            ->set('support.body', 'Description du souci')
            ->call('submitSupport')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('help_messages', ['kind' => 'support', 'subject' => 'Un souci']);
    }
}
