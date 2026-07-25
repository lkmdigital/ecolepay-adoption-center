<?php

use App\Domains\Dashboard\Actions\ComputeExecutiveDashboard;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $period = 'year';

    #[Computed]
    public function data(): array
    {
        return app(ComputeExecutiveDashboard::class)($this->period);
    }

    public function setPeriod(string $p): void
    {
        $this->period = $p;
        unset($this->data);
    }

    public function refreshData(): void
    {
        unset($this->data);
    }

    public function export()
    {
        $kpis = $this->data['kpis'];
        $rows = collect($kpis)->map(fn ($k) => [$k['label'], $k['value']]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Indicateur', 'Valeur']);
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
        }, 'dashboard-kpis-'.now()->format('Y-m-d').'.csv');
    }
};

?>

@php
    $fr = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $money = fn ($n) => $n >= 1_000_000 ? number_format($n / 1_000_000, 1, ',', ' ').' M F' : $fr($n).' F';
    $fmt = function ($k) use ($fr, $money) {
        return match ($k['format']) {
            'pct' => number_format($k['value'], 1, ',', ' ').' %',
            'money' => $money($k['value']),
            default => $fr($k['value']),
        };
    };

    // Génère une sparkline SVG à partir d'une série de valeurs.
    $sparkline = function (?array $series, string $color) {
        if (! $series || count($series) < 2 || max($series) == 0) {
            return '';
        }
        $w = 96;
        $h = 30;
        $n = count($series);
        $max = max($series);
        $min = min($series);
        $range = max($max - $min, 1);
        $pts = [];
        foreach ($series as $i => $v) {
            $x = round($i / ($n - 1) * $w, 1);
            $y = round($h - 3 - ($v - $min) / $range * ($h - 6), 1);
            $pts[] = "$x,$y";
        }
        $line = implode(' ', $pts);
        $area = "0,$h ".$line." $w,$h";

        return '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" fill="none" class="overflow-visible">'
            .'<polygon points="'.$area.'" fill="'.$color.'" opacity="0.08"/>'
            .'<polyline points="'.$line.'" fill="none" stroke="'.$color.'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    };

    $periods = ['7d' => '7 jours', '30d' => '30 jours', 'year' => 'Année scolaire'];

    $kpiIcons = [
        'ecoles' => '<polygon points="10,2 17,7 3,7" fill="currentColor"/><rect x="4" y="7.5" width="12" height="9.5" rx="1" stroke="currentColor" stroke-width="1.6" fill="none"/>',
        'eleves' => '<circle cx="10" cy="6" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M4 17c0-3.3 2.7-6 6-6s6 2.7 6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
        'parents' => '<circle cx="7.2" cy="6.5" r="3" stroke="currentColor" stroke-width="1.6"/><circle cx="14" cy="8" r="2.2" stroke="currentColor" stroke-width="1.6" opacity="0.6"/><path d="M2.5 17c0-3 2.1-5 4.7-5s4.7 2 4.7 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
        'inscrits' => '<rect x="4" y="3" width="12" height="14" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M7 8h6M7 11h6M7 14h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
        'actifs' => '<path d="M4 10.5l3.5 3.5L16 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
        'adoption' => '<circle cx="6" cy="6" r="2.3" stroke="currentColor" stroke-width="1.6"/><circle cx="14" cy="14" r="2.3" stroke="currentColor" stroke-width="1.6"/><line x1="15" y1="5" x2="5" y2="15" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
        'ca_sub' => '<rect x="3" y="5" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.6"/><circle cx="10" cy="10" r="2.2" stroke="currentColor" stroke-width="1.5"/>',
        'ca_pay' => '<circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.6"/><path d="M10 6.5v7M12.2 8.2c0-.9-1-1.5-2.2-1.5s-2.2.7-2.2 1.6c0 2.2 4.4 1 4.4 3.2 0 .9-1 1.6-2.2 1.6s-2.2-.6-2.2-1.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>',
    ];
    $kpiColors = [
        'ecoles' => ['var(--color-brand-50)', 'var(--color-brand-600)'],
        'eleves' => ['var(--color-ink-100)', 'var(--color-ink-800)'],
        'parents' => ['var(--color-ink-100)', 'var(--color-ink-800)'],
        'inscrits' => ['var(--color-brand-50)', 'var(--color-brand-600)'],
        'actifs' => ['var(--color-success-soft)', 'var(--color-success)'],
        'adoption' => ['var(--color-brand-50)', 'var(--color-brand-600)'],
        'ca_sub' => ['var(--color-warning-soft)', 'var(--color-warning)'],
        'ca_pay' => ['var(--color-warning-soft)', 'var(--color-warning)'],
    ];
    $sparkColor = ['inscrits' => '#2554C7', 'actifs' => '#189B57', 'adoption' => '#2554C7', 'ca_pay' => '#D97706'];

    $prioBadge = [
        'critique' => ['Critique', '#B91C1C', '#FDECEC'],
        'elevee' => ['Élevée', '#B45F04', '#FEF3E2'],
        'moyenne' => ['Moyenne', '#1D3F9C', '#EEF3FE'],
        'faible' => ['Faible', '#5B6472', '#F2F3F5'],
    ];
    $alertStyle = [
        'danger' => ['#B91C1C', '#FDECEC'],
        'warning' => ['#B45F04', '#FEF3E2'],
        'info' => ['#1D3F9C', '#EEF3FE'],
        'success' => ['#0F7A44', '#E9F8EF'],
    ];
@endphp

<div class="mx-auto max-w-[1480px]">

    {{-- Barre d'actions --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex rounded-lg border border-ink-200 bg-white p-0.5">
            @foreach ($periods as $key => $label)
                <button wire:click="setPeriod('{{ $key }}')"
                        class="rounded-md px-3 py-1.5 text-[12.5px] font-semibold transition-colors
                               {{ $period === $key ? 'bg-brand-600 text-white' : 'text-ink-600 hover:text-ink-900' }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="export"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 3v9m0 0l-3.2-3.2M10 12l3.2-3.2M4 15.5h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Exporter
            </button>
            <button wire:click="refreshData"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" wire:loading.class="animate-spin" wire:target="refreshData"><path d="M16 6a7 7 0 10.9 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M16.5 3v3.2h-3.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Actualiser
            </button>
        </div>
    </div>

    {{-- 1. KPI principaux --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->data['kpis'] as $k)
            @php [$bg, $fg] = $kpiColors[$k['key']]; @endphp
            <div class="group rounded-[14px] border border-ink-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition-all hover:-translate-y-0.5 hover:shadow-[0_6px_20px_rgba(15,23,42,0.07)]">
                <div class="flex items-start justify-between">
                    <div class="flex h-9 w-9 items-center justify-center rounded-[9px]" style="background: {{ $bg }}; color: {{ $fg }}">
                        <svg width="17" height="17" viewBox="0 0 20 20" fill="none">{!! $kpiIcons[$k['key']] !!}</svg>
                    </div>
                    @if ($k['delta'])
                        @php $up = $k['delta']['dir'] === 'up'; @endphp
                        <span class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[11.5px] font-semibold"
                              style="background: {{ $up ? 'var(--color-success-soft)' : 'var(--color-danger-soft)' }}; color: {{ $up ? 'var(--color-success)' : 'var(--color-danger)' }}">
                            <svg width="11" height="11" viewBox="0 0 20 20" fill="none" style="{{ $up ? '' : 'transform:rotate(180deg)' }}"><path d="M10 5v10M6 9l4-4 4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            {{ $k['delta']['pct'] !== null ? $fr($k['delta']['pct']).' %' : 'nouveau' }}
                        </span>
                    @endif
                </div>
                <div class="mt-3.5 flex items-end justify-between gap-2">
                    <div class="min-w-0">
                        <div class="truncate text-[24px] font-bold tracking-tight text-ink-900">{{ $fmt($k) }}</div>
                        <div class="mt-0.5 text-[12.5px] font-semibold text-ink-700">{{ $k['label'] }}</div>
                    </div>
                    @if ($spark = $sparkline($k['spark'] ?? null, $sparkColor[$k['key']] ?? '#2554C7'))
                        <div class="flex-shrink-0 opacity-90">{!! $spark !!}</div>
                    @endif
                </div>
                <div class="mt-1 truncate text-[11.5px] text-ink-500">{{ $k['sub'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- 2. Santé globale : 2 graphiques --}}
    <div class="mt-8 mb-4 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-600">Santé globale</div>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="rounded-[14px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="text-[15px] font-semibold text-ink-900">Évolution du taux d'adoption</div>
            <div class="mb-3 text-[12px] text-ink-500">Adoptants cumulés rapportés aux parents connus, par mois</div>
            <div wire:ignore><div id="chart-adoption" class="h-[260px] w-full"></div></div>
        </div>
        <div class="rounded-[14px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="text-[15px] font-semibold text-ink-900">Évolution des revenus</div>
            <div class="mb-3 text-[12px] text-ink-500">Paiements réels via l'app · abonnements estimés (M FCFA)</div>
            <div wire:ignore><div id="chart-revenue" class="h-[260px] w-full"></div></div>
        </div>
    </div>

    {{-- 3. Répartition des parents --}}
    <div class="mt-8 mb-4 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-600">Répartition des parents</div>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="rounded-[14px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div wire:ignore><div id="chart-donut" class="h-[240px] w-full"></div></div>
        </div>
        <div class="lg:col-span-2 rounded-[14px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-4 text-[15px] font-semibold text-ink-900">Où se situent les parents</div>
            @php $totalRep = max(1, collect($this->data['repartition'])->sum('value')); @endphp
            <div class="flex flex-col gap-4">
                @foreach ($this->data['repartition'] as $seg)
                    <div class="flex items-center gap-3">
                        <span class="h-2.5 w-2.5 flex-shrink-0 rounded-full" style="background: {{ $seg['color'] }}"></span>
                        <div class="w-52 flex-shrink-0 text-[13px] font-medium text-ink-800">{{ $seg['label'] }}</div>
                        <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-ink-100">
                            <div class="h-full rounded-full" style="width: {{ max(1, round($seg['value'] / $totalRep * 100)) }}%; background: {{ $seg['color'] }}"></div>
                        </div>
                        <div class="w-28 flex-shrink-0 text-right">
                            <span class="font-mono text-[13.5px] font-semibold text-ink-900">{{ $fr($seg['value']) }}</span>
                            <span class="text-[11.5px] text-ink-500">· {{ round($seg['value'] / $totalRep * 100) }} %</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- 4. Les écoles --}}
    <div class="mt-8 mb-4 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-600">Les écoles</div>
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        {{-- Top 10 --}}
        <div class="overflow-hidden rounded-[14px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="border-b border-ink-150 px-6 py-4 text-[15px] font-semibold text-ink-900">Top 10 des écoles</div>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-ink-50 text-[11px] font-bold uppercase tracking-wider text-ink-500">
                        <th class="px-5 py-2.5 text-left">#</th>
                        <th class="px-2 py-2.5 text-left">École</th>
                        <th class="px-3 py-2.5 text-right">Adoption</th>
                        <th class="px-3 py-2.5 text-right">Élan 90j</th>
                        <th class="px-5 py-2.5 text-right">Potentiel</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->data['topSchools'] as $i => $s)
                        <tr class="border-b border-ink-150 last:border-0 hover:bg-ink-50">
                            <td class="px-5 py-3 text-[13px] font-bold text-ink-400">{{ $i + 1 }}</td>
                            <td class="px-2 py-3 text-[13px] font-semibold text-ink-900">{{ $s['name'] }}</td>
                            <td class="px-3 py-3 text-right font-mono text-[13px] font-semibold text-ink-900">{{ number_format($s['rate'], 1, ',', ' ') }} %</td>
                            <td class="px-3 py-3 text-right font-mono text-[12.5px] {{ $s['recent'] > 0 ? 'text-success' : 'text-ink-400' }}">{{ $s['recent'] > 0 ? '+'.$fr($s['recent']) : '—' }}</td>
                            <td class="px-5 py-3 text-right font-mono text-[12.5px] text-ink-700">{{ $s['potential'] > 0 ? $money($s['potential']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{-- Écoles à action --}}
        <div class="overflow-hidden rounded-[14px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="border-b border-ink-150 px-6 py-4 text-[15px] font-semibold text-ink-900">Écoles nécessitant une action</div>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-ink-50 text-[11px] font-bold uppercase tracking-wider text-ink-500">
                        <th class="px-5 py-2.5 text-left">École</th>
                        <th class="px-3 py-2.5 text-right">Adoption</th>
                        <th class="px-3 py-2.5 text-left">Priorité</th>
                        <th class="px-5 py-2.5 text-right">Gain pot.</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->data['actionSchools'] as $s)
                        @php [$plabel, $pfg, $pbg] = $prioBadge[$s['priority']]; @endphp
                        <tr class="border-b border-ink-150 last:border-0 hover:bg-ink-50">
                            <td class="px-5 py-3 text-[13px] font-semibold text-ink-900">{{ $s['name'] }}</td>
                            <td class="px-3 py-3 text-right font-mono text-[13px] font-semibold" style="color: {{ $s['rate'] < 15 ? '#B91C1C' : '#B45F04' }}">{{ number_format($s['rate'], 1, ',', ' ') }} %</td>
                            <td class="px-3 py-3"><span class="inline-block rounded-full px-2 py-0.5 text-[11.5px] font-semibold" style="background: {{ $pbg }}; color: {{ $pfg }}">{{ $plabel }}</span></td>
                            <td class="px-5 py-3 text-right font-mono text-[12.5px] text-ink-700">{{ $s['potential'] > 0 ? $money($s['potential']) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-10 text-center text-[13px] text-ink-500">Aucune école en zone critique.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 5. Opportunités de croissance --}}
    <div class="mt-8 mb-4 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-600">Opportunités</div>
    <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
        <div class="mb-5 flex items-center gap-2.5">
            <div class="flex h-8 w-8 items-center justify-center rounded-[9px] bg-brand-50 text-brand-600">
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M10 2l2.2 5.3L18 8l-4 3.9.9 5.6L10 15l-4.9 2.5.9-5.6L2 8l5.8-.7z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
            </div>
            <div>
                <div class="text-[15px] font-bold text-ink-900">Opportunités de croissance</div>
                <div class="text-[12px] text-ink-500">Les écoles au plus fort revenu d'abonnement non exploité</div>
            </div>
        </div>
        <div class="flex flex-col divide-y divide-ink-150">
            @foreach ($this->data['opportunities'] as $i => $o)
                <div class="flex flex-wrap items-center gap-x-6 gap-y-3 py-4 first:pt-0 last:pb-0">
                    <div class="flex min-w-[220px] flex-[2_1_240px] items-center gap-3">
                        <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-brand-50 text-[12px] font-bold text-brand-700">{{ $i + 1 }}</span>
                        <div class="min-w-0">
                            <div class="truncate text-[14px] font-semibold text-ink-900">{{ $o['name'] }}</div>
                            <div class="text-[12px] text-ink-500">{{ $fr($o['nonAdopters']) }} parents non actifs · adoption {{ number_format($o['rate'], 1, ',', ' ') }} %</div>
                        </div>
                    </div>
                    <div class="flex-1">
                        <div class="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Potentiel estimé</div>
                        <div class="text-[17px] font-bold text-success">{{ $money($o['potential']) }}</div>
                    </div>
                    <a href="{{ route('schools.index') }}" wire:navigate
                       class="inline-flex flex-shrink-0 items-center gap-1 rounded-lg border border-ink-200 px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
                        Voir les détails
                        <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M7 4l6 6-6 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                </div>
            @endforeach
        </div>
    </div>

    {{-- 6 & 7. Alertes + Recommandations --}}
    <div class="mt-8 grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Alertes intelligentes --}}
        <div>
            <div class="mb-4 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-600">Alertes intelligentes</div>
            <div class="rounded-[14px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="flex flex-col gap-1">
                    @foreach ($this->data['alerts'] as $j => $a)
                        @php [$afg, $abg] = $alertStyle[$a['level']]; @endphp
                        <div class="relative flex gap-3.5 pb-5 last:pb-0">
                            @if (! $loop->last)<span class="absolute left-[15px] top-8 bottom-0 w-px bg-ink-150"></span>@endif
                            <span class="relative z-10 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full" style="background: {{ $abg }}; color: {{ $afg }}">
                                <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M10 6.5v4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="10" cy="13.8" r="1" fill="currentColor"/><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.4"/></svg>
                            </span>
                            <div class="min-w-0 pt-0.5">
                                <div class="flex items-center gap-2">
                                    <span class="text-[13.5px] font-semibold text-ink-900">{{ $a['title'] }}</span>
                                    <span class="rounded px-1.5 py-0.5 text-[10.5px] font-bold uppercase tracking-wide" style="background: {{ $abg }}; color: {{ $afg }}">{{ $a['priority'] }}</span>
                                </div>
                                <div class="mt-0.5 text-[12.5px] leading-snug text-ink-600">{{ $a['detail'] }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Recommandations IA --}}
        <div>
            <div class="mb-4 flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-600">
                Recommandations IA
                <span class="rounded bg-ink-100 px-1.5 py-0.5 text-[9.5px] font-bold tracking-normal text-ink-500 normal-case">règles métier · v1</span>
            </div>
            <div class="flex flex-col gap-3">
                @foreach ($this->data['recommendations'] as $r)
                    @php [$rlabel, $rfg, $rbg] = $prioBadge[$r['priority']]; @endphp
                    <div class="rounded-[14px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-2.5">
                                <span class="mt-0.5 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600">
                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M10 2.5a5.5 5.5 0 00-3 10.1V15h6v-2.4a5.5 5.5 0 00-3-10.1z" stroke="currentColor" stroke-width="1.5"/><path d="M8 17h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                </span>
                                <div class="text-[13.5px] font-semibold leading-snug text-ink-900">{{ $r['title'] }}</div>
                            </div>
                            <span class="flex-shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background: {{ $rbg }}; color: {{ $rfg }}">{{ $rlabel }}</span>
                        </div>
                        <div class="mt-2 pl-[34px] text-[12.5px] leading-snug text-ink-600">{{ $r['why'] }}</div>
                        <div class="mt-2.5 pl-[34px]">
                            <button class="inline-flex items-center gap-1 text-[12.5px] font-semibold text-brand-600 hover:underline">
                                Voir l'analyse
                                <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M7 4l6 6-6 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

@script
<script>
    (() => {
        if (! window.echarts) return;
        const d = @js($this->data);
        const inter = 'Inter';
        const axisLabel = { color: '#B7BCC5', fontFamily: inter, fontSize: 11 };
        const charts = [];

        const adoptionEl = document.getElementById('chart-adoption');
        if (adoptionEl) {
            const c = window.echarts.init(adoptionEl);
            c.setOption({
                grid: { left: 40, right: 20, top: 20, bottom: 28 },
                tooltip: { trigger: 'axis', valueFormatter: v => v + ' %' },
                xAxis: { type: 'category', data: d.health.labels, axisTick: { show: false }, axisLine: { lineStyle: { color: '#E7E9ED' } }, axisLabel },
                yAxis: { type: 'value', splitLine: { lineStyle: { color: '#F0F1F3' } }, axisLabel: { ...axisLabel, formatter: '{value} %' } },
                series: [{ type: 'line', data: d.health.adoptionRate, smooth: true, symbol: 'circle', symbolSize: 6, lineStyle: { color: '#2554C7', width: 2.5 }, itemStyle: { color: '#2554C7' }, areaStyle: { color: 'rgba(37,84,199,0.08)' } }],
            });
            charts.push(c);
        }

        const revenueEl = document.getElementById('chart-revenue');
        if (revenueEl) {
            const c = window.echarts.init(revenueEl);
            c.setOption({
                grid: { left: 44, right: 20, top: 36, bottom: 28 },
                legend: { data: ['Paiements', 'Abonnements'], right: 0, top: 0, icon: 'roundRect', textStyle: { fontFamily: inter, fontSize: 12, color: '#3A4150' } },
                tooltip: { trigger: 'axis', valueFormatter: v => v + ' M' },
                xAxis: { type: 'category', data: d.health.labels, axisTick: { show: false }, axisLine: { lineStyle: { color: '#E7E9ED' } }, axisLabel },
                yAxis: { type: 'value', splitLine: { lineStyle: { color: '#F0F1F3' } }, axisLabel: { ...axisLabel, formatter: '{value} M' } },
                series: [
                    { name: 'Paiements', type: 'line', data: d.health.revenue, smooth: true, symbol: 'none', lineStyle: { color: '#2554C7', width: 2.5 }, itemStyle: { color: '#2554C7' } },
                    { name: 'Abonnements', type: 'line', data: d.health.subRevenue, smooth: true, symbol: 'none', lineStyle: { color: '#D97706', width: 2.5, type: 'dashed' }, itemStyle: { color: '#D97706' } },
                ],
            });
            charts.push(c);
        }

        const donutEl = document.getElementById('chart-donut');
        if (donutEl) {
            const c = window.echarts.init(donutEl);
            c.setOption({
                tooltip: { trigger: 'item', formatter: '{b}<br/>{c} ({d}%)' },
                series: [{
                    type: 'pie', radius: ['58%', '82%'], center: ['50%', '50%'], avoidLabelOverlap: false,
                    label: { show: true, position: 'center', formatter: () => 'Parents\n' + d.repartition.reduce((a, s) => a + s.value, 0).toLocaleString('fr-FR'), fontFamily: inter, fontSize: 13, fontWeight: 600, color: '#14181f', lineHeight: 18 },
                    labelLine: { show: false },
                    itemStyle: { borderColor: '#fff', borderWidth: 2 },
                    data: d.repartition.map(s => ({ name: s.label, value: s.value, itemStyle: { color: s.color } })),
                }],
            });
            charts.push(c);
        }

        const resize = () => charts.forEach(c => c.resize());
        window.addEventListener('resize', resize);
    })();
</script>
@endscript
