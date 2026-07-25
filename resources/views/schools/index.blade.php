<?php

use App\Domains\Schools\Actions\ListSchoolsWithAdoption;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $health = '';

    #[Computed]
    public function all(): Collection
    {
        return app(ListSchoolsWithAdoption::class)();
    }

    #[Computed]
    public function schools(): Collection
    {
        $term = trim(mb_strtolower($this->search));

        return $this->all
            ->when($term !== '', fn ($c) => $c->filter(
                fn ($s) => str_contains(mb_strtolower($s['name'].' '.$s['city'].' '.$s['region']), $term),
            ))
            ->when($this->health !== '', fn ($c) => $c->filter(fn ($s) => $s['health']['level'] === $this->health))
            ->values();
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        return $this->all->groupBy(fn ($s) => $s['health']['level'])->map->count()->all();
    }

    public function filterHealth(string $level): void
    {
        $this->health = $this->health === $level ? '' : $level;
    }
};

?>

@php $fr = fn ($n) => number_format($n, 0, ',', ' '); @endphp

<div class="mx-auto max-w-[1480px]">

    {{-- Filtres santé --}}
    <div class="mb-5 flex flex-wrap items-center gap-2.5">
        @php
            $chips = [
                'reference' => ['Référence', '#0F7A44', '#E9F8EF'],
                'satisfaisante' => ['Satisfaisante', '#1D3F9C', '#EEF3FE'],
                'fragile' => ['Fragile', '#B45F04', '#FEF3E2'],
                'prioritaire' => ['Prioritaire', '#B91C1C', '#FDECEC'],
            ];
        @endphp
        @foreach ($chips as $level => [$label, $color, $bg])
            <button wire:click="filterHealth('{{ $level }}')"
                    class="flex items-center gap-2 rounded-lg border px-3 py-2 text-[12.5px] font-semibold transition-colors
                           {{ $this->health === $level ? 'border-transparent' : 'border-ink-200 bg-white hover:bg-ink-50' }}"
                    style="{{ $this->health === $level ? "background: {$bg}; color: {$color}" : '' }}">
                <span class="h-2 w-2 rounded-full" style="background: {{ $color }}"></span>
                {{ $label }}
                <span class="font-mono text-ink-500">{{ $this->counts[$level] ?? 0 }}</span>
            </button>
        @endforeach
        @if ($this->health)
            <button wire:click="$set('health', '')" class="text-[12.5px] font-semibold text-brand-600 hover:underline">Réinitialiser</button>
        @endif
    </div>

    {{-- Barre : recherche + compteur --}}
    <div class="mb-4 flex items-center justify-between gap-4">
        <div class="text-[13px] text-ink-600">
            <span class="font-mono font-semibold text-ink-900">{{ $fr($this->schools->count()) }}</span> écoles
        </div>
        <div class="flex w-72 items-center gap-2 rounded-lg border border-ink-300 bg-white px-3 py-2 focus-within:border-brand-600">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-500"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="M17 17l-3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            <input wire:model.live.debounce.250ms="search" placeholder="Rechercher une école, ville…" class="w-full border-none bg-transparent text-[13.5px] text-ink-900 outline-none placeholder:text-ink-500">
        </div>
    </div>

    {{-- Tableau --}}
    <div class="overflow-hidden rounded-[14px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
        <table class="w-full border-collapse">
            <thead>
                <tr class="bg-ink-50">
                    <th class="px-6 py-3 text-left text-[11.5px] font-bold uppercase tracking-wider text-ink-500">École</th>
                    <th class="px-6 py-3 text-left text-[11.5px] font-bold uppercase tracking-wider text-ink-500">Ville · Région</th>
                    <th class="px-6 py-3 text-right text-[11.5px] font-bold uppercase tracking-wider text-ink-500">Connus</th>
                    <th class="px-6 py-3 text-right text-[11.5px] font-bold uppercase tracking-wider text-ink-500">Adoptants</th>
                    <th class="px-6 py-3 text-right text-[11.5px] font-bold uppercase tracking-wider text-ink-500">Taux</th>
                    <th class="px-6 py-3 text-left text-[11.5px] font-bold uppercase tracking-wider text-ink-500">Santé</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->schools as $s)
                    <tr class="border-b border-ink-150 last:border-0 hover:bg-ink-50" wire:key="school-{{ $s['id'] }}">
                        <td class="px-6 py-3.5 text-[13.5px] font-semibold text-ink-900">{{ $s['name'] }}</td>
                        <td class="px-6 py-3.5 text-[13px] text-ink-700">{{ $s['city'] ?: '—' }}@if ($s['region']) <span class="text-ink-500">· {{ $s['region'] }}</span>@endif</td>
                        <td class="px-6 py-3.5 text-right font-mono text-[13.5px] text-ink-700">{{ $fr($s['known']) }}</td>
                        <td class="px-6 py-3.5 text-right font-mono text-[13.5px] font-semibold text-ink-900">{{ $fr($s['adopters']) }}</td>
                        <td class="px-6 py-3.5 text-right font-mono text-[13.5px] font-semibold text-ink-900">{{ number_format($s['rate'], 1, ',', ' ') }} %</td>
                        <td class="px-6 py-3.5">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[12px] font-semibold" style="background: {{ $s['health']['bg'] }}; color: {{ $s['health']['color'] }}">
                                <span class="h-1.5 w-1.5 rounded-full" style="background: {{ $s['health']['color'] }}"></span>{{ $s['health']['label'] }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-12 text-center text-[13.5px] text-ink-500">Aucune école ne correspond à ces critères.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
