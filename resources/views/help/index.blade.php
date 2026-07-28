<?php

use App\Domains\Help\Models\HelpMessage;
use App\Domains\Help\Support\HelpContent;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $q = '';

    #[Url]
    public string $article = '';

    #[Url]
    public string $cat = '';

    public bool $supportOpen = false;

    public array $support = ['type' => 'probleme', 'subject' => '', 'body' => ''];

    // Retour d'article (« cette réponse vous a-t-elle aidé ? »).
    public array $rated = [];

    public bool $askComment = false;

    public string $feedbackComment = '';

    public string $flash = '';

    #[Computed]
    public function categories(): array
    {
        $articles = HelpContent::articles();

        return array_map(function ($c) use ($articles) {
            $c['count'] = collect($articles)->where('category', $c['key'])->count();

            return $c;
        }, HelpContent::categories());
    }

    #[Computed]
    public function openArticle(): ?array
    {
        return HelpContent::articles()[$this->article] ?? null;
    }

    #[Computed]
    public function results(): array
    {
        $q = mb_strtolower(trim($this->q));
        if ($q === '') {
            return [];
        }

        $match = fn (string ...$fields) => str_contains(mb_strtolower(implode(' ', $fields)), $q);

        return [
            'guides' => array_values(array_filter(HelpContent::guides(), fn ($g) => $match($g['title'], $g['excerpt']))),
            'glossary' => array_values(array_filter(HelpContent::glossary(), fn ($g) => $match($g['term'], $g['def']))),
            'faq' => array_values(array_filter(HelpContent::faq(), fn ($f) => $match($f['q'], $f['a']))),
            'docs' => array_values(array_filter(HelpContent::techDocs(), fn ($d) => $match($d['title'], $d['excerpt']))),
        ];
    }

    public function resultCount(): int
    {
        return collect($this->results)->flatten(1)->count();
    }

    public function open(string $key): void
    {
        $this->article = $key;
        $this->askComment = false;
        $this->feedbackComment = '';
    }

    public function closeArticle(): void
    {
        $this->article = '';
    }

    public function submitSupport(): void
    {
        $this->validate([
            'support.type' => 'required|string',
            'support.subject' => 'required|string|max:120',
            'support.body' => 'required|string|max:2000',
        ]);

        HelpMessage::create([
            'kind' => 'support',
            'category' => $this->support['type'],
            'subject' => $this->support['subject'],
            'body' => $this->support['body'],
        ]);

        $this->support = ['type' => 'probleme', 'subject' => '', 'body' => ''];
        $this->supportOpen = false;
        $this->flash = 'Votre demande a été enregistrée. Le support reviendra vers vous.';
        $this->dispatch('help-flash');
    }

    public function rate(string $key, bool $helpful): void
    {
        if ($helpful) {
            HelpMessage::create(['kind' => 'feedback', 'article_key' => $key, 'helpful' => true]);
            $this->rated[$key] = 'up';
            $this->askComment = false;
        } else {
            $this->rated[$key] = 'down';
            $this->askComment = true;
        }
    }

    public function submitComment(string $key): void
    {
        HelpMessage::create([
            'kind' => 'feedback',
            'article_key' => $key,
            'helpful' => false,
            'body' => $this->feedbackComment ?: null,
        ]);
        $this->askComment = false;
        $this->feedbackComment = '';
        $this->flash = 'Merci, votre retour est enregistré.';
        $this->dispatch('help-flash');
    }
};

?>

@php
    $levelColor = [
        'Débutant' => ['#0F7A44', '#E7F6EE'],
        'Intermédiaire' => ['#B45F04', '#FEF3E2'],
        'Avancé' => ['#8A1C6B', '#FBEAF5'],
        'Admin' => ['#1D3F9C', '#EEF3FE'],
    ];
    $changeType = [
        'feature' => ['Nouveauté', '#2554C7', '#EEF3FE'],
        'amelioration' => ['Amélioration', '#0F7A44', '#E7F6EE'],
        'correctif' => ['Correctif', '#B45F04', '#FEF3E2'],
    ];
    $art = $this->openArticle;
@endphp

<div class="mx-auto max-w-[1080px]"
     x-data="{ toast: false, msg: '' }"
     @help-flash.window="msg = $wire.flash; toast = true; clearTimeout(window._ht); window._ht = setTimeout(() => toast = false, 3200)">

    {{-- Toast --}}
    <div x-show="toast" x-cloak x-transition
         class="fixed bottom-6 right-6 z-50 flex items-center gap-2.5 rounded-xl bg-[#189B57] px-4 py-3 text-[13px] font-semibold text-white shadow-lg">
        <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M4 10l4 4 8-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span x-text="msg"></span>
    </div>

    {{-- ============================ LECTEUR D'ARTICLE ============================ --}}
    @if ($this->article !== '')
        <button wire:click="closeArticle" class="mb-4 inline-flex items-center gap-1.5 text-[13px] font-semibold text-ink-600 hover:text-ink-900">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M12 5l-5 5 5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Retour au centre d'aide
        </button>

        @if ($art)
            @php [$lf, $lb] = $levelColor[$art['level']] ?? ['#5B6472', '#F2F3F5']; @endphp
            <article class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)] sm:p-8">
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-2.5 py-1 text-[11px] font-bold" style="background: {{ $lb }}; color: {{ $lf }}">{{ $art['level'] }}</span>
                    <span class="text-[12px] text-ink-500">{{ $art['minutes'] }} min de lecture</span>
                    <span class="text-ink-300">·</span>
                    <span class="text-[12px] text-ink-500">Mis à jour le {{ \Illuminate\Support\Carbon::parse($art['updated'])->translatedFormat('d M Y') }}</span>
                    @if (($art['kind'] ?? '') === 'doc')<span class="rounded-full bg-ink-100 px-2.5 py-1 text-[11px] font-bold text-ink-600">Administrateurs</span>@endif
                </div>
                <h1 class="text-[24px] font-bold tracking-tight text-ink-900">{{ $art['title'] }}</h1>

                <div class="mt-6 flex flex-col gap-4">
                    @foreach ($art['body'] as $block)
                        @if (isset($block['p']))
                            <p class="text-[14.5px] leading-relaxed text-ink-700">{{ $block['p'] }}</p>
                        @elseif (isset($block['h']))
                            <h2 class="mt-2 text-[16px] font-bold text-ink-900">{{ $block['h'] }}</h2>
                        @elseif (isset($block['steps']))
                            <ol class="flex flex-col gap-2.5">
                                @foreach ($block['steps'] as $i => $step)
                                    <li class="flex gap-3">
                                        <span class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-brand-50 text-[12px] font-bold text-brand-700">{{ $i + 1 }}</span>
                                        <span class="pt-0.5 text-[14px] leading-relaxed text-ink-700">{{ $step }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        @elseif (isset($block['note']))
                            <div class="flex items-start gap-2 rounded-[11px] border border-brand-200 bg-brand-50 px-4 py-3 text-[13px] leading-relaxed text-brand-800">
                                <svg width="16" height="16" viewBox="0 0 20 20" fill="none" class="mt-0.5 flex-shrink-0"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.4"/><path d="M10 9v4M10 6.5h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                <span>{{ $block['note'] }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>

                {{-- Évaluation --}}
                <div class="mt-8 border-t border-ink-150 pt-5">
                    @if (($this->rated[$this->article] ?? '') === 'up')
                        <p class="text-[13.5px] font-semibold text-[#0F7A44]">Merci pour votre retour !</p>
                    @elseif ($this->askComment)
                        <div class="flex flex-col gap-2">
                            <p class="text-[13.5px] font-semibold text-ink-800">Qu'est-ce qui pourrait être amélioré ?</p>
                            <textarea wire:model="feedbackComment" rows="3" class="eac-input" placeholder="Votre commentaire (facultatif)…"></textarea>
                            <div class="flex gap-2">
                                <button wire:click="submitComment('{{ $this->article }}')" class="rounded-[9px] bg-brand-600 px-3.5 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700">Envoyer</button>
                                <button wire:click="$set('askComment', false)" class="rounded-[9px] px-3.5 py-2 text-[12.5px] font-semibold text-ink-500 hover:bg-ink-100">Annuler</button>
                            </div>
                        </div>
                    @else
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-[13.5px] font-semibold text-ink-800">Cette réponse vous a-t-elle aidé ?</span>
                            <button wire:click="rate('{{ $this->article }}', true)" class="inline-flex items-center gap-1.5 rounded-[9px] border border-ink-300 px-3 py-1.5 text-[12.5px] font-semibold text-ink-700 hover:border-[#189B57] hover:bg-[#E7F6EE] hover:text-[#0F7A44]">👍 Oui</button>
                            <button wire:click="rate('{{ $this->article }}', false)" class="inline-flex items-center gap-1.5 rounded-[9px] border border-ink-300 px-3 py-1.5 text-[12.5px] font-semibold text-ink-700 hover:border-danger hover:bg-[#FDECEC] hover:text-danger">👎 Non</button>
                        </div>
                    @endif
                </div>
            </article>
        @else
            {{-- État : article introuvable --}}
            <div class="flex flex-col items-center gap-3 rounded-[16px] border border-ink-200 bg-white py-16 text-center">
                <span class="flex h-14 w-14 items-center justify-center rounded-full bg-ink-100 text-ink-400"><svg width="26" height="26" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v5M10 13.5h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span>
                <div class="text-[15px] font-bold text-ink-800">Article introuvable</div>
                <p class="text-[13px] text-ink-500">Cet article n'existe pas ou a été déplacé.</p>
                <button wire:click="closeArticle" class="mt-1 rounded-[9px] bg-brand-600 px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-700">Retour</button>
            </div>
        @endif

    @else
        {{-- ================================ ACCUEIL ================================ --}}

        {{-- Hero + recherche --}}
        <div class="mb-8 rounded-[18px] border border-ink-200 bg-gradient-to-b from-brand-50 to-white p-6 text-center sm:p-9">
            <h1 class="text-[24px] font-bold tracking-tight text-ink-900 sm:text-[27px]">Centre d'aide</h1>
            <p class="mx-auto mt-2 max-w-xl text-[13.5px] text-ink-600">Retrouvez toute la documentation, les guides et les réponses à vos questions sur l'Adoption Center.</p>
            <div class="mx-auto mt-5 flex max-w-xl items-center gap-2.5 rounded-[13px] border border-ink-300 bg-white px-4 py-3 shadow-sm focus-within:border-brand-600">
                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-500"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="M17 17l-3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                <input wire:model.live.debounce.300ms="q" type="text" placeholder="Que souhaitez-vous apprendre aujourd'hui ?" class="w-full border-none bg-transparent text-[14.5px] text-ink-900 outline-none placeholder:text-ink-400">
                @if ($this->q !== '')
                    <button wire:click="$set('q', '')" class="flex-shrink-0 text-ink-400 hover:text-ink-700"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button>
                @endif
            </div>
            <div class="mt-4 flex flex-wrap items-center justify-center gap-2.5">
                <button wire:click="$set('supportOpen', true)" class="inline-flex items-center gap-1.5 rounded-[9px] border border-ink-300 bg-white px-3.5 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-100">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 3l7 12H3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 8v3M10 12.5h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    Signaler un problème
                </button>
                <button wire:click="$set('supportOpen', true)" class="inline-flex items-center gap-1.5 rounded-[9px] bg-brand-600 px-3.5 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M4 5h12v8H7l-3 3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                    Contacter le support
                </button>
            </div>
        </div>

        @if ($this->q !== '')
            {{-- ============================ RÉSULTATS ============================ --}}
            @php $rc = $this->resultCount(); @endphp
            <div class="mb-4 text-[13px] text-ink-600">{{ $rc }} résultat(s) pour « <span class="font-semibold text-ink-900">{{ $this->q }}</span> »</div>

            @if ($rc === 0)
                <div class="flex flex-col items-center gap-3 rounded-[16px] border border-ink-200 bg-white py-16 text-center">
                    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-ink-100 text-ink-400"><svg width="26" height="26" viewBox="0 0 20 20" fill="none"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M17 17l-4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
                    <div class="text-[15px] font-bold text-ink-800">Aucun résultat</div>
                    <p class="max-w-sm text-[13px] text-ink-500">Essayez d'autres mots-clés, ou contactez le support si vous ne trouvez pas votre réponse.</p>
                    <button wire:click="$set('supportOpen', true)" class="mt-1 rounded-[9px] bg-brand-600 px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-700">Contacter le support</button>
                </div>
            @else
                @if (count($this->results['guides']))
                    <div class="mb-3 text-[12px] font-bold uppercase tracking-wide text-ink-500">Guides</div>
                    <div class="mb-6 flex flex-col gap-2">
                        @foreach ($this->results['guides'] as $g)
                            <button wire:click="open('{{ $g['key'] }}')" class="flex items-center justify-between gap-3 rounded-[12px] border border-ink-200 bg-white p-4 text-left hover:border-brand-300 hover:bg-brand-50/30">
                                <div><div class="text-[14px] font-semibold text-ink-900">{{ $g['title'] }}</div><div class="mt-0.5 text-[12.5px] text-ink-500">{{ $g['excerpt'] }}</div></div>
                                <svg width="16" height="16" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-400"><path d="M8 5l5 5-5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        @endforeach
                    </div>
                @endif
                @if (count($this->results['docs']))
                    <div class="mb-3 text-[12px] font-bold uppercase tracking-wide text-ink-500">Documentation technique</div>
                    <div class="mb-6 flex flex-col gap-2">
                        @foreach ($this->results['docs'] as $d)
                            <button wire:click="open('{{ $d['key'] }}')" class="flex items-center justify-between gap-3 rounded-[12px] border border-ink-200 bg-white p-4 text-left hover:border-brand-300 hover:bg-brand-50/30">
                                <div><div class="text-[14px] font-semibold text-ink-900">{{ $d['title'] }}</div><div class="mt-0.5 text-[12.5px] text-ink-500">{{ $d['excerpt'] }}</div></div>
                                <svg width="16" height="16" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-400"><path d="M8 5l5 5-5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        @endforeach
                    </div>
                @endif
                @if (count($this->results['glossary']))
                    <div class="mb-3 text-[12px] font-bold uppercase tracking-wide text-ink-500">Glossaire</div>
                    <div class="mb-6 grid gap-2 sm:grid-cols-2">
                        @foreach ($this->results['glossary'] as $g)
                            <div class="rounded-[12px] border border-ink-200 bg-white p-4"><div class="text-[13.5px] font-bold text-ink-900">{{ $g['term'] }}</div><div class="mt-1 text-[12.5px] leading-relaxed text-ink-600">{{ $g['def'] }}</div></div>
                        @endforeach
                    </div>
                @endif
                @if (count($this->results['faq']))
                    <div class="mb-3 text-[12px] font-bold uppercase tracking-wide text-ink-500">FAQ</div>
                    <div class="flex flex-col gap-2" x-data="{ open: null }">
                        @foreach ($this->results['faq'] as $i => $f)
                            <div class="rounded-[12px] border border-ink-200 bg-white">
                                <button @click="open = open === {{ $i }} ? null : {{ $i }}" class="flex w-full items-center justify-between gap-3 p-4 text-left text-[13.5px] font-semibold text-ink-900">
                                    {{ $f['q'] }}
                                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-400 transition-transform" :class="{ 'rotate-180': open === {{ $i }} }"><path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <div x-show="open === {{ $i }}" x-collapse><p class="px-4 pb-4 text-[13px] leading-relaxed text-ink-600">{{ $f['a'] }}</p></div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif

        @else
            {{-- ============================== LANDING ============================== --}}

            {{-- Accès rapides --}}
            <section class="mb-9">
                <h2 class="mb-3 text-[15px] font-bold text-ink-900">Accès rapides</h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ($this->categories as $c)
                        <button wire:click="$set('cat', '{{ $c['key'] }}')"
                                class="flex flex-col items-start gap-2.5 rounded-[13px] border p-4 text-left transition-colors {{ $cat === $c['key'] ? 'border-brand-400 bg-brand-50' : 'border-ink-200 bg-white hover:border-brand-300 hover:bg-brand-50/30' }}">
                            <span class="flex h-9 w-9 items-center justify-center rounded-[10px] bg-brand-50 text-brand-700"><svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="{{ $c['icon'] }}" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <span class="text-[13px] font-semibold leading-tight text-ink-900">{{ $c['label'] }}</span>
                            <span class="text-[11.5px] text-ink-500">{{ $c['count'] }} article{{ $c['count'] > 1 ? 's' : '' }}</span>
                        </button>
                    @endforeach
                </div>
            </section>

            {{-- Guides --}}
            <section class="mb-9">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-[15px] font-bold text-ink-900">Guides pas à pas</h2>
                    @if ($cat !== '')
                        @php $catLabel = collect($this->categories)->firstWhere('key', $cat)['label'] ?? $cat; @endphp
                        <button wire:click="$set('cat', '')" class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-2.5 py-1 text-[11.5px] font-semibold text-brand-700 hover:bg-brand-100">{{ $catLabel }} <svg width="12" height="12" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
                    @endif
                </div>
                @php $guides = collect(HelpContent::guides())->when($cat !== '', fn ($c) => $c->where('category', $cat)); @endphp
                @if ($guides->isEmpty())
                    <div class="rounded-[13px] border border-dashed border-ink-300 bg-white p-6 text-center text-[13px] text-ink-500">Aucun guide dans cette catégorie pour le moment.</div>
                @else
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($guides as $g)
                            @php [$lf, $lb] = $levelColor[$g['level']] ?? ['#5B6472', '#F2F3F5']; @endphp
                            <button wire:click="open('{{ $g['key'] }}')" class="flex flex-col gap-2 rounded-[13px] border border-ink-200 bg-white p-4 text-left transition-colors hover:border-brand-300 hover:shadow-sm">
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full px-2 py-0.5 text-[10.5px] font-bold" style="background: {{ $lb }}; color: {{ $lf }}">{{ $g['level'] }}</span>
                                    <span class="text-[11.5px] text-ink-500">{{ $g['minutes'] }} min</span>
                                </div>
                                <div class="text-[14px] font-semibold text-ink-900">{{ $g['title'] }}</div>
                                <div class="text-[12.5px] leading-relaxed text-ink-500">{{ $g['excerpt'] }}</div>
                                <div class="mt-1 text-[11px] text-ink-400">Mis à jour le {{ \Illuminate\Support\Carbon::parse($g['updated'])->translatedFormat('d M Y') }}</div>
                            </button>
                        @endforeach
                    </div>
                @endif
            </section>

            {{-- Glossaire métier --}}
            <section class="mb-9">
                <h2 class="mb-1 text-[15px] font-bold text-ink-900">Glossaire métier</h2>
                <p class="mb-3 text-[12.5px] text-ink-500">Le vocabulaire de l'adoption, illustré.</p>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach (HelpContent::glossary() as $g)
                        <div class="rounded-[13px] border border-ink-200 bg-white p-4">
                            <div class="text-[13.5px] font-bold text-ink-900">{{ $g['term'] }}</div>
                            <div class="mt-1 text-[12.5px] leading-relaxed text-ink-600">{{ $g['def'] }}</div>
                            <div class="mt-2 flex items-start gap-1.5 border-t border-ink-100 pt-2 text-[11.5px] italic leading-relaxed text-ink-500">
                                <svg width="13" height="13" viewBox="0 0 20 20" fill="none" class="mt-0.5 flex-shrink-0"><path d="M4 10h8M9 7l3 3-3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <span>{{ $g['example'] }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Documentation technique --}}
            <section class="mb-9">
                <div class="mb-3 flex items-center gap-2">
                    <h2 class="text-[15px] font-bold text-ink-900">Documentation technique</h2>
                    <span class="rounded-full bg-ink-100 px-2.5 py-0.5 text-[11px] font-bold text-ink-600">Administrateurs</span>
                </div>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (HelpContent::techDocs() as $d)
                        <button wire:click="open('{{ $d['key'] }}')" class="flex items-center justify-between gap-3 rounded-[12px] border border-ink-200 bg-white p-4 text-left hover:border-brand-300 hover:bg-brand-50/30">
                            <div><div class="text-[13.5px] font-semibold text-ink-900">{{ $d['title'] }}</div><div class="mt-0.5 text-[12px] text-ink-500">{{ $d['excerpt'] }}</div></div>
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-400"><path d="M8 5l5 5-5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    @endforeach
                </div>
            </section>

            {{-- FAQ --}}
            <section class="mb-9">
                <h2 class="mb-3 text-[15px] font-bold text-ink-900">Questions fréquentes</h2>
                <div class="flex flex-col gap-2" x-data="{ open: 0 }">
                    @foreach (HelpContent::faq() as $i => $f)
                        <div class="rounded-[12px] border border-ink-200 bg-white">
                            <button @click="open = open === {{ $i }} ? null : {{ $i }}" class="flex w-full items-center justify-between gap-3 p-4 text-left text-[13.5px] font-semibold text-ink-900">
                                {{ $f['q'] }}
                                <svg width="16" height="16" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-400 transition-transform" :class="{ 'rotate-180': open === {{ $i }} }"><path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <div x-show="open === {{ $i }}" x-collapse x-cloak><p class="px-4 pb-4 text-[13px] leading-relaxed text-ink-600">{{ $f['a'] }}</p></div>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Académie EcolePay --}}
            <section class="mb-9">
                <div class="mb-1 flex items-center gap-2">
                    <h2 class="text-[15px] font-bold text-ink-900">Académie EcolePay</h2>
                    <span class="rounded-full bg-brand-50 px-2.5 py-0.5 text-[11px] font-bold text-brand-700">Parcours par métier</span>
                </div>
                <p class="mb-3 text-[12.5px] text-ink-500">Des parcours adaptés à chaque profil pour une prise en main homogène.</p>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach (HelpContent::academy() as $p)
                        <div class="flex flex-col rounded-[13px] border border-ink-200 bg-white p-4">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-9 w-9 items-center justify-center rounded-[10px] text-[13px] font-bold text-white" style="background: {{ $p['color'] }}">{{ mb_substr($p['role'], 0, 1) }}</span>
                                <div><div class="text-[13.5px] font-bold text-ink-900">{{ $p['role'] }}</div><div class="text-[11.5px] text-ink-500">{{ count($p['lessons']) }} leçons</div></div>
                            </div>
                            <p class="mt-2 text-[12px] leading-relaxed text-ink-600">{{ $p['desc'] }}</p>
                            <ul class="mt-2.5 flex flex-col gap-1.5">
                                @foreach ($p['lessons'] as $lesson)
                                    <li class="flex items-center gap-2 text-[12px] text-ink-600"><svg width="13" height="13" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-300"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M7 10l2 2 4-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>{{ $lesson }}</li>
                                @endforeach
                            </ul>
                            <div class="mt-3 flex items-center gap-2 border-t border-ink-100 pt-2.5">
                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-ink-100"><div class="h-full w-0 rounded-full bg-brand-500"></div></div>
                                <span class="text-[10.5px] font-semibold text-ink-400">Suivi à venir</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Tutoriels vidéo (honnête : à venir) --}}
            <section class="mb-9">
                <h2 class="mb-3 text-[15px] font-bold text-ink-900">Tutoriels vidéo</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach (HelpContent::videoTopics() as $v)
                        @php [$lf, $lb] = $levelColor[$v['level']] ?? ['#5B6472', '#F2F3F5']; @endphp
                        <div class="overflow-hidden rounded-[13px] border border-ink-200 bg-white">
                            <div class="relative flex aspect-video items-center justify-center bg-ink-100">
                                <span class="flex h-11 w-11 items-center justify-center rounded-full bg-white/90 text-ink-400 shadow"><svg width="18" height="18" viewBox="0 0 20 20" fill="currentColor"><path d="M7 5l8 5-8 5z"/></svg></span>
                                <span class="absolute right-2 top-2 rounded bg-ink-900/70 px-1.5 py-0.5 text-[10px] font-bold text-white">Bientôt</span>
                            </div>
                            <div class="p-3">
                                <div class="text-[12.5px] font-semibold leading-tight text-ink-900">{{ $v['title'] }}</div>
                                <div class="mt-1.5 flex items-center gap-2"><span class="rounded-full px-2 py-0.5 text-[10px] font-bold" style="background: {{ $lb }}; color: {{ $lf }}">{{ $v['level'] }}</span><span class="text-[11px] text-ink-400">{{ $v['minutes'] }} min</span></div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-2 text-[11.5px] text-ink-400">Les vidéos seront ajoutées prochainement — les sujets ci-dessus sont ceux prévus.</div>
            </section>

            {{-- Nouveautés --}}
            <section class="mb-9">
                <h2 class="mb-3 text-[15px] font-bold text-ink-900">Quoi de neuf ?</h2>
                <ol class="relative ml-2 border-l border-ink-200">
                    @foreach (HelpContent::changelog() as $c)
                        @php [$lbl, $fg, $bg] = $changeType[$c['type']] ?? ['Mise à jour', '#5B6472', '#F2F3F5']; @endphp
                        <li class="relative mb-5 pl-6 last:mb-0">
                            <span class="absolute -left-[7px] top-1 h-3 w-3 rounded-full border-2 border-white" style="background: {{ $fg }}"></span>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 text-[10.5px] font-bold" style="background: {{ $bg }}; color: {{ $fg }}">{{ $lbl }}</span>
                                <span class="text-[13.5px] font-semibold text-ink-900">{{ $c['title'] }}</span>
                                <span class="text-[11px] text-ink-400">{{ \Illuminate\Support\Carbon::parse($c['date'])->translatedFormat('d M Y') }}</span>
                            </div>
                            <p class="mt-0.5 text-[12.5px] text-ink-500">{{ $c['desc'] }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>

            {{-- Assistance --}}
            <section class="mb-2">
                <div class="flex flex-col items-center gap-3 rounded-[16px] border border-brand-200 bg-brand-50 p-7 text-center">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-white text-brand-600 shadow-sm"><svg width="24" height="24" viewBox="0 0 20 20" fill="none"><path d="M10 3a7 7 0 00-7 7v3a2 2 0 002 2h1v-5H5v-0a5 5 0 0110 0v0h-1v5h1a2 2 0 002-2v-3a7 7 0 00-7-7z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg></span>
                    <div>
                        <div class="text-[16px] font-bold text-ink-900">Besoin d'aide ?</div>
                        <p class="mt-1 text-[13px] text-ink-600">Vous ne trouvez pas votre réponse ? Notre équipe est là pour vous.</p>
                    </div>
                    <button wire:click="$set('supportOpen', true)" class="rounded-[10px] bg-brand-600 px-5 py-2.5 text-[13px] font-semibold text-white shadow-sm hover:bg-brand-700">Ouvrir une demande de support</button>
                </div>
            </section>
        @endif
    @endif

    {{-- ============================ VOLET SUPPORT ============================ --}}
    <div x-data="{ open: @entangle('supportOpen') }" x-cloak>
        <div x-show="open" x-transition.opacity @click="open = false" class="fixed inset-0 z-40 bg-ink-900/40"></div>
        <div x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
             class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-ink-150 px-5 py-4">
                <div class="text-[15px] font-bold text-ink-900">Contacter le support</div>
                <button @click="open = false" class="flex h-8 w-8 items-center justify-center rounded-lg text-ink-500 hover:bg-ink-100"><svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button>
            </div>
            <div class="flex-1 overflow-y-auto p-5">
                <div class="flex flex-col gap-4">
                    <div>
                        <label class="mb-1.5 block text-[12.5px] font-semibold text-ink-800">Type de demande</label>
                        <select wire:model="support.type" class="eac-input">
                            @foreach (HelpContent::supportTypes() as $val => $lbl)
                                <option value="{{ $val }}">{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-[12.5px] font-semibold text-ink-800">Sujet</label>
                        <input wire:model="support.subject" type="text" class="eac-input" placeholder="Résumé en quelques mots">
                        @error('support.subject') <p class="eac-err">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-[12.5px] font-semibold text-ink-800">Description</label>
                        <textarea wire:model="support.body" rows="6" class="eac-input" placeholder="Décrivez votre problème ou votre question…"></textarea>
                        @error('support.body') <p class="eac-err">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-start gap-2 rounded-[11px] border border-dashed border-ink-300 p-3 text-[12px] text-ink-500">
                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none" class="mt-0.5 flex-shrink-0"><rect x="3" y="5" width="14" height="11" rx="2" stroke="currentColor" stroke-width="1.4"/><circle cx="7" cy="9" r="1.3" stroke="currentColor" stroke-width="1.2"/><path d="M4 14l4-3 3 2 3-3 2 2" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                        <span>Joindre une capture d'écran sera possible avec le stockage de fichiers (à venir).</span>
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-end gap-2 border-t border-ink-150 px-5 py-4">
                <button @click="open = false" class="rounded-[9px] px-4 py-2.5 text-[13px] font-semibold text-ink-600 hover:bg-ink-100">Annuler</button>
                <button wire:click="submitSupport" wire:loading.attr="disabled" wire:target="submitSupport" class="inline-flex items-center gap-2 rounded-[10px] bg-brand-600 px-4 py-2.5 text-[13px] font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
                    <svg wire:loading wire:target="submitSupport" width="15" height="15" viewBox="0 0 20 20" fill="none" class="animate-spin"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" stroke-opacity="0.3"/><path d="M17 10a7 7 0 00-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    Envoyer la demande
                </button>
            </div>
        </div>
    </div>
</div>
