<?php

use App\Domains\Parents\Actions\ComputeParentProfile;
use App\Domains\Parents\Actions\ListParentsForCrm;
use App\Domains\Parents\Support\ParentLifecycle;
use App\Domains\Schools\Models\School;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $stage = '';

    #[Url]
    public ?int $school = null;

    #[Url]
    public string $activity = '';

    #[Url]
    public string $sort = 'activity';

    public int $page = 1;

    public ?int $selectedId = null;

    public array $cols = ['phone', 'school', 'children', 'registeredAt', 'lastPayment', 'lastActivity', 'engagement'];

    #[Computed]
    public function kpis(): array
    {
        return app(ListParentsForCrm::class)->kpis();
    }

    #[Computed]
    public function result(): array
    {
        return app(ListParentsForCrm::class)->search($this->search, $this->stage, $this->school, $this->activity, $this->sort);
    }

    #[Computed]
    public function pageData(): array
    {
        $per = 15;
        $all = $this->result['rows'];
        $pages = max(1, (int) ceil($all->count() / $per));
        $page = min(max(1, $this->page), $pages);

        return ['items' => $all->slice(($page - 1) * $per, $per)->values(), 'page' => $page, 'pages' => $pages, 'shown' => $all->count()];
    }

    #[Computed]
    public function selected(): ?array
    {
        return $this->selectedId ? app(ComputeParentProfile::class)($this->selectedId) : null;
    }

    public function schools(): array
    {
        return School::query()->where('is_test', false)->current()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function setStage(string $s): void
    {
        $this->stage = $this->stage === $s ? '' : $s;
        $this->page = 1;
    }

    public function toggleCol(string $key): void
    {
        $this->cols = in_array($key, $this->cols, true) ? array_values(array_diff($this->cols, [$key])) : [...$this->cols, $key];
    }

    public function updated($prop): void
    {
        if (in_array($prop, ['search', 'stage', 'school', 'activity', 'sort'], true)) {
            $this->page = 1;
        }
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
    }

    public function closePreview(): void
    {
        $this->selectedId = null;
    }

    public function gotoPage(int $p): void
    {
        $this->page = $p;
    }

    public function export()
    {
        $rows = app(ListParentsForCrm::class)->search($this->search, $this->stage, $this->school, $this->activity, $this->sort)['rows'];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Parent', 'Téléphone', 'Étape', 'École', 'Enfants', 'Paiements', 'Engagement']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['name'], $r['phone'], $r['lifecycle']['short'], $r['school'], $r['children'], $r['payCount'], $r['engagement']]);
            }
            fclose($out);
        }, 'parents-'.now()->format('Y-m-d').'.csv');
    }
};

?>

@php
    $fr = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $money = fn ($n) => $n >= 1_000_000 ? number_format($n / 1_000_000, 1, ',', ' ').' M F' : $fr($n).' F';
    $ago = function ($d) {
        if (! $d) {
            return '—';
        }
        $days = (int) \Illuminate\Support\Carbon::parse($d)->diffInDays(now());

        return $days === 0 ? "aujourd'hui" : ($days === 1 ? 'hier' : ($days < 30 ? "il y a {$days} j" : 'il y a '.intdiv($days, 30).' mois'));
    };
    $monogram = fn ($name) => \Illuminate\Support\Str::of($name)->explode(' ')->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
    $spark = function (?array $s, string $color) {
        if (! $s || count($s) < 2 || max($s) == 0) {
            return '';
        }
        [$w, $h] = [82, 26];
        $mx = max($s);
        $pts = [];
        foreach ($s as $i => $v) {
            $pts[] = round($i / (count($s) - 1) * $w, 1).','.round($h - 2 - $v / $mx * ($h - 5), 1);
        }
        $line = implode(' ', $pts);

        return '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" fill="none"><polyline points="'.$line.'" fill="none" stroke="'.$color.'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    };

    $k = $this->kpis;
    $kpiCards = [
        ['Parents connus', $fr($k['connus']), 'numéros identifiés', '#6B7280', '#F2F3F5', null, null],
        ['Parents inscrits', $fr($k['inscrits']), 'comptes créés', '#1D4ED8', '#E7EEFE', $k['inscritsSpark'], '#1D4ED8'],
        ['Parents adoptants ⭐', $fr($k['adoptants']), '1ᵉʳ paiement effectué', '#0F7A44', '#E9F8EF', $k['adoptantsSpark'], '#0F7A44'],
        ['Parents engagés', $fr($k['engages']), 'paiements récurrents', '#0B6A3B', '#DDF3E7', null, null],
        ['Nouveaux ce mois', $fr($k['newThisMonth']), 'comptes créés ce mois', '#B45F04', '#FEF3E2', null, null],
        ["Taux d'adoption", number_format($k['adoptionRate'], 1, ',', ' ').' %', 'adoptants / connus', '#2554C7', '#EEF3FE', null, null],
    ];
    $stageChips = ParentLifecycle::stages();
    $activityOptions = ['' => 'Toute activité', 'today' => "Aujourd'hui", 'week' => 'Cette semaine', 'month' => 'Ce mois', 'stale' => '+ de 30 jours'];
    $sortOptions = ['activity' => 'Dernière activité', 'adoption' => 'Adoption', 'registration' => "Date d'inscription", 'payments' => 'Paiements'];
    $colLabels = ['phone' => 'Téléphone', 'school' => 'École', 'children' => 'Enfants', 'registeredAt' => 'Inscription', 'lastPayment' => 'Dernier paiement', 'lastActivity' => 'Dernière activité', 'engagement' => 'Engagement'];
@endphp

<div class="mx-auto max-w-[1480px]" x-data="{ preview: @entangle('selectedId') }">

    {{-- Actions --}}
    <div class="mb-5 flex flex-wrap items-center justify-end gap-2">
        <button wire:click="export" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 3v9m0 0l-3.2-3.2M10 12l3.2-3.2M4 15.5h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Exporter
        </button>
        <button wire:click="$refresh" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M16 6a7 7 0 10.9 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M16.5 3v3.2h-3.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Actualiser
        </button>
    </div>

    {{-- KPI cycle de vie --}}
    <div class="mb-3 grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
        @foreach ($kpiCards as [$label, $value, $sub, $fg, $bg, $sk, $skc])
            <div class="rounded-[13px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="flex items-start justify-between">
                    <span class="h-2.5 w-2.5 rounded-full" style="background: {{ $fg }}"></span>
                    @if ($sk)<div class="opacity-90">{!! $spark($sk, $skc) !!}</div>@endif
                </div>
                <div class="mt-2 text-[20px] font-bold tracking-tight text-ink-900">{{ $value }}</div>
                <div class="text-[12px] font-semibold text-ink-800">{{ $label }}</div>
                <div class="text-[11px] text-ink-500">{{ $sub }}</div>
            </div>
        @endforeach
    </div>

    {{-- Bandeau des 3 taux --}}
    <div class="mb-5 flex flex-wrap gap-x-8 gap-y-2 rounded-xl border border-ink-150 bg-white px-5 py-3 text-[12.5px]">
        <div><span class="font-semibold text-ink-500">Taux d'inscription</span> <span class="ml-1 font-bold text-ink-900">{{ number_format($k['registrationRate'], 1, ',', ' ') }} %</span> <span class="text-ink-400">(inscrits / connus)</span></div>
        <div><span class="font-semibold text-ink-500">Taux d'activation</span> <span class="ml-1 font-bold text-ink-900">{{ number_format($k['activationRate'], 1, ',', ' ') }} %</span> <span class="text-ink-400">(adoptants / inscrits)</span></div>
        <div><span class="font-semibold text-brand-600">Taux d'adoption ⭐</span> <span class="ml-1 font-bold text-ink-900">{{ number_format($k['adoptionRate'], 1, ',', ' ') }} %</span> <span class="text-ink-400">(adoptants / connus)</span></div>
    </div>

    {{-- Filtres par étape (chips) --}}
    <div class="mb-3 flex flex-wrap items-center gap-2">
        @foreach ($stageChips as $key => $label)
            @php $lc = ParentLifecycle::of($key !== 'connu', in_array($key, ['adoptant', 'engage']), $key === 'engage'); @endphp
            <button wire:click="setStage('{{ $key }}')"
                    class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-[12.5px] font-semibold transition-colors {{ $stage === $key ? 'border-transparent' : 'border-ink-200 bg-white hover:bg-ink-50' }}"
                    style="{{ $stage === $key ? "background: {$lc['bg']}; color: {$lc['color']}" : '' }}">
                <span class="h-2 w-2 rounded-full" style="background: {{ $lc['color'] }}"></span>{{ $label }}
            </button>
        @endforeach
        @if ($stage)<button wire:click="$set('stage','')" class="text-[12px] font-semibold text-brand-600 hover:underline">Réinitialiser</button>@endif
    </div>

    {{-- Recherche + filtres --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="flex min-w-[220px] flex-1 items-center gap-2 rounded-lg border border-ink-300 bg-white px-3 py-2 focus-within:border-brand-600">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-500"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="M17 17l-3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            <input wire:model.live.debounce.300ms="search" placeholder="Nom ou numéro de téléphone…" class="w-full border-none bg-transparent text-[13.5px] text-ink-900 outline-none placeholder:text-ink-500">
        </div>
        <select wire:model.live="school" class="rounded-lg border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold text-ink-800">
            <option value="">Toutes les écoles</option>
            @foreach ($this->schools() as $id => $sname)<option value="{{ $id }}">{{ $sname }}</option>@endforeach
        </select>
        <flux:dropdown>
            <button class="inline-flex items-center gap-2 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold text-ink-800 hover:bg-ink-50">
                {{ $activityOptions[$activity] }}
                <svg width="12" height="12" viewBox="0 0 20 20" fill="none"><path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <flux:menu>
                @foreach ($activityOptions as $key => $label)<flux:menu.item wire:click="$set('activity','{{ $key }}')" icon="{{ $activity === $key ? 'check' : '' }}">{{ $label }}</flux:menu.item>@endforeach
            </flux:menu>
        </flux:dropdown>
        <button type="button" disabled title="Responsable commercial non renseigné" class="inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg border border-dashed border-ink-200 bg-ink-50 px-3 py-2 text-[13px] font-medium text-ink-400">Commercial<span class="rounded bg-ink-100 px-1 text-[9.5px] font-bold uppercase">à venir</span></button>
        <flux:dropdown>
            <button class="inline-flex items-center gap-2 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold text-ink-800 hover:bg-ink-50">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M6 4v12M6 16l-2.5-2.5M6 16l2.5-2.5M14 16V4M14 4l-2.5 2.5M14 4l2.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Trier : {{ $sortOptions[$sort] }}
            </button>
            <flux:menu>
                @foreach ($sortOptions as $key => $label)<flux:menu.item wire:click="$set('sort','{{ $key }}')" icon="{{ $sort === $key ? 'check' : '' }}">{{ $label }}</flux:menu.item>@endforeach
            </flux:menu>
        </flux:dropdown>
        <flux:dropdown>
            <button class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold text-ink-800 hover:bg-ink-50">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><rect x="3" y="3" width="14" height="14" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M8 3v14" stroke="currentColor" stroke-width="1.5"/></svg>
                Colonnes
            </button>
            <flux:menu>
                @foreach ($colLabels as $key => $label)<flux:menu.item wire:click="toggleCol('{{ $key }}')" icon="{{ in_array($key, $cols) ? 'check' : '' }}">{{ $label }}</flux:menu.item>@endforeach
            </flux:menu>
        </flux:dropdown>
    </div>

    {{-- Compteur --}}
    <div class="mb-3 text-[13px] text-ink-600">
        <span class="font-mono font-semibold text-ink-900">{{ $fr($this->result['total']) }}</span> parents
        @if ($this->result['truncated'])<span class="text-ink-400">· {{ $fr($this->pageData['shown']) }} chargés</span>@endif
    </div>

    {{-- Tableau --}}
    @if ($this->pageData['shown'] === 0)
        <div class="rounded-[16px] border border-dashed border-ink-300 bg-white py-16 text-center">
            <div class="text-[14px] font-semibold text-ink-800">Aucun parent ne correspond</div>
            <div class="mt-1 text-[12.5px] text-ink-500">Ajustez la recherche ou les filtres.</div>
        </div>
    @else
        <div class="overflow-x-auto rounded-[14px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-ink-50 text-[11px] font-bold uppercase tracking-wider text-ink-500">
                        <th class="px-5 py-3 text-left">Parent</th>
                        @if (in_array('phone', $cols))<th class="px-3 py-3 text-left">Téléphone</th>@endif
                        @if (in_array('school', $cols))<th class="px-3 py-3 text-left">École</th>@endif
                        @if (in_array('children', $cols))<th class="px-3 py-3 text-right">Enfants</th>@endif
                        <th class="px-3 py-3 text-left">Parcours</th>
                        @if (in_array('registeredAt', $cols))<th class="px-3 py-3 text-left">Inscription</th>@endif
                        @if (in_array('lastPayment', $cols))<th class="px-3 py-3 text-left">Dern. paiement</th>@endif
                        @if (in_array('lastActivity', $cols))<th class="px-3 py-3 text-left">Dern. activité</th>@endif
                        @if (in_array('engagement', $cols))<th class="px-3 py-3 text-right">Engagement</th>@endif
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->pageData['items'] as $r)
                        <tr wire:key="p-{{ $r['id'] }}" class="cursor-pointer border-b border-ink-150 last:border-0 hover:bg-brand-50/40" wire:click="select({{ $r['id'] }})">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-[11px] font-bold" style="background: {{ $r['lifecycle']['bg'] }}; color: {{ $r['lifecycle']['color'] }}">{{ $monogram($r['name']) }}</span>
                                    <div>
                                        <div class="text-[13.5px] font-semibold text-ink-900">{{ $r['name'] }}</div>
                                        <div class="text-[11px] font-semibold" style="color: {{ $r['lifecycle']['color'] }}">{{ $r['lifecycle']['short'] }}{{ $r['lifecycle']['star'] ? ' ⭐' : '' }}</div>
                                    </div>
                                </div>
                            </td>
                            @if (in_array('phone', $cols))<td class="px-3 py-3 font-mono text-[12.5px] text-ink-700">{{ $r['phone'] ?: '—' }}</td>@endif
                            @if (in_array('school', $cols))<td class="px-3 py-3 text-[13px] text-ink-700">{{ $r['school'] ?: '—' }}</td>@endif
                            @if (in_array('children', $cols))<td class="px-3 py-3 text-right font-mono text-[13px] text-ink-700">{{ $fr($r['children']) }}</td>@endif
                            <td class="px-3 py-3">
                                <div class="flex items-center gap-1">
                                    @for ($i = 1; $i <= 4; $i++)
                                        <span class="h-1.5 w-6 rounded-full" style="background: {{ $r['lifecycle']['rank'] >= $i ? $r['lifecycle']['color'] : '#E7E9ED' }}"></span>
                                    @endfor
                                </div>
                            </td>
                            @if (in_array('registeredAt', $cols))<td class="px-3 py-3 text-[12px] text-ink-600">{{ $ago($r['registeredAt']) }}</td>@endif
                            @if (in_array('lastPayment', $cols))<td class="px-3 py-3 text-[12px] text-ink-600">{{ $ago($r['lastPayment']) }}</td>@endif
                            @if (in_array('lastActivity', $cols))<td class="px-3 py-3 text-[12px] text-ink-600">{{ $ago($r['lastActivity']) }}</td>@endif
                            @if (in_array('engagement', $cols))
                                <td class="px-3 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <div class="h-1.5 w-14 overflow-hidden rounded-full bg-ink-100"><div class="h-full rounded-full bg-brand-600" style="width: {{ $r['engagement'] }}%"></div></div>
                                        <span class="w-6 text-right font-mono text-[11.5px] text-ink-600">{{ $r['engagement'] }}</span>
                                    </div>
                                </td>
                            @endif
                            <td class="px-4 py-3 text-right" wire:click.stop>
                                <flux:dropdown>
                                    <button class="flex h-7 w-7 items-center justify-center rounded-md text-ink-500 hover:bg-ink-100"><svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><circle cx="10" cy="4.5" r="1.5"/><circle cx="10" cy="10" r="1.5"/><circle cx="10" cy="15.5" r="1.5"/></svg></button>
                                    <flux:menu>
                                        <flux:menu.item icon="eye" wire:click="select({{ $r['id'] }})">Aperçu rapide</flux:menu.item>
                                        <flux:menu.item icon="arrow-top-right-on-square" href="{{ route('parents.show', $r['id']) }}" wire:navigate>Ouvrir la fiche</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($this->pageData['pages'] > 1)
            <div class="mt-4 flex items-center justify-end gap-1">
                <button wire:click="gotoPage({{ max(1, $this->pageData['page'] - 1) }})" @disabled($this->pageData['page'] <= 1) class="flex h-8 w-8 items-center justify-center rounded-lg border border-ink-200 text-ink-600 hover:bg-ink-50 disabled:opacity-40"><svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M12 5l-5 5 5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                <span class="px-2 text-[12.5px] text-ink-600">{{ $this->pageData['page'] }} / {{ $this->pageData['pages'] }}</span>
                <button wire:click="gotoPage({{ min($this->pageData['pages'], $this->pageData['page'] + 1) }})" @disabled($this->pageData['page'] >= $this->pageData['pages']) class="flex h-8 w-8 items-center justify-center rounded-lg border border-ink-200 text-ink-600 hover:bg-ink-50 disabled:opacity-40"><svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M8 5l5 5-5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
            </div>
        @endif
    @endif

    {{-- ===== Quick Preview ===== --}}
    <div x-show="preview" x-cloak class="fixed inset-0 z-50" style="display:none">
        <div class="absolute inset-0 bg-ink-900/30" x-show="preview" x-transition.opacity @click="$wire.closePreview()"></div>
        <div class="absolute inset-y-0 right-0 flex w-full max-w-[440px] flex-col bg-white shadow-2xl"
             x-show="preview" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full">
            @if ($s = $this->selected)
                @php $lc = $s['lifecycle']; @endphp
                <div class="flex items-start justify-between gap-3 border-b border-ink-150 px-6 py-5">
                    <div class="flex items-center gap-3">
                        <span class="flex h-12 w-12 items-center justify-center rounded-full text-[15px] font-bold" style="background: {{ $lc['bg'] }}; color: {{ $lc['color'] }}">{{ $monogram($s['parent']['name']) }}</span>
                        <div>
                            <div class="text-[16px] font-bold text-ink-900">{{ $s['parent']['name'] }}</div>
                            <span class="mt-0.5 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background: {{ $lc['bg'] }}; color: {{ $lc['color'] }}">{{ $lc['label'] }}{{ $lc['star'] ? ' ⭐' : '' }}</span>
                        </div>
                    </div>
                    <button wire:click="closePreview" class="flex h-8 w-8 items-center justify-center rounded-lg text-ink-500 hover:bg-ink-100"><svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5">
                    <div class="mb-1 text-[11px] font-bold uppercase tracking-wider text-ink-500">Informations générales</div>
                    <div class="mb-5 grid grid-cols-2 gap-y-2 text-[13px]">
                        <div class="text-ink-500">Téléphone</div><div class="text-right font-mono font-medium text-ink-800">{{ $s['parent']['phone'] ?: '—' }}</div>
                        <div class="text-ink-500">Email</div><div class="text-right font-medium text-ink-800">{{ $s['parent']['email'] ?: '—' }}</div>
                        <div class="text-ink-500">École principale</div><div class="text-right font-medium text-ink-800">{{ $s['parent']['school'] ?: '—' }}</div>
                    </div>
                    <div class="mb-2 text-[11px] font-bold uppercase tracking-wider text-ink-500">Enfants ({{ count($s['children']) }})</div>
                    <div class="mb-5 flex flex-col gap-1.5">
                        @forelse ($s['children'] as $child)
                            <div class="flex items-center justify-between rounded-lg border border-ink-150 px-3 py-2 text-[12.5px]"><span class="font-medium text-ink-800">{{ $child['ref'] ?: 'Élève' }}</span><span class="text-ink-500">{{ $child['class'] ?: '—' }}</span></div>
                        @empty
                            <div class="text-[12.5px] text-ink-500">Aucun enfant rattaché.</div>
                        @endforelse
                    </div>
                    <div class="mb-2 text-[11px] font-bold uppercase tracking-wider text-ink-500">Indicateurs</div>
                    <div class="mb-5 grid grid-cols-2 gap-2.5">
                        @foreach ([['Paiements', $fr($s['kpis']['payCount'])], ['Montant payé', $s['kpis']['total'] > 0 ? $money($s['kpis']['total']) : '—'], ['1ᵉʳ paiement', $ago($s['kpis']['firstPayment'])], ['Dernière activité', $ago($s['kpis']['lastActivity'])]] as [$l, $v])
                            <div class="rounded-xl border border-ink-150 bg-ink-50 px-3 py-2.5"><div class="text-[16px] font-bold text-ink-900">{{ $v }}</div><div class="text-[11px] text-ink-500">{{ $l }}</div></div>
                        @endforeach
                    </div>
                </div>
                <div class="border-t border-ink-150 px-6 py-4">
                    <a href="{{ route('parents.show', $s['parent']['id']) }}" wire:navigate class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2.5 text-[12.5px] font-semibold text-white hover:bg-brand-700">Voir la fiche complète</a>
                </div>
            @endif
        </div>
    </div>
</div>
