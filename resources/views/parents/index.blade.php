<?php

use App\Domains\Parents\Actions\SearchParents;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $segment = '';

    /** @return array{rows: Collection<int, array<string, mixed>>, total: int, truncated: bool} */
    #[Computed]
    public function result(): array
    {
        return app(SearchParents::class)($this->search, $this->segment);
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        return app(SearchParents::class)->segmentCounts();
    }

    public function pickSegment(string $key): void
    {
        $this->segment = $this->segment === $key ? '' : $key;
    }
};

?>

@php
    $fr = fn ($n) => number_format((int) $n, 0, ',', ' ');

    // Palette des étapes de l'entonnoir (id => libellé, couleur, fond).
    $stages = [
        1 => ['Connu', '#6B7280', '#F2F3F5'],
        2 => ['Inscrit', '#1D3F9C', '#EEF3FE'],
        3 => ['Adoptant', '#0F7A44', '#E9F8EF'],
        4 => ['Engagé', '#0B6A3B', '#DFF4E8'],
        5 => ['À risque', '#B45F04', '#FEF3E2'],
        6 => ['Perdu', '#B91C1C', '#FDECEC'],
    ];

    // Onglets de segmentation : clé => libellé.
    $segments = [
        '' => 'Tous',
        'relance' => 'À relancer',
        'inscrits' => 'Inscrits',
        'adoptants' => 'Adoptants',
        'risque' => 'À risque',
    ];
    $segmentCountKey = ['' => 'tous', 'relance' => 'relance', 'inscrits' => 'inscrits', 'adoptants' => 'adoptants', 'risque' => 'risque'];
@endphp

<div class="mx-auto max-w-[1480px]">

    {{-- Segments --}}
    <div class="mb-5 flex flex-wrap items-center gap-1.5 border-b border-ink-200 pb-0">
        @foreach ($segments as $key => $label)
            @php $isOn = $this->segment === $key; @endphp
            <button wire:click="pickSegment('{{ $key }}')"
                    class="relative -mb-px flex items-center gap-2 px-3.5 py-2.5 text-[13.5px] font-semibold transition-colors
                           {{ $isOn ? 'text-brand-700' : 'text-ink-600 hover:text-ink-900' }}">
                {{ $label }}
                <span class="rounded-full px-1.5 py-0.5 text-[11px] font-mono font-semibold
                             {{ $isOn ? 'bg-brand-50 text-brand-700' : 'bg-ink-100 text-ink-600' }}">
                    {{ $fr($this->counts[$segmentCountKey[$key]] ?? 0) }}
                </span>
                @if ($isOn)<span class="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-brand-600"></span>@endif
            </button>
        @endforeach
    </div>

    {{-- Relance : bandeau contextuel --}}
    @if ($this->segment === 'relance')
        <div class="mb-4 flex items-start gap-3 rounded-xl border border-brand-600/15 bg-brand-50 px-4 py-3">
            <svg width="18" height="18" viewBox="0 0 20 20" fill="none" class="mt-0.5 flex-shrink-0 text-brand-700"><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.6"/><path d="M10 6v5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="13.6" r="1" fill="currentColor"/></svg>
            <div class="text-[13px] leading-snug text-ink-700">
                <span class="font-semibold text-ink-900">{{ $fr($this->counts['relance'] ?? 0) }} parents connus mais jamais inscrits.</span>
                Numéros présents dans une liste d'école, sans compte EcolePay : la cible directe des campagnes de relance.
            </div>
        </div>
    @endif

    {{-- Recherche + compteur --}}
    <div class="mb-4 flex items-center justify-between gap-4">
        <div class="text-[13px] text-ink-600">
            <span class="font-mono font-semibold text-ink-900">{{ $fr($this->result['total']) }}</span> parents
            @if ($this->result['truncated'])
                <span class="text-ink-500">· {{ $fr($this->result['rows']->count()) }} affichés</span>
            @endif
        </div>
        <div class="flex w-80 items-center gap-2 rounded-lg border border-ink-300 bg-white px-3 py-2 focus-within:border-brand-600">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-500"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="M17 17l-3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            <input wire:model.live.debounce.350ms="search" placeholder="Nom ou numéro de téléphone…" class="w-full border-none bg-transparent text-[13.5px] text-ink-900 outline-none placeholder:text-ink-500">
        </div>
    </div>

    {{-- Tableau --}}
    <div class="overflow-hidden rounded-[14px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
        <table class="w-full border-collapse">
            <thead>
                <tr class="bg-ink-50">
                    <th class="px-6 py-3 text-left text-[11.5px] font-bold uppercase tracking-wider text-ink-500">Parent</th>
                    <th class="px-6 py-3 text-left text-[11.5px] font-bold uppercase tracking-wider text-ink-500">Téléphone</th>
                    <th class="px-6 py-3 text-left text-[11.5px] font-bold uppercase tracking-wider text-ink-500">Étape</th>
                    <th class="px-6 py-3 text-right text-[11.5px] font-bold uppercase tracking-wider text-ink-500">Écoles</th>
                    <th class="px-6 py-3 text-right text-[11.5px] font-bold uppercase tracking-wider text-ink-500">Paiements</th>
                    <th class="px-6 py-3 text-right text-[11.5px] font-bold uppercase tracking-wider text-ink-500">Volume</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->result['rows'] as $p)
                    @php $st = $stages[$p['stage_id']] ?? ['Sans parcours', '#6B7280', '#F2F3F5']; @endphp
                    <tr class="border-b border-ink-150 last:border-0 hover:bg-ink-50" wire:key="parent-{{ $p['id'] }}">
                        <td class="px-6 py-3.5 text-[13.5px] font-semibold text-ink-900">{{ $p['name'] }}</td>
                        <td class="px-6 py-3.5 font-mono text-[13px] text-ink-700">{{ $p['phone'] ?: '—' }}</td>
                        <td class="px-6 py-3.5">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[12px] font-semibold" style="background: {{ $st[2] }}; color: {{ $st[1] }}">
                                <span class="h-1.5 w-1.5 rounded-full" style="background: {{ $st[1] }}"></span>{{ $st[0] }}
                            </span>
                        </td>
                        <td class="px-6 py-3.5 text-right font-mono text-[13.5px] text-ink-700">{{ $fr($p['school_count']) }}</td>
                        <td class="px-6 py-3.5 text-right font-mono text-[13.5px] text-ink-700">{{ $fr($p['payments']) }}</td>
                        <td class="px-6 py-3.5 text-right font-mono text-[13.5px] font-semibold text-ink-900">{{ $p['total_amount'] > 0 ? $fr($p['total_amount']).' F' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-12 text-center text-[13.5px] text-ink-500">Aucun parent ne correspond à ces critères.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
