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

    public function mount(): void
    {
        $this->userId = CurrentUser::resolve()->id;

        if ($this->conversationId && ! AiConversation::where('user_id', $this->userId)->whereKey($this->conversationId)->exists()) {
            $this->conversationId = null;
        }
        if (! $this->conversationId) {
            $this->conversationId = AiConversation::where('user_id', $this->userId)->latest('updated_at')->value('id');
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
        return AiConversation::where('user_id', $this->userId)->latest('updated_at')->take(40)->get();
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
    }

    public function openConversation(int $id): void
    {
        if (AiConversation::where('user_id', $this->userId)->whereKey($id)->exists()) {
            $this->conversationId = $id;
        }
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
                'no_key' => "Aucune clé API n'est configurée. Renseignez votre clé dans Paramètres › Assistant IA.",
                'auth' => "La clé API a été refusée. Vérifiez-la dans Paramètres › Assistant IA.",
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

    $suggestions = [
        "Quel est le taux d'adoption global et son évolution ?",
        "Quelles écoles sont critiques et à accompagner en priorité ?",
        "Où se trouve le plus fort potentiel de revenu ?",
        "Quelles opérations marketing ont le mieux converti ?",
    ];
    $msgs = $this->messages;
@endphp

<div class="mx-auto flex h-[calc(100vh-8.5rem)] max-w-[1280px] gap-5"
     x-data="{ scroll() { this.$nextTick(() => { const b = this.$refs.thread; if (b) b.scrollTop = b.scrollHeight; }); } }"
     x-init="scroll()"
     @ai-scroll.window="scroll()">

    {{-- Historique des conversations --}}
    <aside class="hidden w-64 flex-shrink-0 flex-col rounded-[16px] border border-ink-200 bg-white p-3 shadow-[0_1px_2px_rgba(15,23,42,0.03)] lg:flex">
        <button wire:click="newConversation" class="mb-3 inline-flex items-center justify-center gap-2 rounded-[10px] bg-brand-600 px-3.5 py-2.5 text-[13px] font-semibold text-white hover:bg-brand-700">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            Nouvelle conversation
        </button>
        <div class="flex-1 space-y-0.5 overflow-y-auto">
            @forelse ($this->conversations as $c)
                <div wire:key="conv-{{ $c->id }}" class="group flex items-center gap-1 rounded-[9px] {{ $conversationId === $c->id ? 'bg-brand-50' : 'hover:bg-ink-100' }}">
                    <button wire:click="openConversation({{ $c->id }})" class="flex-1 truncate px-2.5 py-2 text-left text-[12.5px] font-medium {{ $conversationId === $c->id ? 'text-brand-700' : 'text-ink-700' }}">{{ $c->title }}</button>
                    <button wire:click="deleteConversation({{ $c->id }})" class="mr-1 hidden h-6 w-6 flex-shrink-0 items-center justify-center rounded text-ink-400 hover:text-danger group-hover:flex" title="Supprimer">
                        <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M5 6h10M8 6V4h4v2M6 6l1 10h6l1-10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </div>
            @empty
                <p class="px-2 py-4 text-center text-[12px] text-ink-400">Aucune conversation.</p>
            @endforelse
        </div>
        <div class="mt-2 border-t border-ink-150 pt-2 text-[10.5px] text-ink-400">Réponses générées par Claude, ancrées sur vos données EcolePay.</div>
    </aside>

    {{-- Zone de conversation --}}
    <div class="flex min-w-0 flex-1 flex-col rounded-[16px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">

        @if (! $this->configured)
            {{-- État : non configuré --}}
            <div class="flex flex-1 flex-col items-center justify-center gap-4 p-8 text-center">
                <span class="flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                    <svg width="30" height="30" viewBox="0 0 20 20" fill="none"><rect x="3.5" y="6" width="13" height="9" rx="3" stroke="currentColor" stroke-width="1.5"/><line x1="10" y1="2.5" x2="10" y2="6" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="2" r="1.2" fill="currentColor"/><circle cx="7.3" cy="10.5" r="1.3" fill="currentColor"/><circle cx="12.7" cy="10.5" r="1.3" fill="currentColor"/></svg>
                </span>
                <div>
                    <div class="text-[17px] font-bold text-ink-900">Connectez l'Assistant IA</div>
                    <p class="mx-auto mt-1.5 max-w-md text-[13px] leading-relaxed text-ink-500">
                        L'Assistant IA répond à vos questions métier à partir de vos vraies données EcolePay, propulsé par Claude. Renseignez votre clé API Anthropic pour l'activer.
                    </p>
                </div>
                <a href="{{ route('settings.index', ['section' => 'assistant']) }}" wire:navigate class="inline-flex items-center gap-2 rounded-[10px] bg-brand-600 px-4 py-2.5 text-[13px] font-semibold text-white hover:bg-brand-700">
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M10 3v2M10 15v2M3 10h2M15 10h2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    Configurer la clé API
                </a>
            </div>
        @else
            {{-- Fil de messages --}}
            <div x-ref="thread" class="flex-1 space-y-5 overflow-y-auto p-5 sm:p-6">
                @if ($msgs->isEmpty())
                    {{-- Accueil : briefing + suggestions --}}
                    <div class="mx-auto max-w-2xl py-6 text-center">
                        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                            <svg width="26" height="26" viewBox="0 0 20 20" fill="none"><rect x="3.5" y="6" width="13" height="9" rx="3" stroke="currentColor" stroke-width="1.5"/><line x1="10" y1="2.5" x2="10" y2="6" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="2" r="1.2" fill="currentColor"/><circle cx="7.3" cy="10.5" r="1.3" fill="currentColor"/><circle cx="12.7" cy="10.5" r="1.3" fill="currentColor"/></svg>
                        </span>
                        <h1 class="mt-3 text-[20px] font-bold tracking-tight text-ink-900">Comment puis-je vous aider ?</h1>
                        <p class="mt-1 text-[13px] text-ink-500">Posez une question sur l'adoption, les écoles, les campagnes ou les revenus.</p>

                        <button wire:click="briefing" wire:loading.attr="disabled" wire:target="briefing,send"
                                class="mx-auto mt-5 flex items-center gap-2.5 rounded-[12px] border border-brand-200 bg-brand-50 px-4 py-3 text-left hover:bg-brand-100/60">
                            <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-[10px] bg-brand-600 text-white"><svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M4 10h3l2-5 2 10 2-5h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <span>
                                <span class="block text-[13.5px] font-bold text-brand-800">Briefing du jour</span>
                                <span class="block text-[12px] text-brand-700/80">Synthèse : situation, priorités et actions recommandées.</span>
                            </span>
                        </button>

                        <div class="mt-6 grid gap-2 sm:grid-cols-2">
                            @foreach ($suggestions as $sug)
                                <button wire:click="suggest(@js($sug))" class="rounded-[11px] border border-ink-200 bg-white px-3.5 py-3 text-left text-[12.5px] text-ink-700 hover:border-brand-300 hover:bg-brand-50/30">{{ $sug }}</button>
                            @endforeach
                        </div>
                    </div>
                @else
                    @foreach ($msgs as $m)
                        @if ($m->role === 'user')
                            <div wire:key="m-{{ $m->id }}" class="flex justify-end">
                                <div class="max-w-[85%] rounded-2xl rounded-br-md bg-brand-600 px-4 py-2.5 text-[13.5px] leading-relaxed text-white">{{ $m->content }}</div>
                            </div>
                        @else
                            <div wire:key="m-{{ $m->id }}" class="flex gap-3">
                                <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full {{ ($m->meta['error'] ?? null) ? 'bg-[#FDECEC] text-danger' : 'bg-brand-50 text-brand-600' }}">
                                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><rect x="3.5" y="6" width="13" height="9" rx="3" stroke="currentColor" stroke-width="1.5"/><line x1="10" y1="2.5" x2="10" y2="6" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="2" r="1.1" fill="currentColor"/><circle cx="7.3" cy="10.5" r="1.2" fill="currentColor"/><circle cx="12.7" cy="10.5" r="1.2" fill="currentColor"/></svg>
                                </span>
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
                @endif

                {{-- Indicateur « en train de répondre » --}}
                <div wire:loading.flex wire:target="send,briefing" class="flex gap-3">
                    <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600">
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><rect x="3.5" y="6" width="13" height="9" rx="3" stroke="currentColor" stroke-width="1.5"/><line x1="10" y1="2.5" x2="10" y2="6" stroke="currentColor" stroke-width="1.5"/></svg>
                    </span>
                    <div class="flex items-center gap-1.5 rounded-2xl rounded-tl-md bg-ink-50 px-4 py-3.5">
                        <span class="h-2 w-2 animate-bounce rounded-full bg-ink-400" style="animation-delay:0ms"></span>
                        <span class="h-2 w-2 animate-bounce rounded-full bg-ink-400" style="animation-delay:150ms"></span>
                        <span class="h-2 w-2 animate-bounce rounded-full bg-ink-400" style="animation-delay:300ms"></span>
                    </div>
                </div>
            </div>

            {{-- Champ de saisie --}}
            <div class="border-t border-ink-150 p-3 sm:p-4">
                <form wire:submit="send" class="flex items-end gap-2 rounded-[14px] border border-ink-300 bg-white px-3 py-2 focus-within:border-brand-600">
                    <textarea wire:model="draft" rows="1" placeholder="Écrivez votre question…"
                              x-data x-on:input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,160)+'px'"
                              x-on:keydown.enter.prevent="if(!$event.shiftKey){ $wire.send() }"
                              class="max-h-40 flex-1 resize-none border-none bg-transparent py-1.5 text-[13.5px] text-ink-900 outline-none placeholder:text-ink-400"></textarea>
                    <button type="submit" wire:loading.attr="disabled" wire:target="send,briefing"
                            class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-[10px] bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50">
                        <svg wire:loading.remove wire:target="send,briefing" width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M3 10l14-6-6 14-2-6z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                        <svg wire:loading wire:target="send,briefing" width="16" height="16" viewBox="0 0 20 20" fill="none" class="animate-spin"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" stroke-opacity="0.3"/><path d="M17 10a7 7 0 00-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </button>
                </form>
                <p class="mt-1.5 px-1 text-center text-[10.5px] text-ink-400">L'assistant peut se tromper. Vérifiez les décisions importantes. Entrée pour envoyer, Maj+Entrée pour un saut de ligne.</p>
            </div>
        @endif
    </div>
</div>
