<?php

use App\Domains\Campaigns\Models\Campaign;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Recherche globale transverse (écoles + parents + campagnes) rendue dans le
 * header. Résultats cliquables vers la fiche/le module concerné. Requête légère,
 * limitée à quelques résultats par catégorie.
 */
new class extends Component
{
    public string $q = '';

    #[Computed]
    public function results(): array
    {
        $q = trim($this->q);
        if (mb_strlen($q) < 2) {
            return ['schools' => collect(), 'parents' => collect(), 'campaigns' => collect(), 'total' => 0];
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        $schools = DB::table('dim_schools')->where('is_test', false)->where('is_current', true)
            ->where('name', 'like', $like)->orderBy('name')->limit(6)->get(['id', 'name', 'city']);

        $parents = DB::table('dim_parents')->where('is_test', false)
            ->where(fn ($w) => $w->where('full_name', 'like', $like)->orWhere('phone_e164', 'like', $like))
            ->orderBy('full_name')->limit(6)->get(['id', 'full_name', 'phone_e164']);

        $campaigns = Campaign::query()->where('name', 'like', $like)
            ->latest('id')->limit(6)->get(['id', 'name', 'channel']);

        return [
            'schools' => $schools,
            'parents' => $parents,
            'campaigns' => $campaigns,
            'total' => $schools->count() + $parents->count() + $campaigns->count(),
        ];
    }

    public function clear(): void
    {
        $this->q = '';
    }
};

?>

<div class="relative" x-data="{ open: false }" @keydown.escape="open = false; $wire.clear()">
    <div class="hidden w-52 items-center gap-2 rounded-lg border border-ink-300 bg-ink-50 px-3 py-2 focus-within:border-brand-600 focus-within:bg-white sm:flex lg:w-64">
        <svg width="14" height="14" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-600"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="M17 17l-3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        <input wire:model.live.debounce.250ms="q" @focus="open = true" @click="open = true"
               placeholder="Rechercher écoles, parents…"
               class="w-full border-none bg-transparent text-[13.5px] text-ink-900 outline-none placeholder:text-ink-500">
        <button wire:click="clear" x-show="$wire.q.length" class="flex-shrink-0 text-ink-400 hover:text-ink-700"><svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button>
    </div>

    {{-- Résultats --}}
    <div x-show="open && $wire.q.length >= 2" x-cloak @click.outside="open = false" x-transition
         class="absolute right-0 top-11 z-50 w-[340px] overflow-hidden rounded-[14px] border border-ink-200 bg-white shadow-[0_12px_40px_rgba(15,23,42,0.18)] sm:left-0 sm:right-auto">
        @php $r = $this->results; @endphp

        <div wire:loading.flex wire:target="q" class="items-center gap-2 px-4 py-3 text-[12.5px] text-ink-500">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" class="animate-spin"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" stroke-opacity="0.3"/><path d="M17 10a7 7 0 00-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            Recherche…
        </div>

        <div wire:loading.remove wire:target="q" class="max-h-[400px] overflow-y-auto">
            @if ($r['total'] === 0)
                <div class="px-4 py-6 text-center text-[12.5px] text-ink-500">Aucun résultat pour « {{ $this->q }} ».</div>
            @else
                @if ($r['schools']->isNotEmpty())
                    <div class="px-3 pt-2.5 pb-1 text-[10.5px] font-bold uppercase tracking-wide text-ink-400">Écoles</div>
                    @foreach ($r['schools'] as $s)
                        <a href="{{ route('schools.show', $s->id) }}" wire:navigate @click="open = false"
                           class="flex items-center gap-2.5 px-3 py-2 hover:bg-ink-50">
                            <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-[8px] bg-brand-50 text-brand-700"><svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 3l7 4H3zM4 8h12v9H4zM9 12h2v5H9z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg></span>
                            <span class="min-w-0"><span class="block truncate text-[13px] font-semibold text-ink-900">{{ $s->name }}</span>@if ($s->city)<span class="block truncate text-[11px] text-ink-500">{{ $s->city }}</span>@endif</span>
                        </a>
                    @endforeach
                @endif

                @if ($r['parents']->isNotEmpty())
                    <div class="px-3 pt-2.5 pb-1 text-[10.5px] font-bold uppercase tracking-wide text-ink-400">Parents</div>
                    @foreach ($r['parents'] as $p)
                        <a href="{{ route('parents.index', ['search' => $p->full_name ?: $p->phone_e164]) }}" wire:navigate @click="open = false"
                           class="flex items-center gap-2.5 px-3 py-2 hover:bg-ink-50">
                            <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-[8px] bg-[#EEF3FE] text-[#2554C7]"><svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="7" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M4 16c0-3 2.7-4.5 6-4.5s6 1.5 6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
                            <span class="min-w-0"><span class="block truncate text-[13px] font-semibold text-ink-900">{{ $p->full_name ?: 'Parent' }}</span>@if ($p->phone_e164)<span class="block truncate text-[11px] text-ink-500">{{ $p->phone_e164 }}</span>@endif</span>
                        </a>
                    @endforeach
                @endif

                @if ($r['campaigns']->isNotEmpty())
                    <div class="px-3 pt-2.5 pb-1 text-[10.5px] font-bold uppercase tracking-wide text-ink-400">Campagnes</div>
                    @foreach ($r['campaigns'] as $c)
                        <a href="{{ route('campaigns.show', $c->id) }}" wire:navigate @click="open = false"
                           class="flex items-center gap-2.5 px-3 py-2 hover:bg-ink-50">
                            <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-[8px] bg-[#FEF3E2] text-[#B45F04]"><svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M4 7h9l4-3v12l-4-3H4z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg></span>
                            <span class="min-w-0 flex-1"><span class="block truncate text-[13px] font-semibold text-ink-900">{{ $c->name }}</span></span>
                        </a>
                    @endforeach
                @endif
            @endif
        </div>
    </div>
</div>
