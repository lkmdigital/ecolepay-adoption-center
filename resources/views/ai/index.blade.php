<?php

use App\Domains\AI\Actions\AskClaude;
use App\Domains\AI\Models\AiConversation;
use App\Domains\AI\Models\AiMessage;
use App\Domains\Settings\Support\Settings;
use App\Domains\Users\Support\CurrentUser;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public ?int $conversationId = null;

    public string $draft = '';

    public int $userId;

    // Vue active : 'chat' (conversation / accueil) ou 'history' (liste).
    public string $view = 'chat';

    public function mount(): void
    {
        $this->userId = CurrentUser::resolve()->id;

        if ($this->conversationId && ! AiConversation::where('user_id', $this->userId)->whereKey($this->conversationId)->exists()) {
            $this->conversationId = null;
        }
        if (! $this->conversationId) {
            $this->conversationId = AiConversation::where('user_id', $this->userId)->where('source', 'page')->latest('updated_at')->value('id');
        }
    }

    #[Computed]
    public function configured(): bool
    {
        return app(AskClaude::class)->isConfigured() && (bool) Settings::get('ai_enabled', true);
    }

    #[Computed]
    public function conversations(): Collection
    {
        return AiConversation::where('user_id', $this->userId)->where('source', 'page')->latest('updated_at')->take(40)->get();
    }

    #[Computed]
    public function messages(): Collection
    {
        if (! $this->conversationId) {
            return collect();
        }

        return AiMessage::where('conversation_id', $this->conversationId)->orderBy('id')->get();
    }

    public function newConversation(): void
    {
        $this->conversationId = null;
        $this->draft = '';
        $this->view = 'chat';
    }

    public function openConversation(int $id): void
    {
        if (AiConversation::where('user_id', $this->userId)->whereKey($id)->exists()) {
            $this->conversationId = $id;
            $this->view = 'chat';
        }
    }

    public function ask(string $prompt): void
    {
        $this->draft = $prompt;
        $this->send();
    }

    public function deleteConversation(int $id): void
    {
        AiConversation::where('user_id', $this->userId)->whereKey($id)->delete();
        if ($this->conversationId === $id) {
            $this->conversationId = null;
        }
        unset($this->conversations, $this->messages);
    }

    public function suggest(string $prompt): void
    {
        $this->draft = $prompt;
    }

    public function briefing(): void
    {
        $this->draft = "Fais-moi le briefing du jour : où en est l'adoption globale, quelles écoles nécessitent une action prioritaire, et quelles sont mes 3 actions recommandées aujourd'hui ?";
        $this->send();
    }

    public function send(): void
    {
        $text = trim($this->draft);
        if ($text === '' || ! $this->configured) {
            return;
        }

        $conversation = $this->conversationId
            ? AiConversation::where('user_id', $this->userId)->find($this->conversationId)
            : null;

        if (! $conversation) {
            $conversation = AiConversation::create([
                'user_id' => $this->userId,
                'title' => \Illuminate\Support\Str::limit($text, 48),
            ]);
            $this->conversationId = $conversation->id;
        }

        AiMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => $text]);
        $this->draft = '';
        unset($this->messages, $this->conversations);

        // Historique envoyé à Claude : messages valides uniquement (hors erreurs).
        $history = AiMessage::where('conversation_id', $conversation->id)->orderBy('id')->get()
            ->reject(fn ($m) => ($m->meta['error'] ?? null))
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()->all();

        $res = app(AskClaude::class)($history);

        if ($res['ok']) {
            AiMessage::create([
                'conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => $res['text'],
                'meta' => ['model' => $res['model'] ?? null, 'usage' => $res['usage'] ?? []],
            ]);
        } else {
            $msg = match ($res['error'] ?? 'api') {
                'no_key' => "Aucune clé API n'est configurée. Renseignez votre clé dans Paramètres › KATIA.",
                'auth' => "La clé API a été refusée. Vérifiez-la dans Paramètres › KATIA.",
                'rate_limit' => "Limite de requêtes atteinte. Patientez quelques instants puis réessayez.",
                'connection' => "Impossible de joindre l'API Claude. Vérifiez la connexion réseau du serveur.",
                'refusal' => "Cette demande a été déclinée par les protections de sécurité du modèle.",
                default => "Une erreur est survenue lors de l'appel à l'API Claude.",
            };
            AiMessage::create([
                'conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => $msg,
                'meta' => ['error' => $res['error'] ?? 'api'],
            ]);
        }

        $conversation->touch();
        unset($this->messages, $this->conversations);
        $this->dispatch('ai-scroll');
    }
};

?>

@php
    use Illuminate\Support\Str;

    // Questions rapides (chips).
    $suggestions = [
        "Pourquoi le taux d'adoption a-t-il évolué ce mois-ci ?",
        "Compare les écoles d'Abidjan.",
        "Quelles campagnes sont les plus performantes ?",
        "Génère un rapport exécutif.",
        "Quelles écoles nécessitent une intervention ?",
        "Quels parents sont inscrits mais n'ont jamais effectué de premier paiement ?",
        "Quel est le potentiel de revenus restant ?",
        "Résume les performances de cette semaine.",
    ];

    // Analyses proposées (libellé => prompt).
    $analyses = [
        'Écoles à plus fort potentiel' => "Quelles écoles ont le plus fort potentiel de revenu restant ?",
        'Établissements à risque' => "Quels établissements sont à risque et pourquoi ?",
        'Meilleures campagnes du mois' => "Quelles sont les meilleures opérations marketing récentes ?",
        'Régions en forte progression' => "Quelles régions progressent le plus en adoption ?",
        'Tendances des revenus' => "Quelle est la tendance des revenus via l'application ?",
    ];

    $msgs = $this->messages;
    $tabBase = 'inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-[12.5px] font-semibold transition-colors';
@endphp

<div class="mx-auto max-w-[880px]"
     x-data="{ scroll() { this.$nextTick(() => { const b = this.$refs.thread; if (b) b.scrollTop = b.scrollHeight; }); } }"
     x-init="scroll()"
     @ai-scroll.window="scroll()">

    {{-- Onglets --}}
    <div class="mb-6 flex flex-wrap items-center justify-center gap-1.5">
        <button wire:click="newConversation" class="{{ $tabBase }} {{ $view === 'chat' && ! $conversationId ? 'bg-brand-600 text-white' : 'text-ink-600 hover:bg-ink-100' }}">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            Nouvelle conversation
        </button>
        <button wire:click="$set('view', 'history')" class="{{ $tabBase }} {{ $view === 'history' ? 'bg-brand-600 text-white' : 'text-ink-600 hover:bg-ink-100' }}">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M10 6v4l2.5 1.5M10 3a7 7 0 100 14 7 7 0 000-14z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Historique
        </button>
        <a href="{{ route('help.index') }}" class="{{ $tabBase }} text-ink-600 hover:bg-ink-100">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M4 4h9a3 3 0 013 3v9l-3-2H4z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
            Connaissances
        </a>
        <a href="{{ route('settings.index', ['section' => 'assistant']) }}" class="{{ $tabBase }} text-ink-600 hover:bg-ink-100">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M10 3v2M10 15v2M3 10h2M15 10h2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            Paramètres IA
        </a>
    </div>

    @if (! $this->configured)
        {{-- État : non configuré --}}
        <div class="flex flex-col items-center gap-4 rounded-[18px] border border-ink-200 bg-white py-16 text-center shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <x-ai.mascot :size="64" />
            <div>
                <div class="text-[18px] font-bold text-ink-900">Connectez KATIA</div>
                <p class="mx-auto mt-1.5 max-w-md text-[13px] leading-relaxed text-ink-500">
                    KATIA répond à vos questions métier à partir de vos vraies données EcolePay, propulsé par Claude. Renseignez votre clé API Anthropic pour l'activer.
                </p>
            </div>
            <a href="{{ route('settings.index', ['section' => 'assistant']) }}" class="inline-flex items-center gap-2 rounded-[10px] bg-brand-600 px-4 py-2.5 text-[13px] font-semibold text-white hover:bg-brand-700">
                <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M10 3v2M10 15v2M3 10h2M15 10h2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                Configurer la clé API
            </a>
        </div>

    @elseif ($view === 'history')
        {{-- Historique des conversations --}}
        <div class="rounded-[18px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)] sm:p-5">
            <h2 class="mb-3 text-[15px] font-bold text-ink-900">Historique des conversations</h2>
            <div class="space-y-1">
                @forelse ($this->conversations as $c)
                    <div wire:key="conv-{{ $c->id }}" class="group flex items-center gap-2 rounded-[11px] border border-ink-150 px-3 py-2.5 hover:border-brand-300 hover:bg-brand-50/30">
                        <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-brand-50"><x-ai.mascot :size="18" /></span>
                        <button wire:click="openConversation({{ $c->id }})" class="min-w-0 flex-1 truncate text-left text-[13px] font-medium text-ink-800">{{ $c->title }}</button>
                        <span class="hidden flex-shrink-0 text-[11px] text-ink-400 sm:block">{{ $c->updated_at->diffForHumans() }}</span>
                        <button wire:click="deleteConversation({{ $c->id }})" class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded text-ink-400 hover:text-danger" title="Supprimer">
                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M5 6h10M8 6V4h4v2M6 6l1 10h6l1-10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    </div>
                @empty
                    <p class="py-10 text-center text-[13px] text-ink-500">Aucune conversation enregistrée pour le moment.</p>
                @endforelse
            </div>
        </div>

    @elseif ($msgs->isEmpty())
        {{-- Accueil centré --}}
        <div class="pt-6 text-center">
            <x-ai.mascot :size="60" class="mx-auto" />
            <h1 class="mt-3 text-[24px] font-bold tracking-tight text-ink-900">Comment puis-je vous aider ?</h1>
            <p class="mt-1.5 text-[13.5px] text-ink-500">Analyses, comparaisons, recommandations et rapports sur l'adoption d'EcolePay.</p>

            {{-- Champ de saisie principal --}}
            <form wire:submit="send" class="mx-auto mt-6 flex max-w-[640px] items-end gap-2 rounded-[16px] border border-ink-300 bg-white px-4 py-3 shadow-sm focus-within:border-brand-600">
                <textarea wire:model="draft" rows="1" placeholder="Posez votre question sur l'adoption d'EcolePay…"
                          x-data x-on:input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,160)+'px'"
                          x-on:keydown.enter.prevent="if(!$event.shiftKey){ $wire.send() }"
                          class="max-h-40 flex-1 resize-none border-none bg-transparent py-1 text-left text-[14px] text-ink-900 outline-none placeholder:text-ink-400"></textarea>
                <button type="submit" wire:loading.attr="disabled" wire:target="send,ask,briefing"
                        class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50">
                    <svg wire:loading.remove wire:target="send,ask,briefing" width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M10 16V5M5 10l5-5 5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <svg wire:loading wire:target="send,ask,briefing" width="15" height="15" viewBox="0 0 20 20" fill="none" class="animate-spin"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" stroke-opacity="0.3"/><path d="M17 10a7 7 0 00-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </button>
            </form>

            {{-- Briefing du jour --}}
            <button wire:click="briefing" wire:loading.attr="disabled" wire:target="briefing,send,ask"
                    class="mx-auto mt-3 flex w-full max-w-[640px] items-center gap-3 rounded-[14px] border border-brand-200 bg-brand-50 px-4 py-3 text-left hover:bg-brand-100/50">
                <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-[10px] bg-white"><x-ai.mascot :size="24" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block text-[13.5px] font-bold text-brand-800">Briefing du jour</span>
                    <span class="block text-[12px] text-brand-700/80">Les points clés de l'adoption EcolePay, analysés automatiquement ce matin.</span>
                </span>
                <span class="flex-shrink-0 text-[12px] font-semibold text-brand-600">Voir →</span>
            </button>

            {{-- Suggestions --}}
            <div class="mx-auto mt-6 flex max-w-[720px] flex-wrap justify-center gap-2">
                @foreach ($suggestions as $sug)
                    <button wire:click="ask(@js($sug))" wire:loading.attr="disabled" wire:target="send,ask,briefing"
                            class="rounded-full border border-ink-200 bg-white px-3.5 py-2 text-[12.5px] text-ink-700 hover:border-brand-300 hover:bg-brand-50/40">{{ $sug }}</button>
                @endforeach
            </div>

            {{-- Analyses proposées --}}
            <div class="mt-8">
                <div class="mb-2.5 text-[11px] font-bold uppercase tracking-wider text-ink-400">Analyses proposées</div>
                <div class="mx-auto flex max-w-[720px] flex-wrap justify-center gap-2">
                    @foreach ($analyses as $label => $prompt)
                        <button wire:click="ask(@js($prompt))" wire:loading.attr="disabled" wire:target="send,ask,briefing"
                                class="inline-flex items-center gap-1.5 rounded-full bg-ink-100 px-3.5 py-2 text-[12.5px] font-medium text-ink-700 hover:bg-brand-50 hover:text-brand-700">
                            <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M4 16V4M4 16h12M8 13V9M11 13V6M14 13v-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

    @else
        {{-- Conversation active --}}
        <div class="flex h-[calc(100vh-11rem)] flex-col rounded-[18px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div x-ref="thread" class="flex-1 space-y-5 overflow-y-auto p-5 sm:p-6">
                @foreach ($msgs as $m)
                    @if ($m->role === 'user')
                        <div wire:key="m-{{ $m->id }}" class="flex justify-end">
                            <div class="max-w-[85%] rounded-2xl rounded-br-md bg-brand-600 px-4 py-2.5 text-[13.5px] leading-relaxed text-white">{{ $m->content }}</div>
                        </div>
                    @else
                        <div wire:key="m-{{ $m->id }}" class="flex gap-3">
                            <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full {{ ($m->meta['error'] ?? null) ? 'bg-[#FDECEC]' : 'bg-white ring-1 ring-ink-200' }}"><x-ai.mascot :size="22" /></span>
                            <div class="min-w-0 flex-1">
                                @if ($m->meta['error'] ?? null)
                                    <div class="rounded-2xl rounded-tl-md border border-danger/20 bg-[#FDF2F2] px-4 py-3 text-[13px] leading-relaxed text-[#8A1C1C]">{{ $m->content }}</div>
                                @else
                                    <div class="ai-prose max-w-none rounded-2xl rounded-tl-md bg-ink-50 px-4 py-3 text-[13.5px] leading-relaxed text-ink-800">{!! Str::markdown($m->content) !!}</div>
                                    @if ($m->meta['model'] ?? null)
                                        <div class="mt-1 pl-1 text-[10.5px] text-ink-400">{{ $m->meta['model'] }}</div>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @endif
                @endforeach

                <div wire:loading.flex wire:target="send,ask,briefing" class="flex gap-3">
                    <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-white ring-1 ring-ink-200"><x-ai.mascot :size="22" /></span>
                    <div class="flex items-center gap-1.5 rounded-2xl rounded-tl-md bg-ink-50 px-4 py-3.5">
                        <span class="h-2 w-2 animate-bounce rounded-full bg-ink-400" style="animation-delay:0ms"></span>
                        <span class="h-2 w-2 animate-bounce rounded-full bg-ink-400" style="animation-delay:150ms"></span>
                        <span class="h-2 w-2 animate-bounce rounded-full bg-ink-400" style="animation-delay:300ms"></span>
                    </div>
                </div>
            </div>

            <div class="border-t border-ink-150 p-3 sm:p-4">
                <form wire:submit="send" class="flex items-end gap-2 rounded-[14px] border border-ink-300 bg-white px-3 py-2 focus-within:border-brand-600">
                    <textarea wire:model="draft" rows="1" placeholder="Écrivez votre question…"
                              x-data x-on:input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,160)+'px'"
                              x-on:keydown.enter.prevent="if(!$event.shiftKey){ $wire.send() }"
                              class="max-h-40 flex-1 resize-none border-none bg-transparent py-1.5 text-[13.5px] text-ink-900 outline-none placeholder:text-ink-400"></textarea>
                    <button type="submit" wire:loading.attr="disabled" wire:target="send,ask,briefing"
                            class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-[10px] bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50">
                        <svg wire:loading.remove wire:target="send,ask,briefing" width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M3 10l14-6-6 14-2-6z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                        <svg wire:loading wire:target="send,ask,briefing" width="16" height="16" viewBox="0 0 20 20" fill="none" class="animate-spin"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" stroke-opacity="0.3"/><path d="M17 10a7 7 0 00-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </button>
                </form>
                <p class="mt-1.5 px-1 text-center text-[10.5px] text-ink-400">L'assistant peut se tromper. Vérifiez les décisions importantes. Entrée pour envoyer, Maj+Entrée pour un saut de ligne.</p>
            </div>
        </div>
    @endif
</div>
