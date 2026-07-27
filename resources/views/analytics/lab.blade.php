<?php

use App\Domains\Analytics\Actions\RunAnalysis;
use App\Domains\Analytics\Models\SavedAnalysis;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $dimension = 'school';

    #[Url]
    public string $viz = 'table';

    public array $measures = ['adoptants', 'revenus'];

    public string $viewName = '';

    public ?int $loadedId = null;

    #[Computed]
    public function result(): array
    {
        return app(RunAnalysis::class)($this->dimension, $this->measures);
    }

    #[Computed]
    public function views(): Collection
    {
        return SavedAnalysis::query()->latest()->get();
    }

    private function push(): void
    {
        unset($this->result);
        $this->dispatch('lab:data', payload: ['rows' => $this->result, 'measures' => $this->measures, 'viz' => $this->viz, 'dimension' => $this->dimension]);
    }

    public function setDimension(string $d): void
    {
        if (! (RunAnalysis::DIMENSIONS[$d]['available'] ?? false)) {
            return;
        }
        $this->dimension = $d;
        $this->push();
    }

    public function toggleMeasure(string $m): void
    {
        if (in_array($m, $this->measures, true)) {
            if (count($this->measures) > 1) {
                $this->measures = array_values(array_diff($this->measures, [$m]));
            }
        } else {
            $this->measures[] = $m;
        }
        $this->push();
    }

    public function setViz(string $v): void
    {
        $this->viz = $v;
        $this->push();
    }

    public function saveView(): void
    {
        $this->validate(['viewName' => 'required|min:2']);
        SavedAnalysis::create(['name' => $this->viewName, 'dimension' => $this->dimension, 'measures' => $this->measures, 'viz' => $this->viz]);
        $this->viewName = '';
        unset($this->views);
    }

    public function loadView(int $id): void
    {
        $v = SavedAnalysis::find($id);
        if (! $v) {
            return;
        }
        $this->dimension = $v->dimension;
        $this->measures = $v->measures;
        $this->viz = $v->viz;
        $this->loadedId = $id;
        $this->push();
    }

    public function deleteView(int $id): void
    {
        SavedAnalysis::whereKey($id)->delete();
        unset($this->views);
    }

    public function export()
    {
        $rows = $this->result;
        $measures = $this->measures;

        return response()->streamDownload(function () use ($rows, $measures) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge([RunAnalysis::DIMENSIONS[$this->dimension]['label']], array_map(fn ($m) => RunAnalysis::MEASURES[$m]['label'], $measures)));
            foreach ($rows as $r) {
                fputcsv($out, array_merge([$r['label']], array_map(fn ($m) => $r['values'][$m] ?? '', $measures)));
            }
            fclose($out);
        }, 'analyse-'.now()->format('Y-m-d').'.csv');
    }
};

?>

@php
    $DIM = App\Domains\Analytics\Actions\RunAnalysis::DIMENSIONS;
    $MES = App\Domains\Analytics\Actions\RunAnalysis::MEASURES;
    $vizList = ['table' => 'Tableau', 'bar' => 'Barres', 'line' => 'Courbe', 'donut' => 'Donut', 'treemap' => 'Treemap', 'heatmap' => 'Heatmap'];
    $vizIcons = [
        'table' => '<rect x="3" y="4" width="14" height="12" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M3 8h14M3 12h14M8 4v12" stroke="currentColor" stroke-width="1.4"/>',
        'bar' => '<rect x="3" y="10" width="3.5" height="7" rx="1" fill="currentColor"/><rect x="8.3" y="6" width="3.5" height="11" rx="1" fill="currentColor"/><rect x="13.6" y="3" width="3.5" height="14" rx="1" fill="currentColor"/>',
        'line' => '<path d="M3 14l4-5 4 3 6-8" stroke="currentColor" stroke-width="1.7" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
        'donut' => '<circle cx="10" cy="10" r="6.5" stroke="currentColor" stroke-width="3" fill="none"/>',
        'treemap' => '<rect x="3" y="3" width="8" height="8" rx="1" fill="currentColor"/><rect x="12" y="3" width="5" height="5" rx="1" fill="currentColor" opacity="0.6"/><rect x="12" y="9" width="5" height="8" rx="1" fill="currentColor" opacity="0.4"/><rect x="3" y="12" width="8" height="5" rx="1" fill="currentColor" opacity="0.5"/>',
        'heatmap' => '<rect x="3" y="3" width="4" height="4" fill="currentColor"/><rect x="8" y="3" width="4" height="4" fill="currentColor" opacity="0.5"/><rect x="13" y="3" width="4" height="4" fill="currentColor" opacity="0.3"/><rect x="3" y="8" width="4" height="4" fill="currentColor" opacity="0.4"/><rect x="8" y="8" width="4" height="4" fill="currentColor"/><rect x="13" y="8" width="4" height="4" fill="currentColor" opacity="0.6"/><rect x="3" y="13" width="4" height="4" fill="currentColor" opacity="0.6"/><rect x="8" y="13" width="4" height="4" fill="currentColor" opacity="0.3"/><rect x="13" y="13" width="4" height="4" fill="currentColor"/>',
    ];
    $fr = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $fmt = function ($m, $v) use ($fr, $MES) {
        if ($v === null) {
            return '—';
        }

        return match ($MES[$m]['format']) {
            'pct' => number_format($v, 1, ',', ' ').' %',
            'money' => $v >= 1_000_000 ? number_format($v / 1_000_000, 1, ',', ' ').' M F' : $fr($v).' F',
            default => $fr($v),
        };
    };
    $rows = $this->result;
@endphp

<div class="mx-auto max-w-[1480px]">

    {{-- Fil d'Ariane --}}
    <nav class="mb-4 flex items-center gap-1.5 text-[12.5px] text-ink-500">
        <a href="{{ route('analytics.index') }}" wire:navigate class="hover:text-ink-800">Analytics</a><span>/</span>
        <span class="font-semibold text-ink-800">Laboratoire d'Analyses</span>
    </nav>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-[22px] font-bold tracking-tight text-ink-900">Laboratoire d'Analyses</h1>
            <p class="mt-1 max-w-2xl text-[13px] text-ink-500">Construisez vos analyses sans écrire de requête : choisissez une dimension, des mesures et un type de graphique, puis enregistrez la vue pour la réutiliser.</p>
        </div>
        <button wire:click="export" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 3v9m0 0l-3.2-3.2M10 12l3.2-3.2M4 15.5h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Exporter
        </button>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[300px_1fr]">
        {{-- Panneau de construction --}}
        <div class="flex flex-col gap-4">
            <div class="rounded-[16px] border border-ink-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="mb-2 text-[11px] font-bold uppercase tracking-wide text-ink-500">Dimension</div>
                <div class="flex flex-col gap-1.5">
                    @foreach ($DIM as $key => $dim)
                        @if ($dim['available'])
                            <button wire:click="setDimension('{{ $key }}')" class="flex items-center justify-between rounded-lg px-3 py-2 text-[13px] font-semibold transition-colors {{ $dimension === $key ? 'bg-brand-50 text-brand-700' : 'text-ink-700 hover:bg-ink-100' }}">
                                {{ $dim['label'] }}
                                @if ($dimension === $key)<svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M5 10.5l3.5 3.5L15 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>@endif
                            </button>
                        @else
                            <div class="flex cursor-not-allowed items-center justify-between rounded-lg px-3 py-2 text-[13px] font-medium text-ink-400" title="{{ $dim['note'] ?? '' }}">
                                {{ $dim['label'] }}<span class="rounded bg-ink-100 px-1 text-[9px] font-bold uppercase">à venir</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="rounded-[16px] border border-ink-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="mb-2 text-[11px] font-bold uppercase tracking-wide text-ink-500">Mesures</div>
                <div class="flex flex-col gap-1">
                    @foreach ($MES as $key => $mes)
                        @php $on = in_array($key, $measures, true); @endphp
                        <button wire:click="toggleMeasure('{{ $key }}')" class="flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-left text-[13px] font-medium transition-colors {{ $on ? 'text-ink-900' : 'text-ink-600 hover:bg-ink-100' }}">
                            <span class="flex h-4 w-4 flex-shrink-0 items-center justify-center rounded border {{ $on ? 'border-brand-600 bg-brand-600 text-white' : 'border-ink-300' }}">
                                @if ($on)<svg width="11" height="11" viewBox="0 0 20 20" fill="none"><path d="M5 10.5l3.5 3.5L15 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>@endif
                            </span>
                            {{ $mes['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="rounded-[16px] border border-ink-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="mb-2 text-[11px] font-bold uppercase tracking-wide text-ink-500">Visualisation</div>
                <div class="grid grid-cols-3 gap-2">
                    @foreach ($vizList as $key => $label)
                        <button wire:click="setViz('{{ $key }}')" class="flex flex-col items-center gap-1 rounded-lg border px-2 py-2.5 text-[11px] font-semibold transition-colors {{ $viz === $key ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-600 hover:bg-ink-50' }}">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none">{!! $vizIcons[$key] !!}</svg>
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Vues enregistrées --}}
            <div class="rounded-[16px] border border-ink-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="mb-2 text-[11px] font-bold uppercase tracking-wide text-ink-500">Enregistrer la vue</div>
                <div class="flex gap-1.5">
                    <input wire:model="viewName" wire:keydown.enter="saveView" placeholder="Nom de l'analyse" class="min-w-0 flex-1 rounded-lg border border-ink-300 px-2.5 py-1.5 text-[12.5px] outline-none focus:border-brand-600">
                    <button wire:click="saveView" class="flex-shrink-0 rounded-lg bg-brand-600 px-3 py-1.5 text-[12px] font-semibold text-white hover:bg-brand-700">Enregistrer</button>
                </div>
                @error('viewName')<div class="mt-1 text-[11px] text-danger">{{ $message }}</div>@enderror
                @if ($this->views->count())
                    <div class="mt-3 flex flex-col gap-1">
                        @foreach ($this->views as $v)
                            <div class="group flex items-center justify-between rounded-lg px-2.5 py-1.5 hover:bg-ink-50">
                                <button wire:click="loadView({{ $v->id }})" class="min-w-0 flex-1 truncate text-left text-[12.5px] font-medium text-ink-800 hover:text-brand-700">{{ $v->name }}</button>
                                <button wire:click="deleteView({{ $v->id }})" class="ml-2 text-ink-400 opacity-0 transition-opacity hover:text-danger group-hover:opacity-100"><svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Résultat --}}
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-4 flex items-center justify-between">
                <div class="text-[15px] font-semibold text-ink-900">{{ $DIM[$dimension]['label'] }} × {{ collect($measures)->map(fn ($m) => $MES[$m]['label'])->join(', ') }}</div>
                <span class="text-[12px] text-ink-500">{{ count($rows) }} lignes</span>
            </div>

            @if ($viz === 'table')
                <div class="overflow-x-auto rounded-xl border border-ink-150">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-ink-50 text-[11px] font-bold uppercase tracking-wider text-ink-500">
                                <th class="px-4 py-2.5 text-left">{{ $DIM[$dimension]['label'] }}</th>
                                @foreach ($measures as $m)<th class="px-3 py-2.5 text-right">{{ $MES[$m]['label'] }}</th>@endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (array_slice($rows, 0, 40) as $r)
                                <tr class="border-b border-ink-150 last:border-0 hover:bg-ink-50">
                                    <td class="px-4 py-2 text-[13px] font-semibold text-ink-900">{{ $r['label'] }}</td>
                                    @foreach ($measures as $m)<td class="px-3 py-2 text-right font-mono text-[12.5px] text-ink-700">{{ $fmt($m, $r['values'][$m]) }}</td>@endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if (count($rows) > 40)<div class="mt-2 text-center text-[11.5px] text-ink-400">40 premières lignes affichées — exportez pour la totalité.</div>@endif
            @else
                <div wire:ignore><div id="lab-chart" class="h-[440px] w-full"></div></div>
                <div class="mt-2 text-[11.5px] text-ink-400">Le graphique affiche la 1ᵉʳ mesure sélectionnée@if ($viz === 'heatmap'), normalisée par colonne@endif. Le tableau reste disponible pour toutes les mesures.</div>
            @endif
        </div>
    </div>
</div>

@script
<script>
    (() => {
        if (! window.echarts) return;
        const g = window.echarts.graphic;
        const inter = 'Inter';
        const axis = { color: '#B7BCC5', fontFamily: inter, fontSize: 11 };
        const measureLabels = @js(collect(App\Domains\Analytics\Actions\RunAnalysis::MEASURES)->map(fn ($m) => $m['label']));
        let chart = null;

        const render = (d) => {
            if (d.viz === 'table') { if (chart) { chart.dispose(); chart = null; } return; }
            const el = document.getElementById('lab-chart');
            if (! el) return;
            if (chart) chart.dispose();
            chart = window.echarts.init(el);

            const rows = d.rows.slice(0, d.viz === 'heatmap' ? 14 : 15);
            const m0 = d.measures[0];
            const labels = rows.map(r => r.label.length > 18 ? r.label.slice(0, 17) + '…' : r.label);
            const vals = rows.map(r => r.values[m0] ?? 0);
            const blue = new g.LinearGradient(0, 0, 0, 1, [{ offset: 0, color: '#2554C7' }, { offset: 1, color: '#7DA0EC' }]);

            if (d.viz === 'bar') {
                chart.setOption({
                    grid: { left: 8, right: 20, top: 16, bottom: 90, containLabel: true },
                    tooltip: { trigger: 'axis' },
                    xAxis: { type: 'category', data: labels, axisTick: { show: false }, axisLine: { lineStyle: { color: '#E7E9ED' } }, axisLabel: { ...axis, rotate: 40, interval: 0 } },
                    yAxis: { type: 'value', splitLine: { lineStyle: { color: '#F0F1F3' } }, axisLabel: axis },
                    series: [{ type: 'bar', data: vals, barMaxWidth: 26, itemStyle: { borderRadius: [4, 4, 0, 0], color: blue } }],
                });
            } else if (d.viz === 'line') {
                chart.setOption({
                    grid: { left: 40, right: 20, top: 16, bottom: 60, containLabel: true },
                    tooltip: { trigger: 'axis' },
                    xAxis: { type: 'category', boundaryGap: false, data: labels, axisTick: { show: false }, axisLine: { lineStyle: { color: '#E7E9ED' } }, axisLabel: { ...axis, rotate: 30 } },
                    yAxis: { type: 'value', splitLine: { lineStyle: { color: '#F0F1F3' } }, axisLabel: axis },
                    series: [{ type: 'line', data: vals, smooth: 0.3, showSymbol: false, lineStyle: { color: '#2554C7', width: 3 }, areaStyle: { color: 'rgba(37,84,199,0.12)' } }],
                });
            } else if (d.viz === 'donut') {
                chart.setOption({
                    tooltip: { trigger: 'item' },
                    legend: { type: 'scroll', bottom: 0, textStyle: { fontFamily: inter, fontSize: 10 } },
                    series: [{ type: 'pie', radius: ['45%', '72%'], center: ['50%', '44%'], label: { show: false }, itemStyle: { borderColor: '#fff', borderWidth: 2 }, data: rows.slice(0, 10).map(r => ({ name: r.label, value: r.values[m0] ?? 0 })) }],
                });
            } else if (d.viz === 'treemap') {
                chart.setOption({
                    tooltip: {},
                    series: [{ type: 'treemap', roam: false, breadcrumb: { show: false }, label: { fontFamily: inter, fontSize: 11 }, data: rows.map(r => ({ name: r.label, value: r.values[m0] ?? 0 })), levels: [{ itemStyle: { borderColor: '#fff', borderWidth: 2, gapWidth: 2 } }], colorMappingBy: 'value' }],
                    color: ['#2554C7'],
                });
            } else if (d.viz === 'heatmap') {
                const measures = d.measures;
                const cells = [];
                measures.forEach((m, x) => {
                    const col = rows.map(r => r.values[m] ?? 0);
                    const mx = Math.max(...col, 1);
                    rows.forEach((r, y) => cells.push([x, y, mx > 0 ? Math.round((r.values[m] ?? 0) / mx * 100) : 0]));
                });
                chart.setOption({
                    tooltip: { formatter: p => rows[p.value[1]].label + ' · ' + measureLabels[measures[p.value[0]]] + ' : ' + p.value[2] + ' (max=100)' },
                    grid: { left: 8, right: 12, top: 40, bottom: 8, containLabel: true },
                    xAxis: { type: 'category', position: 'top', data: measures.map(m => measureLabels[m]), axisLabel: { ...axis, interval: 0, fontSize: 10 }, splitArea: { show: true } },
                    yAxis: { type: 'category', data: labels, axisLabel: { ...axis, fontSize: 10 }, splitArea: { show: true } },
                    visualMap: { min: 0, max: 100, show: false, inRange: { color: ['#EEF3FE', '#2554C7'] } },
                    series: [{ type: 'heatmap', data: cells, label: { show: true, fontSize: 9, color: '#3A4150' } }],
                });
            }
        };

        render(@js(['rows' => $this->result, 'measures' => $this->measures, 'viz' => $this->viz, 'dimension' => $this->dimension]));
        window.addEventListener('resize', () => chart && chart.resize());
        $wire.on('lab:data', (e) => render(e.payload ?? e[0]?.payload ?? e));
    })();
</script>
@endscript
