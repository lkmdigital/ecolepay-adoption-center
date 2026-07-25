<?php

use App\Domains\Schools\Actions\ListSchoolsForPilotage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $adoption = '';

    #[Url]
    public string $model = '';

    #[Url]
    public string $sort = 'rate';

    #[Url]
    public string $dir = 'desc';

    #[Url]
    public string $view = 'table';

    public int $page = 1;

    public ?int $selectedId = null;

    /** Colonnes optionnelles visibles (nom / adoption / santé / actions sont toujours affichées). */
    public array $cols = ['code', 'students', 'known', 'inscrits', 'actifs', 'revenue', 'potential', 'lastActivity', 'badge'];

    #[Computed]
    public function portfolio(): array
    {
        return app(ListSchoolsForPilotage::class)();
    }

    #[Computed]
    public function filtered(): Collection
    {
        $term = trim(mb_strtolower($this->search));

        return collect($this->portfolio['rows'])
            ->when($term !== '', fn ($c) => $c->filter(
                fn ($s) => str_contains(mb_strtolower($s['name'].' '.$s['code']), $term),
            ))
            ->when($this->adoption !== '', fn ($c) => $c->filter(fn ($s) => $this->matchesAdoption($s['rate'])))
            ->when($this->model !== '', fn ($c) => $c->filter(fn ($s) => $s['subscriptionModel'] === $this->model))
            ->sortBy(fn ($s) => $s[$this->sort] ?? 0, SORT_REGULAR, $this->dir === 'desc')
            ->values();
    }

    #[Computed]
    public function pageData(): array
    {
        $perPage = 12;
        $all = $this->filtered;
        $pages = max(1, (int) ceil($all->count() / $perPage));
        $page = min(max(1, $this->page), $pages);

        return [
            'items' => $all->slice(($page - 1) * $perPage, $perPage)->values(),
            'page' => $page,
            'pages' => $pages,
            'total' => $all->count(),
            'from' => $all->count() ? ($page - 1) * $perPage + 1 : 0,
            'to' => min($page * $perPage, $all->count()),
        ];
    }

    #[Computed]
    public function selected(): ?array
    {
        if ($this->selectedId === null) {
            return null;
        }
        $school = collect($this->portfolio['rows'])->firstWhere('id', $this->selectedId);
        if (! $school) {
            return null;
        }
        $school['spark'] = $this->schoolSpark($this->selectedId);

        return $school;
    }

    private function matchesAdoption(float $rate): bool
    {
        return match ($this->adoption) {
            'critique' => $rate < 20,
            'faible' => $rate >= 20 && $rate < 40,
            'moyen' => $rate >= 40 && $rate < 70,
            'excellent' => $rate >= 70,
            default => true,
        };
    }

    /** Adoption cumulée par mois (6 mois) pour la mini-courbe du panneau. */
    private function schoolSpark(int $schoolId): array
    {
        $known = max(1, (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('school_id', $schoolId)->distinct()->count('parent_id'));
        $byMonth = DB::table('fact_parent_journeys')->where('is_test', false)->where('school_id', $schoolId)
            ->whereNotNull('first_payment_at')->where('first_payment_at', '>=', Carbon::now()->startOfMonth()->subMonths(5))
            ->selectRaw("DATE_FORMAT(first_payment_at, '%Y-%m') as m, COUNT(DISTINCT parent_id) as n")->groupBy('m')->pluck('n', 'm');
        $before = (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('school_id', $schoolId)
            ->whereNotNull('first_payment_at')->where('first_payment_at', '<', Carbon::now()->startOfMonth()->subMonths(5))->distinct()->count('parent_id');

        $spark = [];
        $cursor = Carbon::now()->startOfMonth()->subMonths(5);
        $cumulative = $before;
        for ($i = 0; $i < 6; $i++) {
            $cumulative += (int) ($byMonth[$cursor->format('Y-m')] ?? 0);
            $spark[] = round($cumulative / $known * 100, 1);
            $cursor->addMonth();
        }

        return $spark;
    }

    public function sortByCol(string $col): void
    {
        if ($this->sort === $col) {
            $this->dir = $this->dir === 'desc' ? 'asc' : 'desc';
        } else {
            $this->sort = $col;
            $this->dir = 'desc';
        }
        $this->page = 1;
    }

    public function toggleCol(string $key): void
    {
        $this->cols = in_array($key, $this->cols, true)
            ? array_values(array_diff($this->cols, [$key]))
            : [...$this->cols, $key];
    }

    public function setView(string $v): void
    {
        $this->view = $v;
    }

    public function gotoPage(int $p): void
    {
        $this->page = $p;
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
    }

    public function closePreview(): void
    {
        $this->selectedId = null;
    }

    public function refreshData(): void
    {
        unset($this->portfolio);
    }

    public function export()
    {
        $rows = $this->filtered;

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['École', 'Code', 'Élèves', 'Parents', 'Inscrits', 'Actifs', 'Adoption %', 'Score santé', 'CA', 'Potentiel']);
            foreach ($rows as $s) {
                fputcsv($out, [$s['name'], $s['code'], $s['students'], $s['known'], $s['inscrits'], $s['actifs'], $s['rate'], $s['healthScore'], $s['revenue'], $s['potential']]);
            }
            fclose($out);
        }, 'ecoles-'.now()->format('Y-m-d').'.csv');
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

    $sparkSvg = function (array $series, string $color) {
        if (count($series) < 2 || max($series) == 0) {
            return '';
        }
        [$w, $h] = [90, 28];
        $min = min($series);
        $range = max(max($series) - $min, 1);
        $pts = [];
        foreach ($series as $i => $v) {
            $pts[] = round($i / (count($series) - 1) * $w, 1).','.round($h - 2 - ($v - $min) / $range * ($h - 5), 1);
        }
        $line = implode(' ', $pts);

        return '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" fill="none" class="overflow-visible"><polygon points="0,'.$h.' '.$line.' '.$w.','.$h.'" fill="'.$color.'" opacity="0.09"/><polyline points="'.$line.'" fill="none" stroke="'.$color.'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    };

    $colLabels = ['code' => 'Code', 'students' => 'Élèves', 'known' => 'Parents', 'inscrits' => 'Inscrits', 'actifs' => 'Actifs', 'revenue' => 'CA', 'potential' => 'Potentiel', 'lastActivity' => 'Dernière activité', 'badge' => 'Statut'];
    $numericSorts = ['healthScore' => 'Score de santé', 'rate' => 'Adoption', 'known' => 'Parents', 'revenue' => 'Revenus', 'recent' => 'Progression', 'potential' => 'Potentiel'];
    $adoptionLevels = ['' => 'Toutes', 'critique' => 'Critique (< 20 %)', 'faible' => 'Faible (20–40 %)', 'moyen' => 'Moyen (40–70 %)', 'excellent' => 'Excellent (> 70 %)'];
    $modelLevels = ['' => 'Tous modèles', 'parent_paid' => 'Abonnement parent', 'bundled' => 'Abonnement inclus'];
    $sum = $this->portfolio['summary'];
@endphp

<div class="mx-auto max-w-[1480px]" x-data="{ preview: @entangle('selectedId') }">

    {{-- Actions header --}}
    <div class="mb-5 flex flex-wrap items-center justify-end gap-2">
        <button wire:click="export" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 3v9m0 0l-3.2-3.2M10 12l3.2-3.2M4 15.5h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Exporter
        </button>
        <button wire:click="refreshData" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none" wire:loading.class="animate-spin" wire:target="refreshData"><path d="M16 6a7 7 0 10.9 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M16.5 3v3.2h-3.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Actualiser
        </button>
    </div>

    {{-- Cartes de synthèse --}}
    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @php
            $cards = [
                ['Écoles suivies', $fr($sum['total']), 'établissements partenaires', 'var(--color-brand-50)', 'var(--color-brand-600)', null],
                ['Adoption moyenne', number_format($sum['avgAdoption'], 1, ',', ' ').' %', 'pondérée sur tous les parents', 'var(--color-success-soft)', 'var(--color-success)', $sum['adoptionSpark']],
                ['Écoles critiques', $fr($sum['critical']), 'adoption < 20 % · base ≥ 20', 'var(--color-danger-soft)', 'var(--color-danger)', null],
                ['Potentiel global', $money($sum['potential']), 'abonnements dormants', 'var(--color-warning-soft)', 'var(--color-warning)', null],
            ];
        @endphp
        @foreach ($cards as [$label, $value, $sub, $bg, $fg, $spark])
            <div class="rounded-[14px] border border-ink-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="flex items-start justify-between">
                    <div class="flex h-9 w-9 items-center justify-center rounded-[10px]" style="background: {{ $bg }}; color: {{ $fg }}">
                        <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.6"/><path d="M10 6v4l2.5 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    @if ($spark)<div>{!! $sparkSvg($spark, $fg) !!}</div>@endif
                </div>
                <div class="mt-3 text-[24px] font-bold tracking-tight text-ink-900">{{ $value }}</div>
                <div class="text-[13px] font-semibold text-ink-800">{{ $label }}</div>
                <div class="text-[11.5px] text-ink-500">{{ $sub }}</div>
            </div>
        @endforeach
    </div>

    {{-- Barre de filtres --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="flex min-w-[220px] flex-1 items-center gap-2 rounded-lg border border-ink-300 bg-white px-3 py-2 focus-within:border-brand-600">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-500"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="M17 17l-3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            <input wire:model.live.debounce.250ms="search" placeholder="Rechercher par nom ou code école…" class="w-full border-none bg-transparent text-[13.5px] text-ink-900 outline-none placeholder:text-ink-500">
        </div>

        <flux:dropdown>
            <button class="inline-flex items-center gap-2 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold text-ink-800 hover:bg-ink-50">
                {{ $adoptionLevels[$adoption] }}
                <svg width="12" height="12" viewBox="0 0 20 20" fill="none"><path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <flux:menu>
                @foreach ($adoptionLevels as $key => $label)
                    <flux:menu.item wire:click="$set('adoption', '{{ $key }}')" icon="{{ $adoption === $key ? 'check' : '' }}">{{ $label }}</flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>

        <flux:dropdown>
            <button class="inline-flex items-center gap-2 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold text-ink-800 hover:bg-ink-50">
                {{ $modelLevels[$model] }}
                <svg width="12" height="12" viewBox="0 0 20 20" fill="none"><path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <flux:menu>
                @foreach ($modelLevels as $key => $label)
                    <flux:menu.item wire:click="$set('model', '{{ $key }}')" icon="{{ $model === $key ? 'check' : '' }}">{{ $label }}</flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>

        @foreach ([['Région', 'Géographie non renseignée'], ['Ville', 'Géographie non renseignée'], ['Commercial', 'Responsable commercial non renseigné']] as [$flabel, $ftip])
            <button type="button" disabled title="{{ $ftip }}" class="inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg border border-dashed border-ink-200 bg-ink-50 px-3 py-2 text-[13px] font-medium text-ink-400">
                {{ $flabel }}<span class="rounded bg-ink-100 px-1 text-[9.5px] font-bold uppercase text-ink-400">à venir</span>
            </button>
        @endforeach

        <flux:dropdown>
            <button class="inline-flex items-center gap-2 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold text-ink-800 hover:bg-ink-50">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M6 4v12M6 16l-2.5-2.5M6 16l2.5-2.5M14 16V4M14 4l-2.5 2.5M14 4l2.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Trier : {{ $numericSorts[$sort] ?? 'Adoption' }}
            </button>
            <flux:menu>
                @foreach ($numericSorts as $key => $label)
                    <flux:menu.item wire:click="sortByCol('{{ $key }}')" icon="{{ $sort === $key ? 'check' : '' }}">{{ $label }}</flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>

        <flux:dropdown>
            <button class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold text-ink-800 hover:bg-ink-50">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><rect x="3" y="3" width="14" height="14" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M8 3v14" stroke="currentColor" stroke-width="1.5"/></svg>
                Colonnes
            </button>
            <flux:menu>
                @foreach ($colLabels as $key => $label)
                    <flux:menu.item wire:click="toggleCol('{{ $key }}')" icon="{{ in_array($key, $cols) ? 'check' : '' }}">{{ $label }}</flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>

        <div class="inline-flex rounded-lg border border-ink-200 bg-white p-0.5">
            <button wire:click="setView('table')" title="Vue tableau" class="flex h-8 w-8 items-center justify-center rounded-md {{ $view === 'table' ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-100' }}">
                <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="12" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M3 8.5h14M3 12.5h14" stroke="currentColor" stroke-width="1.5"/></svg>
            </button>
            <button wire:click="setView('cards')" title="Vue cartes" class="flex h-8 w-8 items-center justify-center rounded-md {{ $view === 'cards' ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-100' }}">
                <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><rect x="3" y="3" width="6" height="6" rx="1.5" stroke="currentColor" stroke-width="1.5"/><rect x="11" y="3" width="6" height="6" rx="1.5" stroke="currentColor" stroke-width="1.5"/><rect x="3" y="11" width="6" height="6" rx="1.5" stroke="currentColor" stroke-width="1.5"/><rect x="11" y="11" width="6" height="6" rx="1.5" stroke="currentColor" stroke-width="1.5"/></svg>
            </button>
        </div>
    </div>

    {{-- Compteur --}}
    <div class="mb-3 text-[13px] text-ink-600">
        <span class="font-mono font-semibold text-ink-900">{{ $fr($this->pageData['total']) }}</span> écoles
        @if ($this->pageData['total'] !== $sum['total'])<span class="text-ink-400">/ {{ $fr($sum['total']) }}</span>@endif
    </div>

    {{-- Skeleton de chargement --}}
    <div wire:loading.flex wire:target="refreshData, search, adoption, model" class="flex-col gap-2" style="display:none">
        @for ($i = 0; $i < 6; $i++)
            <div class="h-14 animate-pulse rounded-xl bg-ink-100"></div>
        @endfor
    </div>

    <div wire:loading.remove wire:target="refreshData, search, adoption, model">
        @if ($this->pageData['total'] === 0)
            {{-- État vide --}}
            <div class="flex flex-col items-center gap-2 rounded-[16px] border border-dashed border-ink-300 bg-white py-16 text-center">
                <svg width="34" height="34" viewBox="0 0 20 20" fill="none" class="text-ink-300"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.4"/><path d="M17 17l-3.5-3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                <div class="text-[14px] font-semibold text-ink-800">Aucune école ne correspond</div>
                <div class="text-[12.5px] text-ink-500">Ajustez la recherche ou les filtres.</div>
                @if ($search || $adoption || $model)
                    <button wire:click="$set('search',''); $set('adoption',''); $set('model','')" class="mt-1 text-[12.5px] font-semibold text-brand-600 hover:underline">Réinitialiser les filtres</button>
                @endif
            </div>
        @elseif ($view === 'table')
            {{-- ===== Vue tableau ===== --}}
            <div class="overflow-x-auto rounded-[14px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-ink-50 text-[11px] font-bold uppercase tracking-wider text-ink-500">
                            <th class="px-5 py-3 text-left">École</th>
                            @if (in_array('code', $cols))<th class="px-3 py-3 text-left">Code</th>@endif
                            @if (in_array('students', $cols))<th class="px-3 py-3 text-right">Élèves</th>@endif
                            @if (in_array('known', $cols))<th class="cursor-pointer px-3 py-3 text-right hover:text-ink-800" wire:click="sortByCol('known')">Parents @if($sort==='known')<span class="text-brand-600">{{ $dir==='desc'?'↓':'↑' }}</span>@endif</th>@endif
                            @if (in_array('inscrits', $cols))<th class="px-3 py-3 text-right">Inscrits</th>@endif
                            @if (in_array('actifs', $cols))<th class="px-3 py-3 text-right">Actifs</th>@endif
                            <th class="cursor-pointer px-3 py-3 text-right hover:text-ink-800" wire:click="sortByCol('rate')">Adoption @if($sort==='rate')<span class="text-brand-600">{{ $dir==='desc'?'↓':'↑' }}</span>@endif</th>
                            <th class="cursor-pointer px-3 py-3 text-center hover:text-ink-800" wire:click="sortByCol('healthScore')">Santé @if($sort==='healthScore')<span class="text-brand-600">{{ $dir==='desc'?'↓':'↑' }}</span>@endif</th>
                            @if (in_array('revenue', $cols))<th class="cursor-pointer px-3 py-3 text-right hover:text-ink-800" wire:click="sortByCol('revenue')">CA @if($sort==='revenue')<span class="text-brand-600">{{ $dir==='desc'?'↓':'↑' }}</span>@endif</th>@endif
                            @if (in_array('potential', $cols))<th class="cursor-pointer px-3 py-3 text-right hover:text-ink-800" wire:click="sortByCol('potential')">Potentiel @if($sort==='potential')<span class="text-brand-600">{{ $dir==='desc'?'↓':'↑' }}</span>@endif</th>@endif
                            @if (in_array('lastActivity', $cols))<th class="px-3 py-3 text-left">Activité</th>@endif
                            @if (in_array('badge', $cols))<th class="px-3 py-3 text-left">Statut</th>@endif
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->pageData['items'] as $s)
                            <tr wire:key="row-{{ $s['id'] }}" class="cursor-pointer border-b border-ink-150 last:border-0 hover:bg-brand-50/40" wire:click="select({{ $s['id'] }})">
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-brand-50 text-[11px] font-bold text-brand-700">{{ $monogram($s['name']) }}</span>
                                        <span class="text-[13.5px] font-semibold text-ink-900">{{ $s['name'] }}</span>
                                    </div>
                                </td>
                                @if (in_array('code', $cols))<td class="px-3 py-3 font-mono text-[12px] text-ink-500">{{ $s['code'] ?: '—' }}</td>@endif
                                @if (in_array('students', $cols))<td class="px-3 py-3 text-right font-mono text-[13px] text-ink-700">{{ $fr($s['students']) }}</td>@endif
                                @if (in_array('known', $cols))<td class="px-3 py-3 text-right font-mono text-[13px] text-ink-700">{{ $fr($s['known']) }}</td>@endif
                                @if (in_array('inscrits', $cols))<td class="px-3 py-3 text-right font-mono text-[13px] text-ink-700">{{ $fr($s['inscrits']) }}</td>@endif
                                @if (in_array('actifs', $cols))<td class="px-3 py-3 text-right font-mono text-[13px] font-semibold text-ink-900">{{ $fr($s['actifs']) }}</td>@endif
                                <td class="px-3 py-3 text-right"><span class="font-mono text-[13px] font-bold" style="color: {{ $s['badge']['color'] }}">{{ number_format($s['rate'], 1, ',', ' ') }} %</span></td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <span class="h-2 w-2 flex-shrink-0 rounded-full" style="background: {{ $s['health']['dot'] }}"></span>
                                        <span class="font-mono text-[13px] font-bold" style="color: {{ $s['health']['color'] }}">{{ $s['health']['score'] }}</span>
                                        <span class="font-mono text-[10.5px] text-ink-400">/100</span>
                                    </div>
                                </td>
                                @if (in_array('revenue', $cols))<td class="px-3 py-3 text-right font-mono text-[12.5px] text-ink-700">{{ $s['revenue'] > 0 ? $money($s['revenue']) : '—' }}</td>@endif
                                @if (in_array('potential', $cols))<td class="px-3 py-3 text-right font-mono text-[12.5px] text-ink-700">{{ $s['potential'] > 0 ? $money($s['potential']) : '—' }}</td>@endif
                                @if (in_array('lastActivity', $cols))<td class="px-3 py-3 text-[12px] text-ink-500">{{ $ago($s['lastActivity']) }}</td>@endif
                                @if (in_array('badge', $cols))<td class="px-3 py-3"><span class="inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-[11.5px] font-semibold" style="background: {{ $s['badge']['bg'] }}; color: {{ $s['badge']['color'] }}">{{ $s['badge']['label'] }}</span></td>@endif
                                <td class="px-4 py-3 text-right" wire:click.stop>
                                    <flux:dropdown>
                                        <button class="flex h-7 w-7 items-center justify-center rounded-md text-ink-500 hover:bg-ink-100 hover:text-ink-800">
                                            <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><circle cx="10" cy="4.5" r="1.5"/><circle cx="10" cy="10" r="1.5"/><circle cx="10" cy="15.5" r="1.5"/></svg>
                                        </button>
                                        <flux:menu>
                                            <flux:menu.item icon="eye" wire:click="select({{ $s['id'] }})">Aperçu rapide</flux:menu.item>
                                            <flux:menu.item icon="arrow-top-right-on-square" href="{{ route('schools.show', $s['id']) }}" wire:navigate>Ouvrir la fiche</flux:menu.item>
                                            <flux:menu.item icon="users" href="{{ route('parents.index') }}" wire:navigate>Voir les parents</flux:menu.item>
                                            <flux:menu.separator />
                                            <flux:menu.item icon="megaphone" disabled>Voir les campagnes — à venir</flux:menu.item>
                                            <flux:menu.item icon="banknotes" disabled>Voir les paiements — à venir</flux:menu.item>
                                            <flux:menu.item icon="document-arrow-down" disabled>Exporter le rapport — à venir</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            {{-- ===== Vue cartes ===== --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->pageData['items'] as $s)
                    <div wire:key="card-{{ $s['id'] }}" class="group cursor-pointer rounded-[16px] border border-ink-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition-all hover:-translate-y-0.5 hover:shadow-[0_10px_28px_rgba(15,23,42,0.08)]" wire:click="select({{ $s['id'] }})">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-brand-50 text-[14px] font-bold text-brand-700">{{ $monogram($s['name']) }}</span>
                                <div class="min-w-0">
                                    <div class="truncate text-[14px] font-bold text-ink-900">{{ $s['name'] }}</div>
                                    <div class="font-mono text-[11.5px] text-ink-500">{{ $s['code'] ?: '—' }}</div>
                                </div>
                            </div>
                            <span class="flex-shrink-0 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background: {{ $s['badge']['bg'] }}; color: {{ $s['badge']['color'] }}">{{ $s['badge']['label'] }}</span>
                        </div>
                        <div class="mt-4 flex items-end justify-between">
                            <div>
                                <div class="text-[28px] font-extrabold tracking-tight" style="color: {{ $s['badge']['color'] }}">{{ number_format($s['rate'], 1, ',', ' ') }} %</div>
                                <div class="text-[11.5px] text-ink-500">taux d'adoption</div>
                            </div>
                            <div class="text-right">
                                <div class="text-[15px] font-bold text-ink-900">{{ $fr($s['actifs']) }}</div>
                                <div class="text-[11.5px] text-ink-500">parents actifs</div>
                            </div>
                        </div>
                        <div class="mt-4 flex items-center gap-2 rounded-xl px-3 py-2" style="background: {{ $s['health']['bg'] }}">
                            <span class="h-2 w-2 rounded-full" style="background: {{ $s['health']['dot'] }}"></span>
                            <span class="text-[12px] font-semibold" style="color: {{ $s['health']['color'] }}">Score de santé</span>
                            <span class="ml-auto font-mono text-[15px] font-bold" style="color: {{ $s['health']['color'] }}">{{ $s['health']['score'] }}<span class="text-[10px] text-ink-400">/100</span></span>
                        </div>
                        <div class="mt-3 flex items-center justify-between border-t border-ink-150 pt-3">
                            <div class="text-[12px] text-ink-500">Potentiel : <span class="font-semibold text-ink-800">{{ $s['potential'] > 0 ? $money($s['potential']) : '—' }}</span></div>
                            <span class="inline-flex items-center gap-1 text-[12.5px] font-semibold text-brand-600">Voir la fiche
                                <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M7 4l6 6-6 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Pagination --}}
        @if ($this->pageData['pages'] > 1)
            <div class="mt-4 flex items-center justify-between">
                <div class="text-[12.5px] text-ink-500">{{ $fr($this->pageData['from']) }}–{{ $fr($this->pageData['to']) }} sur {{ $fr($this->pageData['total']) }}</div>
                <div class="flex items-center gap-1">
                    <button wire:click="gotoPage({{ max(1, $this->pageData['page'] - 1) }})" @disabled($this->pageData['page'] <= 1) class="flex h-8 w-8 items-center justify-center rounded-lg border border-ink-200 text-ink-600 hover:bg-ink-50 disabled:opacity-40">
                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M12 5l-5 5 5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    @for ($p = 1; $p <= $this->pageData['pages']; $p++)
                        <button wire:click="gotoPage({{ $p }})" class="h-8 min-w-8 rounded-lg px-2.5 text-[12.5px] font-semibold {{ $p === $this->pageData['page'] ? 'bg-brand-600 text-white' : 'border border-ink-200 text-ink-700 hover:bg-ink-50' }}">{{ $p }}</button>
                    @endfor
                    <button wire:click="gotoPage({{ min($this->pageData['pages'], $this->pageData['page'] + 1) }})" @disabled($this->pageData['page'] >= $this->pageData['pages']) class="flex h-8 w-8 items-center justify-center rounded-lg border border-ink-200 text-ink-600 hover:bg-ink-50 disabled:opacity-40">
                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M8 5l5 5-5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </div>
            </div>
        @endif
    </div>

    {{-- ===== Quick Preview (panneau latéral) ===== --}}
    <div x-show="preview" x-cloak class="fixed inset-0 z-50" style="display:none">
        <div class="absolute inset-0 bg-ink-900/30" x-show="preview" x-transition.opacity @click="$wire.closePreview()"></div>
        <div class="absolute inset-y-0 right-0 flex w-full max-w-[440px] flex-col bg-white shadow-2xl"
             x-show="preview" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full">
            @if ($s = $this->selected)
                <div class="flex items-start justify-between gap-3 border-b border-ink-150 px-6 py-5">
                    <div class="flex items-center gap-3">
                        <span class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl bg-brand-50 text-[15px] font-bold text-brand-700">{{ $monogram($s['name']) }}</span>
                        <div class="min-w-0">
                            <div class="text-[16px] font-bold text-ink-900">{{ $s['name'] }}</div>
                            <div class="mt-0.5 flex items-center gap-2">
                                <span class="font-mono text-[11.5px] text-ink-500">{{ $s['code'] ?: '—' }}</span>
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background: {{ $s['badge']['bg'] }}; color: {{ $s['badge']['color'] }}">{{ $s['badge']['label'] }}</span>
                            </div>
                        </div>
                    </div>
                    <button wire:click="closePreview" class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg text-ink-500 hover:bg-ink-100">
                        <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-5">
                    {{-- Score de santé --}}
                    <div class="mb-5 rounded-2xl border p-4" style="border-color: {{ $s['health']['color'] }}20; background: {{ $s['health']['bg'] }}">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider" style="color: {{ $s['health']['color'] }}">
                                    <span class="h-2 w-2 rounded-full" style="background: {{ $s['health']['dot'] }}"></span>Score de santé
                                </div>
                                <div class="mt-1 text-[13px] text-ink-600">Indicateur composite de priorité</div>
                            </div>
                            <div class="text-right">
                                <span class="text-[34px] font-extrabold leading-none" style="color: {{ $s['health']['color'] }}">{{ $s['health']['score'] }}</span>
                                <span class="text-[14px] font-bold text-ink-400">/100</span>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-col gap-2">
                            @foreach ($s['health']['breakdown'] as $c)
                                <div class="flex items-center gap-2.5 {{ $c['available'] ? '' : 'opacity-50' }}">
                                    <div class="w-32 flex-shrink-0 text-[12px] font-medium text-ink-700">
                                        {{ $c['label'] }}
                                        <span class="text-[10px] text-ink-400">{{ $c['available'] ? $c['weight'].' %' : 'à venir' }}</span>
                                    </div>
                                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-white/70">
                                        <div class="h-full rounded-full" style="width: {{ $c['available'] ? $c['score'] : 0 }}%; background: {{ $s['health']['color'] }}"></div>
                                    </div>
                                    <div class="w-8 flex-shrink-0 text-right font-mono text-[11.5px] font-semibold text-ink-700">{{ $c['available'] ? $c['score'] : '—' }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="mb-1 text-[11px] font-bold uppercase tracking-wider text-ink-500">Informations générales</div>
                    <div class="mb-5 grid grid-cols-2 gap-y-2 text-[13px]">
                        <div class="text-ink-500">Ville · Région</div><div class="text-right font-medium text-ink-800">— <span class="text-[10.5px] text-ink-400">(à venir)</span></div>
                        <div class="text-ink-500">Commercial</div><div class="text-right font-medium text-ink-800">— <span class="text-[10.5px] text-ink-400">(à venir)</span></div>
                        <div class="text-ink-500">Modèle</div><div class="text-right font-medium text-ink-800">{{ $s['subscriptionModel'] === 'parent_paid' ? 'Abonnement parent' : 'Abonnement inclus' }}</div>
                        <div class="text-ink-500">Dernière activité</div><div class="text-right font-medium text-ink-800">{{ $ago($s['lastActivity']) }}</div>
                    </div>

                    <div class="mb-2 text-[11px] font-bold uppercase tracking-wider text-ink-500">Indicateurs</div>
                    <div class="mb-5 grid grid-cols-2 gap-2.5">
                        @foreach ([['Élèves', $fr($s['students'])], ['Parents connus', $fr($s['known'])], ['Comptes créés', $fr($s['inscrits'])], ['Parents actifs', $fr($s['actifs'])], ["Taux d'adoption", number_format($s['rate'], 1, ',', ' ').' %'], ['Revenus', $s['revenue'] > 0 ? $money($s['revenue']) : '—']] as [$l, $v])
                            <div class="rounded-xl border border-ink-150 bg-ink-50 px-3 py-2.5">
                                <div class="text-[17px] font-bold text-ink-900">{{ $v }}</div>
                                <div class="text-[11px] text-ink-500">{{ $l }}</div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mb-2 text-[11px] font-bold uppercase tracking-wider text-ink-500">Évolution de l'adoption (6 mois)</div>
                    <div class="mb-5 rounded-xl border border-ink-150 bg-white p-4">
                        @php $ss = $s['spark']; @endphp
                        <div class="flex items-end justify-between gap-4">
                            <div>
                                <div class="text-[22px] font-bold text-ink-900">{{ number_format(end($ss), 1, ',', ' ') }} %</div>
                                <div class="text-[11px] text-ink-500">taux actuel</div>
                            </div>
                            <div>{!! $sparkSvg($ss, '#2554C7') !!}</div>
                        </div>
                    </div>

                    <div class="mb-2 text-[11px] font-bold uppercase tracking-wider text-ink-500">Dernières campagnes</div>
                    <div class="mb-2 flex flex-col items-center gap-1.5 rounded-xl border border-dashed border-ink-200 bg-ink-50 py-6 text-center">
                        <svg width="22" height="22" viewBox="0 0 20 20" fill="none" class="text-ink-300"><rect x="2.5" y="4" width="15" height="10" rx="3" stroke="currentColor" stroke-width="1.5"/><polygon points="6,14 6,18 10,14" fill="currentColor"/></svg>
                        <span class="text-[12px] text-ink-500">Module Campagnes à venir</span>
                    </div>
                </div>

                <div class="border-t border-ink-150 px-6 py-4">
                    <div class="grid grid-cols-2 gap-2">
                        <a href="{{ route('schools.show', $s['id']) }}" wire:navigate class="col-span-2 inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2.5 text-[12.5px] font-semibold text-white hover:bg-brand-700">
                            Ouvrir la fiche complète
                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M7 4l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </a>
                        <a href="{{ route('parents.index') }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-ink-200 px-3 py-2.5 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">Voir les parents</a>
                        <button wire:click="export" class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-ink-200 px-3 py-2.5 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">Exporter</button>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
