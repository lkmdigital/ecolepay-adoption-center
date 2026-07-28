<?php

namespace Tests\Feature\Ai;

use App\Domains\AI\Actions\AskClaude;
use App\Domains\AI\Models\AiConversation;
use App\Domains\AI\Models\AiMessage;
use App\Domains\Settings\Support\Settings;
use App\Domains\Users\Support\CurrentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_not_configured_without_a_key(): void
    {
        $ask = app(AskClaude::class);
        $this->assertFalse($ask->isConfigured());
        $this->assertSame('no_key', $ask([['role' => 'user', 'content' => 'x']])['error']);
    }

    #[Test]
    public function the_key_from_settings_activates_the_assistant(): void
    {
        Settings::save(['ai_api_key' => 'sk-ant-xyz']);
        $this->assertTrue(app(AskClaude::class)->isConfigured());
    }

    #[Test]
    public function it_calls_the_anthropic_api_and_returns_grounded_text(): void
    {
        Settings::save(['ai_api_key' => 'sk-ant-xyz']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-opus-5',
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'Réponse ancrée.']],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
            ], 200),
        ]);

        $res = app(AskClaude::class)([['role' => 'user', 'content' => "Taux d'adoption ?"]]);

        $this->assertTrue($res['ok']);
        $this->assertSame('Réponse ancrée.', $res['text']);

        // La requête envoyée porte bien la clé et le prompt système ancré.
        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key', 'sk-ant-xyz')
                && str_contains($request['system'], 'DONNÉES RÉELLES ECOLEPAY');
        });
    }

    #[Test]
    public function an_invalid_key_surfaces_an_auth_error(): void
    {
        Settings::save(['ai_api_key' => 'sk-ant-bad']);
        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error']], 401)]);

        $res = app(AskClaude::class)([['role' => 'user', 'content' => 'x']]);

        $this->assertFalse($res['ok']);
        $this->assertSame('auth', $res['error']);
    }

    #[Test]
    public function sending_a_message_persists_the_exchange(): void
    {
        Settings::save(['ai_api_key' => 'sk-ant-xyz']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-opus-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => '**16,8 %** de taux d\'adoption.']],
        ], 200)]);

        Livewire::actingAs(CurrentUser::resolve())
            ->test('ai::index')
            ->set('draft', "Quel est le taux d'adoption ?")
            ->call('send');

        $this->assertSame(1, AiConversation::count());
        $this->assertSame(2, AiMessage::count());
        $this->assertSame('assistant', AiMessage::latest('id')->first()->role);
    }

    #[Test]
    public function the_page_shows_the_setup_state_when_unconfigured(): void
    {
        Livewire::actingAs(CurrentUser::resolve())
            ->test('ai::index')
            ->assertOk()
            ->assertSee("Connectez l'Assistant IA", false);
    }

    #[Test]
    public function the_settings_page_saves_the_api_key(): void
    {
        Livewire::test('settings::index')
            ->set('section', 'assistant')
            ->set('aiKey', 'sk-ant-from-ui')
            ->call('saveApiKey');

        $this->assertSame('sk-ant-from-ui', Settings::get('ai_api_key'));
    }

    #[Test]
    public function the_floating_widget_renders(): void
    {
        Livewire::actingAs(CurrentUser::resolve())->test('ai::widget')->assertOk();
    }

    #[Test]
    public function the_widget_tags_its_conversation_and_passes_page_context(): void
    {
        Settings::save(['ai_api_key' => 'sk-ant-xyz']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-opus-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Réponse contextuelle.']],
        ], 200)]);

        Livewire::actingAs(CurrentUser::resolve())
            ->test('ai::widget')
            ->set('pageLabel', 'Écoles')
            ->call('ask', 'Que montre cette page ?');

        // La conversation est marquée « widget », distincte du plein écran.
        $this->assertSame(1, AiConversation::where('source', 'widget')->count());

        // Le contexte de page est injecté dans le prompt système envoyé à Claude.
        Http::assertSent(fn ($request) => str_contains($request['system'], 'Écoles'));
    }
}
