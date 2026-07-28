<?php

namespace App\Domains\AI\Actions;

use App\Domains\AI\Support\EacContext;
use App\Domains\Settings\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Appelle l'API Messages d'Anthropic (Claude) pour l'Assistant IA.
 *
 * - La clé API est fournie par l'utilisateur (Paramètres › Assistant IA) ou via
 *   la variable d'environnement ANTHROPIC_API_KEY ; elle n'est jamais inventée.
 * - Les réponses sont ANCRÉES sur un instantané des vraies données EcolePay
 *   (EacContext) injecté dans le prompt système, pour éviter les hallucinations.
 * - En l'absence de clé, on renvoie un état explicite « non configuré » plutôt
 *   que de simuler une réponse.
 */
final class AskClaude
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const VERSION = '2023-06-01';

    public function __construct(private readonly EacContext $context) {}

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    private function apiKey(): string
    {
        // Priorité au réglage saisi dans l'UI, repli sur la configuration/env.
        return (string) (Settings::get('ai_api_key') ?: config('services.anthropic.key', ''));
    }

    public function model(): string
    {
        return (string) Settings::get('ai_model', 'claude-opus-5');
    }

    /** Prompt système = rôle de l'assistant + garde-fous + données réelles. */
    public function systemPrompt(string $extraContext = ''): string
    {
        // Filet de sécurité : si la couche données échoue, l'assistant répond
        // quand même (sans ancrage) plutôt que de tomber en erreur.
        try {
            $context = Cache::remember('ai.context', 300, fn () => $this->context->build());
        } catch (\Throwable $e) {
            $context = "DONNÉES RÉELLES ECOLEPAY : instantané momentanément indisponible.";
        }

        // Contexte de page (bot flottant) : oriente la réponse vers l'écran courant.
        $page = $extraContext !== '' ? "\n\nCONTEXTE : {$extraContext}" : '';

        return <<<TXT
        Tu es l'assistant décisionnel d'EcolePay Adoption Center (EAC), la plateforme interne de LKM Digital qui pilote l'adoption de l'application de paiement scolaire EcolePay en Côte d'Ivoire.

        RÈGLES :
        - Réponds en français, de façon concise et actionnable, avec les chiffres exacts.
        - Utilise UNIQUEMENT les données réelles fournies ci-dessous. N'invente jamais un chiffre.
        - Si l'information demandée n'est pas dans ces données, dis-le clairement et oriente vers le module concerné (Écoles, Campagnes, Analytics, Rapports…).
        - Rappelle la règle métier au besoin : l'adoption = premier paiement d'un parent via l'app (pas la création de compte). Parcours : connu → inscrit → adoptant → engagé.
        - Formate avec un markdown léger (gras, listes). Pas de tableaux lourds.
        - Termine par une recommandation concrète quand c'est pertinent.

        {$context}{$page}
        TXT;
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  string  $extraContext  Contexte additionnel (ex. page consultée) ajouté au prompt système.
     * @return array{ok: bool, text: string, error?: string, model?: string, usage?: array}
     */
    public function __invoke(array $messages, string $extraContext = ''): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'text' => '', 'error' => 'no_key'];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey(),
                'anthropic-version' => self::VERSION,
                'content-type' => 'application/json',
            ])->timeout(120)->post(self::ENDPOINT, [
                'model' => $this->model(),
                'max_tokens' => (int) Settings::get('ai_max_tokens', 2048),
                'system' => $this->systemPrompt($extraContext),
                'output_config' => ['effort' => (string) Settings::get('ai_effort', 'low')],
                'messages' => array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $messages),
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'text' => '', 'error' => 'connection'];
        }

        if ($response->failed()) {
            $type = $response->json('error.type');
            $error = match ($response->status()) {
                401 => 'auth',
                429 => 'rate_limit',
                400 => $type === 'invalid_request_error' ? 'bad_request' : 'api',
                default => 'api',
            };

            return ['ok' => false, 'text' => '', 'error' => $error, 'status' => $response->status()];
        }

        $data = $response->json();

        // Un refus de sûreté renvoie un HTTP 200 avec stop_reason = refusal.
        if (($data['stop_reason'] ?? null) === 'refusal') {
            return ['ok' => false, 'text' => '', 'error' => 'refusal'];
        }

        $text = collect($data['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");

        return [
            'ok' => true,
            'text' => trim($text) ?: '(réponse vide)',
            'model' => $data['model'] ?? $this->model(),
            'usage' => $data['usage'] ?? [],
        ];
    }
}
