<?php

namespace Tests\Feature\Search;

use App\Domains\Schools\Models\School;
use App\Domains\Users\Support\CurrentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_and_ignores_queries_shorter_than_two_chars(): void
    {
        $component = Livewire::actingAs(CurrentUser::resolve())->test('search::global')->assertOk();

        $component->set('q', 'a');
        $this->assertSame(0, $component->instance()->results()['total']);
    }

    #[Test]
    public function it_finds_a_matching_school(): void
    {
        School::factory()->create(['name' => 'GROUPE SCOLAIRE TEST COCODY']);

        $component = Livewire::actingAs(CurrentUser::resolve())->test('search::global')->set('q', 'cocody');

        $this->assertSame(1, $component->instance()->results()['schools']->count());
    }
}
