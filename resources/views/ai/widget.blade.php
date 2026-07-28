<?php

use App\Domains\AI\Actions\AskClaude;
use App\Domains\AI\Models\AiConversation;
use App\Domains\AI\Models\AiMessage;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Settings\Support\Settings;
use App\Domains\Users\Support\CurrentUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Bot flottant présent sur toutes les pages : question rapide sur l'écran courant
 * sans passer par le module Assistant IA. Réutilise l'API Claude (AskClaude) avec
 * un contexte de page, persiste l'historique (conversations `source = widget`) et
 * affiche un badge quand des recommandations (alertes actives) existent.
 */
new class extends Component
{
    public int $userId;

    public string $draft = '';

    public ?int $conversationId = null;

    public string $pageLabel = '';

    public string $pageRoute = '';

    public function mount(): void
    {
        $this->userId = CurrentUser::resolve()->id;
        $this->pageRoute = request()->route()?->getName() ?? '';
        $this->pageLabel = $this->labelFor($this->pageRoute);
        $this->conversationId = AiConversation::where('user_id', $this->userId)
            ->where('source', 'widget')->latest('updated_at')->value('id');
    }

    #[Computed]
    public function configured(): bool
    {
        return app(AskClaude::class)->isConfigured() && (bool) Settings::get('ai_enabled', true);
    }

    #[Computed]
    public function messages(): Collection
    {
        return $this->conversationId
            ? AiMessage::where('conversation_id', $this->conversationId)->orderBy('id')->get()
            : collect();
    }

    /** Nombre de recommandations = alertes actives détectées par la plateforme. */
    #[Computed]
    public function suggestionCount(): int
    {
        if (! Schema::hasTable('eac_notifications')) {
            return 0;
        }

        return (int) Notification::where('status', '!=', 'resolved')->count();
    }

    #[Computed]
    public function topSuggestions(): Collection
    {
        if (! Schema::hasTable('eac_notifications')) {
            return collect();
        }

        return Notification::where('status', '!=', 'resolved')
            ->orderByRaw("CASE priority WHEN 'critique' THEN 1 WHEN 'haute' THEN 2 WHEN 'moyenne' THEN 3 WHEN 'faible' THEN 4 ELSE 5 END")
            ->orderByDesc('detected_at')->take(3)->get();
    }

    public function newChat(): void
    {
        $this->conversationId = null;
        $this->draft = '';
        unset($this->messages);
    }

    public function ask(string $text): void
    {
        $this->draft = $text;
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
                'user_id' => $this->userId, 'source' => 'widget',
                'context' => $this->pageLabel, 'title' => Str::limit($text, 40),
            ]);
            $this->conversationId = $conversation->id;
        }

        AiMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => $text]);
        $this->draft = '';
        unset($this->messages);

        $history = AiMessage::where('conversation_id', $conversation->id)->orderBy('id')->get()
            ->reject(fn ($m) => ($m->meta['error'] ?? null))
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()->all();

        $note = "L'utilisateur consulte actuellement l'écran « {$this->pageLabel} » de la plateforme. Oriente ta réponse vers cet écran quand c'est pertinent.";
        $res = app(AskClaude::class)($history, $note);

        if ($res['ok']) {
            AiMessage::create([
                'conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => $res['text'],
                'meta' => ['model' => $res['model'] ?? null],
            ]);
        } else {
            $msg = match ($res['error'] ?? 'api') {
                'no_key' => "Aucune clé API n'est configurée. Renseignez-la dans Paramètres › KATIA.",
                'auth' => "La clé API a été refusée. Vérifiez-la dans Paramètres › KATIA.",
                'rate_limit' => 'Limite de requêtes atteinte. Réessayez dans un instant.',
                'connection' => "Impossible de joindre l'API Claude.",
                'refusal' => 'Demande déclinée par les protections du modèle.',
                default => "Une erreur est survenue lors de l'appel à l'API Claude.",
            };
            AiMessage::create([
                'conversation_id' => $conversation->id, 'role' => 'assistant',
                'content' => $msg, 'meta' => ['error' => $res['error'] ?? 'api'],
            ]);
        }

        $conversation->touch();
        unset($this->messages);
        $this->dispatch('ai-widget-scroll');
    }

    private function labelFor(string $route): string
    {
        $map = [
            'dashboard.index' => 'Dashboard exécutif',
            'schools.index' => 'Écoles', 'schools.show' => "Fiche d'une école",
            'geography.index' => 'Carte de répartition',
            'parents.index' => 'Parents',
            'campaigns.index' => 'Campagnes', 'campaigns.show' => 'Campagne',
            'analytics.index' => 'Analytics', 'analytics.lab' => "Laboratoire d'analyses",
            'reports.index' => 'Rapports', 'reports.show' => 'Rapport',
            'notifications.index' => 'Notifications & alertes',
            'activity.index' => "Journal d'activité",
            'users.index' => 'Utilisateurs & rôles',
            'settings.index' => 'Paramètres',
            'profile.index' => 'Mon profil',
            'help.index' => "Centre d'aide",
        ];

        return $map[$route] ?? 'Adoption Center';
    }
};

?>

@php
    // Redondant sur la page Assistant IA elle-même : on n'affiche pas le bot.
    $hidden = str_starts_with($this->pageRoute, 'assistant');
    $count = $this->suggestionCount;
    $prioColor = ['critique' => '#DC2626', 'haute' => '#D97706', 'moyenne' => '#2554C7', 'faible' => '#5B6472'];
    $pageSuggestions = [
        'Résume cette page en 3 points clés.',
        'Quels sont les points d’attention ici ?',
        'Quelle action prioritaire me recommandes-tu ?',
    ];
    $msgs = $this->messages;
@endphp

<div>
@unless ($hidden)
    <div x-data="{ open: false, scroll() { this.$nextTick(() => { const b = this.$refs.wthread; if (b) b.scrollTop = b.scrollHeight; }); } }"
         x-init="$watch('open', v => v && scroll())"
         @ai-widget-scroll.window="scroll()"
         @ai-widget-open.window="open = true; scroll()"
         class="fixed bottom-5 right-5 z-[60] flex flex-col items-end gap-3" x-cloak>

        {{-- Panneau de conversation --}}
        <div x-show="open"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-4 opacity-0 scale-95" x-transition:enter-end="translate-y-0 opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
             class="flex h-[580px] max-h-[calc(100vh-7rem)] w-[min(384px,calc(100vw-2.5rem))] flex-col overflow-hidden rounded-[18px] border border-ink-200 bg-white shadow-[0_16px_48px_rgba(15,23,42,0.22)]"
             style="transform-origin: bottom right">

            {{-- En-tête --}}
            <div class="flex items-center gap-2.5 border-b border-ink-150 bg-gradient-to-r from-brand-600 to-[#1D3F9C] px-4 py-3 text-white">
                <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-white/15"><x-ai.mascot :size="26" variant="white" /></span>
                <div class="min-w-0 flex-1">
                    <div class="text-[13.5px] font-bold leading-tight">KATIA</div>
                    <div class="truncate text-[11px] text-white/75">sur : {{ $this->pageLabel }}</div>
                </div>
                @if (! $msgs->isEmpty())
                    <button wire:click="newChat" title="Nouvelle question" class="flex h-8 w-8 items-center justify-center rounded-lg text-white/85 hover:bg-white/15"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
                @endif
                <a href="{{ route('assistant.index') }}" title="Ouvrir en plein écran" class="flex h-8 w-8 items-center justify-center rounded-lg text-white/85 hover:bg-white/15"><svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M8 4H4v4M12 16h4v-4M4 12v4h4M16 8V4h-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
                <button @click="open = false" class="flex h-8 w-8 items-center justify-center rounded-lg text-white/85 hover:bg-white/15"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
            </div>

            {{-- Corps --}}
            <div x-ref="wthread" class="flex-1 space-y-3 overflow-y-auto bg-ink-50/40 p-3.5">
                @if (! $this->configured)
                    <div class="flex h-full flex-col items-center justify-center gap-3 px-4 text-center">
                        <x-ai.mascot :size="52" />
                        <div class="text-[13.5px] font-bold text-ink-900">KATIA à connecter</div>
                        <p class="text-[12px] leading-relaxed text-ink-500">Renseignez votre clé API Anthropic pour poser des questions sur vos données.</p>
                        <a href="{{ route('settings.index', ['section' => 'assistant']) }}" class="rounded-[9px] bg-brand-600 px-3.5 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700">Configurer la clé API</a>
                    </div>
                @elseif ($msgs->isEmpty())
                    <div class="px-1 pt-1 text-center">
                        <x-ai.mascot :size="46" class="mx-auto" />
                        <div class="mt-2 text-[14px] font-bold text-ink-900">Une question sur cette page ?</div>
                        <p class="mx-auto mt-1 max-w-[15rem] text-[12px] leading-relaxed text-ink-500">Je réponds à partir de vos vraies données EcolePay, dans le contexte de « {{ $this->pageLabel }} ».</p>
                    </div>

                    @if ($count > 0)
                        <div class="rounded-[12px] border border-[#FCE4C4] bg-[#FEF6EA] p-3">
                            <div class="mb-1.5 flex items-center gap-1.5 text-[12px] font-bold text-[#8A5A06]">
                                <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M10 3l7 12H3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M10 8v3.5M10 13.5h.01" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                                {{ $count }} point{{ $count > 1 ? 's' : '' }} d’attention détecté{{ $count > 1 ? 's' : '' }}
                            </div>
                            <div class="space-y-1">
                                @foreach ($this->topSuggestions as $sug)
                                    <button wire:click="ask('Explique-moi ce point et que dois-je faire : {{ addslashes($sug->title) }}')"
                                            class="flex w-full items-start gap-1.5 rounded-lg px-1.5 py-1 text-left text-[12px] text-ink-700 hover:bg-white">
                                        <span class="mt-1 h-1.5 w-1.5 flex-shrink-0 rounded-full" style="background: {{ $prioColor[$sug->priority] ?? '#5B6472' }}"></span>
                                        <span class="leading-snug">{{ $sug->title }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="space-y-1.5">
                        @foreach ($pageSuggestions as $sug)
                            <button wire:click="ask(@js($sug))" class="w-full rounded-[10px] border border-ink-200 bg-white px-3 py-2 text-left text-[12px] text-ink-700 hover:border-brand-300 hover:bg-brand-50/40">{{ $sug }}</button>
                        @endforeach
                    </div>
                @else
                    @foreach ($msgs as $m)
                        @if ($m->role === 'user')
                            <div wire:key="wm-{{ $m->id }}" class="flex justify-end">
                                <div class="max-w-[85%] rounded-2xl rounded-br-md bg-brand-600 px-3 py-2 text-[12.5px] leading-relaxed text-white">{{ $m->content }}</div>
                            </div>
                        @else
                            <div wire:key="wm-{{ $m->id }}" class="flex gap-2">
                                <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full {{ ($m->meta['error'] ?? null) ? 'bg-[#FDECEC]' : 'bg-white ring-1 ring-ink-200' }}"><x-ai.mascot :size="20" /></span>
                                @if ($m->meta['error'] ?? null)
                                    <div class="rounded-2xl rounded-tl-md border border-danger/20 bg-[#FDF2F2] px-3 py-2 text-[12px] leading-relaxed text-[#8A1C1C]">{{ $m->content }}</div>
                                @else
                                    <div class="ai-prose min-w-0 flex-1 rounded-2xl rounded-tl-md bg-white px-3 py-2 text-[12.5px] leading-relaxed text-ink-800 ring-1 ring-ink-150">{!! Str::markdown($m->content) !!}</div>
                                @endif
                            </div>
                        @endif
                    @endforeach
                @endif

                {{-- Indicateur de frappe --}}
                <div wire:loading.flex wire:target="send,ask" class="flex gap-2">
                    <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-white ring-1 ring-ink-200"><x-ai.mascot :size="20" /></span>
                    <div class="flex items-center gap-1.5 rounded-2xl rounded-tl-md bg-white px-3 py-2.5 ring-1 ring-ink-150">
                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-ink-400" style="animation-delay:0ms"></span>
                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-ink-400" style="animation-delay:150ms"></span>
                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-ink-400" style="animation-delay:300ms"></span>
                    </div>
                </div>
            </div>

            {{-- Saisie --}}
            @if ($this->configured)
                <form wire:submit="send" class="flex items-end gap-2 border-t border-ink-150 bg-white p-2.5">
                    <textarea wire:model="draft" rows="1" placeholder="Poser une question…"
                              x-data x-on:input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,120)+'px'"
                              x-on:keydown.enter.prevent="if(!$event.shiftKey){ $wire.send() }"
                              class="max-h-28 flex-1 resize-none rounded-[11px] border border-ink-300 bg-white px-3 py-2 text-[12.5px] text-ink-900 outline-none focus:border-brand-600 placeholder:text-ink-400"></textarea>
                    <button type="submit" wire:loading.attr="disabled" wire:target="send,ask" class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-[11px] bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50">
                        <svg wire:loading.remove wire:target="send,ask" width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M3 10l14-6-6 14-2-6z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                        <svg wire:loading wire:target="send,ask" width="15" height="15" viewBox="0 0 20 20" fill="none" class="animate-spin"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" stroke-opacity="0.3"/><path d="M17 10a7 7 0 00-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </button>
                </form>
            @endif
        </div>

        {{-- Bouton flottant --}}
        <button @click="open = ! open" aria-label="KATIA"
                class="relative flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-[#1D3F9C] shadow-[0_8px_24px_rgba(37,84,199,0.45)] transition-transform hover:scale-105 active:scale-95">
            <span x-show="!open"><x-ai.mascot :size="34" variant="white" /></span>
            <svg x-show="open" x-cloak width="22" height="22" viewBox="0 0 20 20" fill="none" class="text-white"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            @if ($count > 0)
                <span x-show="!open" class="absolute -right-0.5 -top-0.5 flex h-5 min-w-[20px] items-center justify-center rounded-full border-2 border-white bg-danger px-1 text-[10.5px] font-bold text-white">{{ $count > 9 ? '9+' : $count }}</span>
            @endif
        </button>
    </div>
@endunless
</div>
