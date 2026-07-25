<?php

use App\Domains\Dashboard\Actions\ComputeExecutiveDashboard;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $period = 'school_year';

    #[Url]
    public string $comparison = 'previous';

    #[Computed]
    public function data(): array
    {
        return app(ComputeExecutiveDashboard::class)($this->period, $this->comparison);
    }

    public function periods(): array
    {
        return ComputeExecutiveDashboard::PERIODS;
    }

    public function setPeriod(string $p): void
    {
        $this->period = $p;
        unset($this->data);
    }

    public function setComparison(string $c): void
    {
        $this->comparison = $c;
        unset($this->data);
    }

    public function refreshData(): void
    {
        unset($this->data);
    }

    public function export()
    {
        $rows = collect($this->data['kpis']['strategic'])
            ->merge($this->data['kpis']['secondary'])
            ->map(fn ($k) => [$k['label'], $k['value']]);

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
    $deltaText = fn ($d) => isset($d['pts']) ? number_format($d['pts'], 1, ',', ' ').' pts' : $fr($d['pct']).' %';

    $sparkline = function (?array $series, string $color) {
        if (! $series || count($series) < 2 || max($series) == 0) {
            return '';
        }
        [$w, $h] = [92, 30];
        $n = count($series);
        $min = min($series);
        $range = max(max($series) - $min, 1);
        $pts = [];
        foreach ($series as $i => $v) {
            $pts[] = round($i / ($n - 1) * $w, 1).','.round($h - 3 - ($v - $min) / $range * ($h - 6), 1);
        }
        $line = implode(' ', $pts);

        return '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" fill="none" class="overflow-visible">'
            .'<polygon points="0,'.$h.' '.$line.' '.$w.','.$h.'" fill="'.$color.'" opacity="0.08"/>'
            .'<polyline points="'.$line.'" fill="none" stroke="'.$color.'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    };

    // En-tête de chapitre : une question métier + un sous-titre + une phrase d'analyse.
    $chapter = function (int $n, string $q, string $subtitle, string $lead = '') {
        return '<div class="mt-11 mb-5 first:mt-0">'
            .'<div class="flex items-center gap-3">'
                .'<span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-[10px] bg-ink-900 text-[14px] font-bold text-white">'.$n.'</span>'
                .'<div class="min-w-0"><div class="text-[19px] font-bold tracking-tight text-ink-900">'.$q.'</div>'
                .'<div class="text-[12.5px] text-ink-500">'.$subtitle.'</div></div>'
            .'</div>'
            .($lead ? '<div class="mt-3.5 rounded-xl border border-ink-150 border-l-[3px] border-l-brand-600 bg-white px-4 py-3 text-[13.5px] leading-relaxed text-ink-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">'.$lead.'</div>' : '')
            .'</div>';
    };
    $strong = fn ($t) => '<strong class="font-bold text-ink-900">'.$t.'</strong>';

    $icons = [
        'adoption' => '<circle cx="6" cy="6" r="2.3" stroke="currentColor" stroke-width="1.6"/><circle cx="14" cy="14" r="2.3" stroke="currentColor" stroke-width="1.6"/><line x1="15" y1="5" x2="5" y2="15" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
        'actifs' => '<path d="M4 10.5l3.5 3.5L16 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
        'ca_pay' => '<circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.6"/><path d="M10 6.5v7M12.2 8.2c0-.9-1-1.5-2.2-1.5s-2.2.7-2.2 1.6c0 2.2 4.4 1 4.4 3.2 0 .9-1 1.6-2.2 1.6s-2.2-.6-2.2-1.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>',
        'potentiel' => '<path d="M10 2l2.2 5.3L18 8l-4 3.9.9 5.6L10 15l-4.9 2.5.9-5.6L2 8l5.8-.7z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>',
        'ecoles' => '<polygon points="10,2 17,7 3,7" fill="currentColor"/><rect x="4" y="7.5" width="12" height="9.5" rx="1" stroke="currentColor" stroke-width="1.6" fill="none"/>',
        'eleves' => '<circle cx="10" cy="6" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M4 17c0-3.3 2.7-6 6-6s6 2.7 6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
        'parents' => '<circle cx="7.2" cy="6.5" r="3" stroke="currentColor" stroke-width="1.6"/><circle cx="14" cy="8" r="2.2" stroke="currentColor" stroke-width="1.6" opacity="0.6"/><path d="M2.5 17c0-3 2.1-5 4.7-5s4.7 2 4.7 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
        'inscrits' => '<rect x="4" y="3" width="12" height="14" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M7 8h6M7 11h6M7 14h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
    ];
    $accent = [
        'adoption' => ['var(--color-brand-50)', 'var(--color-brand-600)', '#2554C7'],
        'actifs' => ['var(--color-success-soft)', 'var(--color-success)', '#189B57'],
        'ca_pay' => ['var(--color-warning-soft)', 'var(--color-warning)', '#D97706'],
        'potentiel' => ['#EEF3FE', '#1D3F9C', '#1D3F9C'],
    ];
    $sparkColor = ['adoption' => '#2554C7', 'actifs' => '#189B57', 'ca_pay' => '#D97706', 'inscrits' => '#2554C7'];

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

    // --- Fil narratif calculé sur les données réelles ---
    $sit = $this->data['situation'];
    $repByLabel = collect($this->data['repartition'])->keyBy('label');
    $leakConnus = (int) ($repByLabel['Connus non inscrits']['value'] ?? 0);
    $leakInscrits = (int) ($repByLabel['Inscrits non payeurs']['value'] ?? 0);
    $top5pot = (int) collect($this->data['opportunities'])->sum('potential');
    $rateSeries = $this->data['health']['adoptionRate'];
    $trendUp = count($rateSeries) >= 2 && (float) end($rateSeries) >= (float) reset($rateSeries);
    $recos = $this->data['recommendations'];
    $topReco = $recos[0]['title'] ?? null;

    $leadWhere = 'Adoption globale à '.$strong(number_format($sit['adoptionRate'], 1, ',', ' ').' %')
        .($trendUp ? ", en progression sur l'année" : '').'. Il reste '.$strong($fr($sit['nonAdopters']))
        .' parents à convertir, soit ≈ '.$strong($money($sit['potentialRevenue'])).' d\'abonnements dormants.';
    $leadWhy = 'Le premier verrou : '.$strong($fr($leakConnus)).' parents connus ne se sont jamais inscrits. '
        .'Vient ensuite '.$strong($fr($leakInscrits)).' inscrits qui n\'ont jamais payé. '
        .'La courbe monte quand ces deux marches se franchissent — la rentrée de septembre est le moment clé.';
    $leadSchools = $strong($fr($sit['urgentSchools'])).' écoles passent sous 25 % d\'adoption sur une base significative. '
        .'Les voici, des plus lourdes (potentiel le plus élevé) aux plus légères.';
    $leadPotential = $strong($money($sit['potentialRevenue'])).' d\'abonnements dormants au total. '
        .'Les 5 écoles ci-dessous en concentrent '.$strong($money($top5pot)).' — à cibler en premier.';
    $leadToday = $topReco
        ? 'Priorité du jour : '.$strong($topReco).'. '.count($recos).' actions recommandées ci-dessous, classées par impact.'
        : count($recos).' actions recommandées ci-dessous.';
@endphp

<div class="mx-auto max-w-[1480px]">

    {{-- ============ Panneau de filtres ============ --}}
    <div class="mb-2 flex flex-wrap items-center gap-2">
        <flux:dropdown>
            <button class="inline-flex items-center gap-2 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold text-ink-800 hover:bg-ink-50">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" class="text-ink-500"><rect x="3" y="4.5" width="14" height="12" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M3 8h14M7 3v3M13 3v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                {{ $this->periods()[$period] ?? 'Période' }}
                <svg width="12" height="12" viewBox="0 0 20 20" fill="none"><path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <flux:menu>
                @foreach ($this->periods() as $key => $label)
                    <flux:menu.item wire:click="setPeriod('{{ $key }}')" icon="{{ $period === $key ? 'check' : '' }}">{{ $label }}</flux:menu.item>
                @endforeach
                <flux:menu.separator />
                <flux:menu.item disabled>Personnalisé — à venir</flux:menu.item>
            </flux:menu>
        </flux:dropdown>

        @php
            $disabledFilters = [
                ['École', 'La vue exécutive est globale ; le détail par école est dans le module Écoles'],
                ['Région', 'Géographie des écoles non encore renseignée'],
                ['Campagne', 'Module Campagnes à venir'],
            ];
        @endphp
        @foreach ($disabledFilters as [$flabel, $ftip])
            <button type="button" disabled title="{{ $ftip }}"
                    class="inline-flex cursor-not-allowed items-center gap-2 rounded-lg border border-dashed border-ink-200 bg-ink-50 px-3 py-2 text-[13px] font-medium text-ink-400">
                {{ $flabel }} : Toutes
                <span class="rounded bg-ink-100 px-1 text-[9.5px] font-bold uppercase tracking-wide text-ink-400">à venir</span>
            </button>
        @endforeach

        <div class="ml-auto flex items-center gap-2">
            <div class="hidden items-center gap-1.5 md:flex">
                <span class="text-[12px] font-medium text-ink-500">Comparer :</span>
                @foreach (['previous' => 'période préc.', 'year' => 'année préc.'] as $ckey => $clabel)
                    <button wire:click="setComparison('{{ $ckey }}')"
                            class="rounded-md px-2.5 py-1.5 text-[12px] font-semibold transition-colors {{ $comparison === $ckey ? 'bg-brand-50 text-brand-700' : 'text-ink-600 hover:bg-ink-100' }}">{{ $clabel }}</button>
                @endforeach
            </div>
            <button wire:click="export" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 3v9m0 0l-3.2-3.2M10 12l3.2-3.2M4 15.5h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Exporter
            </button>
            <button wire:click="refreshData" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" wire:loading.class="animate-spin" wire:target="refreshData"><path d="M16 6a7 7 0 10.9 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M16.5 3v3.2h-3.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Actualiser
            </button>
        </div>
    </div>

    {{-- ══════════ 1. OÙ EN SOMMES-NOUS ? ══════════ --}}
    {!! $chapter(1, 'Où en sommes-nous ?', "La photo du jour de l'adoption EcolePay", $leadWhere) !!}

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->data['kpis']['strategic'] as $k)
            @php [$bg, $fg, $line] = $accent[$k['key']]; @endphp
            <div class="group relative overflow-hidden rounded-[16px] border border-ink-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition-all hover:-translate-y-0.5 hover:shadow-[0_10px_28px_rgba(15,23,42,0.08)]">
                <span class="absolute inset-x-0 top-0 h-[3px]" style="background: {{ $line }}"></span>
                <div class="flex items-start justify-between">
                    <div class="flex h-10 w-10 items-center justify-center rounded-[11px]" style="background: {{ $bg }}; color: {{ $fg }}">
                        <svg width="19" height="19" viewBox="0 0 20 20" fill="none">{!! $icons[$k['key']] !!}</svg>
                    </div>
                    @if ($k['delta'])
                        @php $up = $k['delta']['dir'] === 'up'; @endphp
                        <span class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[11.5px] font-semibold"
                              style="background: {{ $up ? 'var(--color-success-soft)' : 'var(--color-danger-soft)' }}; color: {{ $up ? 'var(--color-success)' : 'var(--color-danger)' }}">
                            <svg width="11" height="11" viewBox="0 0 20 20" fill="none" style="{{ $up ? '' : 'transform:rotate(180deg)' }}"><path d="M10 5v10M6 9l4-4 4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            {{ $deltaText($k['delta']) }}
                        </span>
                    @endif
                </div>
                <div class="mt-4 flex items-end justify-between gap-2">
                    <div class="min-w-0">
                        <div class="truncate text-[27px] font-extrabold tracking-tight text-ink-900">{{ $fmt($k) }}</div>
                        <div class="mt-0.5 text-[13px] font-semibold text-ink-800">{{ $k['label'] }}</div>
                    </div>
                    @if ($spark = $sparkline($k['spark'] ?? null, $sparkColor[$k['key']] ?? '#2554C7'))
                        <div class="flex-shrink-0">{!! $spark !!}</div>
                    @endif
                </div>
                <div class="mt-1 truncate text-[11.5px] text-ink-500">{{ $k['sub'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- Carte hero « Situation actuelle » --}}
    <div class="mt-4 overflow-hidden rounded-[20px] text-white shadow-[0_12px_40px_rgba(23,60,130,0.28)]"
         style="background: radial-gradient(120% 140% at 0% 0%, #2E5BD0 0%, #173C82 55%, #102C61 100%)">
        <div class="flex flex-wrap items-stretch">
            <div class="flex flex-[1.4_1_320px] flex-col justify-center gap-1 border-white/10 px-8 py-7 md:border-r">
                <div class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.09em] text-white/60">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7.5" stroke="#fff" stroke-width="1.4"/><circle cx="10" cy="10" r="2.4" fill="#fff"/></svg>
                    Situation actuelle
                </div>
                <div class="mt-1 flex items-end gap-3">
                    <span class="text-[46px] font-extrabold leading-none tracking-tight">{{ number_format($sit['adoptionRate'], 1, ',', ' ') }} %</span>
                    @if ($sit['deltaPts'] > 0)
                        <span class="mb-1.5 inline-flex items-center gap-0.5 rounded-full bg-white/15 px-2 py-0.5 text-[12.5px] font-bold text-white">
                            <svg width="11" height="11" viewBox="0 0 20 20" fill="none"><path d="M10 5v10M6 9l4-4 4 4" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            {{ number_format($sit['deltaPts'], 1, ',', ' ') }} pts · 30 j
                        </span>
                    @endif
                </div>
                <div class="text-[13px] text-white/70">Adoption globale — 1<sup>er</sup> paiement via l'app</div>
            </div>

            <div class="flex flex-[1_1_240px] flex-col justify-center gap-1 border-white/10 px-8 py-7 md:border-r">
                <div class="text-[13px] font-semibold text-white/70">Potentiel restant</div>
                <div class="text-[30px] font-extrabold leading-tight">{{ $fr($sit['nonAdopters']) }}</div>
                <div class="text-[13px] text-white/70">parents à convertir · ≈ <span class="font-semibold text-white">{{ $money($sit['potentialRevenue']) }}</span></div>
            </div>

            <div class="flex flex-[1_1_240px] flex-col justify-center gap-2 px-8 py-7">
                <div class="text-[13px] font-semibold text-white/70">Intervention immédiate</div>
                <div class="flex items-baseline gap-2">
                    <span class="text-[30px] font-extrabold leading-none">{{ $fr($sit['urgentSchools']) }}</span>
                    <span class="text-[13px] text-white/70">écoles prioritaires</span>
                </div>
                <a href="{{ route('schools.index', ['health' => 'prioritaire']) }}" wire:navigate
                   class="mt-0.5 inline-flex w-max items-center gap-1 rounded-lg bg-white px-3 py-2 text-[12.5px] font-bold text-[#173C82] transition-transform hover:scale-[1.02]">
                    Voir les écoles
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M7 4l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
            </div>
        </div>
    </div>

    {{-- Volumétrie de contexte --}}
    <div class="mt-4 grid grid-cols-2 gap-3 xl:grid-cols-4">
        @foreach ($this->data['kpis']['secondary'] as $k)
            <div class="flex items-center gap-3.5 rounded-[13px] border border-ink-200 bg-white px-4 py-3.5 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-[10px] bg-ink-100 text-ink-700">
                    <svg width="17" height="17" viewBox="0 0 20 20" fill="none">{!! $icons[$k['key']] !!}</svg>
                </div>
                <div class="min-w-0">
                    <div class="flex items-center gap-1.5">
                        <span class="text-[19px] font-bold tracking-tight text-ink-900">{{ $fmt($k) }}</span>
                        @if ($k['delta'])
                            @php $up = $k['delta']['dir'] === 'up'; @endphp
                            <span class="text-[11px] font-semibold" style="color: {{ $up ? 'var(--color-success)' : 'var(--color-danger)' }}">{{ $up ? '+' : '−' }}{{ $deltaText($k['delta']) }}</span>
                        @endif
                    </div>
                    <div class="truncate text-[11.5px] font-medium text-ink-500">{{ $k['label'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ══════════ 2. POURQUOI ? ══════════ --}}
    {!! $chapter(2, 'Pourquoi ?', 'Ce qui freine la conversion — et ce qui fait bouger la courbe', $leadWhy) !!}

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-1 text-[15px] font-semibold text-ink-900">L'entonnoir d'adoption</div>
            <div wire:ignore><div id="chart-donut" class="h-[220px] w-full"></div></div>
        </div>
        <div class="lg:col-span-2 rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
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

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-5">
        <div class="lg:col-span-3 rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="text-[15px] font-semibold text-ink-900">Évolution du taux d'adoption</div>
            <div class="mb-3 text-[12px] text-ink-500">Adoptants cumulés / parents connus · repères métier annotés</div>
            <div wire:ignore><div id="chart-adoption" class="h-[280px] w-full"></div></div>
        </div>
        <div class="lg:col-span-2 rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="text-[15px] font-semibold text-ink-900">Revenus</div>
            <div class="mb-3 text-[12px] text-ink-500">Paiements (barres) · abonnements estimés (ligne) — M FCFA</div>
            <div wire:ignore><div id="chart-revenue" class="h-[280px] w-full"></div></div>
        </div>
    </div>

    {{-- ══════════ 3. QUELLES ÉCOLES NÉCESSITENT UNE ACTION ? ══════════ --}}
    {!! $chapter(3, 'Quelles écoles nécessitent une action ?', 'Les établissements à traiter en priorité', $leadSchools) !!}

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <div class="overflow-hidden rounded-[16px] border border-danger/25 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="flex items-center gap-2 border-b border-ink-150 px-6 py-4">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-danger-soft text-danger">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M10 6.5v4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="10" cy="14" r="1" fill="currentColor"/><path d="M8.6 3.5L2.5 15a1.5 1.5 0 001.3 2.2h12.4A1.5 1.5 0 0017.5 15L11.4 3.5a1.6 1.6 0 00-2.8 0z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                </span>
                <span class="text-[15px] font-semibold text-ink-900">Écoles nécessitant une action</span>
            </div>
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
                    @forelse ($this->data['actionSchools'] as $sc)
                        @php [$plabel, $pfg, $pbg] = $prioBadge[$sc['priority']]; @endphp
                        <tr class="border-b border-ink-150 last:border-0 hover:bg-ink-50">
                            <td class="px-5 py-3 text-[13px] font-semibold text-ink-900">{{ $sc['name'] }}</td>
                            <td class="px-3 py-3 text-right font-mono text-[13px] font-semibold" style="color: {{ $sc['rate'] < 15 ? '#B91C1C' : '#B45F04' }}">{{ number_format($sc['rate'], 1, ',', ' ') }} %</td>
                            <td class="px-3 py-3"><span class="inline-block rounded-full px-2 py-0.5 text-[11.5px] font-semibold" style="background: {{ $pbg }}; color: {{ $pfg }}">{{ $plabel }}</span></td>
                            <td class="px-5 py-3 text-right font-mono text-[12.5px] text-ink-700">{{ $sc['potential'] > 0 ? $money($sc['potential']) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-10 text-center text-[13px] text-ink-500">Aucune école en zone critique.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="overflow-hidden rounded-[16px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
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
                    @foreach ($this->data['topSchools'] as $i => $sc)
                        <tr class="border-b border-ink-150 last:border-0 hover:bg-ink-50">
                            <td class="px-5 py-3 text-[13px] font-bold text-ink-400">{{ $i + 1 }}</td>
                            <td class="px-2 py-3 text-[13px] font-semibold text-ink-900">{{ $sc['name'] }}</td>
                            <td class="px-3 py-3 text-right font-mono text-[13px] font-semibold text-ink-900">{{ number_format($sc['rate'], 1, ',', ' ') }} %</td>
                            <td class="px-3 py-3 text-right font-mono text-[12.5px] {{ $sc['recent'] > 0 ? 'text-success' : 'text-ink-400' }}">{{ $sc['recent'] > 0 ? '+'.$fr($sc['recent']) : '—' }}</td>
                            <td class="px-5 py-3 text-right font-mono text-[12.5px] text-ink-700">{{ $sc['potential'] > 0 ? $money($sc['potential']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ══════════ 4. QUEL EST LE POTENTIEL DE REVENUS RESTANT ? ══════════ --}}
    {!! $chapter(4, 'Quel est le potentiel de revenus restant ?', "Le revenu d'abonnement encore dormant", $leadPotential) !!}

    <div class="rounded-[18px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
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
                    <a href="{{ route('schools.index') }}" wire:navigate class="inline-flex flex-shrink-0 items-center gap-1 rounded-lg border border-ink-200 px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
                        Voir les détails
                        <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M7 4l6 6-6 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ══════════ 5. QUE FAUT-IL FAIRE AUJOURD'HUI ? ══════════ --}}
    {!! $chapter(5, "Que faut-il faire aujourd'hui ?", 'Les actions prioritaires et les signaux à surveiller', $leadToday) !!}

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Recommandations (les actions) --}}
        <div class="rounded-[16px] border border-brand-200 bg-brand-50/40 p-5 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-3.5 flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-600 text-white">
                    <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M10 2.5a5.5 5.5 0 00-3 10.1V15h6v-2.4a5.5 5.5 0 00-3-10.1z" stroke="currentColor" stroke-width="1.6"/><path d="M8 17h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                </span>
                <span class="text-[14px] font-bold text-ink-900">Recommandations</span>
                <span class="rounded bg-white px-1.5 py-0.5 text-[9.5px] font-bold uppercase tracking-wide text-ink-500">règles métier · v1</span>
            </div>
            <div class="flex flex-col gap-2.5">
                @foreach ($recos as $r)
                    @php [$rlabel, $rfg, $rbg] = $prioBadge[$r['priority']]; @endphp
                    <div class="rounded-[13px] border border-ink-200 bg-white p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="text-[13.5px] font-semibold leading-snug text-ink-900">{{ $r['title'] }}</div>
                            <span class="flex-shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background: {{ $rbg }}; color: {{ $rfg }}">{{ $rlabel }}</span>
                        </div>
                        <div class="mt-1.5 text-[12.5px] leading-snug text-ink-600">{{ $r['why'] }}</div>
                        <button class="mt-2.5 inline-flex items-center gap-1 text-[12.5px] font-semibold text-brand-600 hover:underline">
                            Voir l'analyse
                            <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M7 4l6 6-6 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Alertes (les signaux) --}}
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-3.5 flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-ink-100 text-ink-700">
                    <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M5 8a5 5 0 0110 0c0 4 1.5 5 1.5 5h-13S5 12 5 8z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M8 16a2 2 0 004 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                </span>
                <span class="text-[14px] font-bold text-ink-900">Alertes intelligentes</span>
            </div>
            <div class="flex flex-col gap-1">
                @foreach ($this->data['alerts'] as $a)
                    @php [$afg, $abg] = $alertStyle[$a['level']]; @endphp
                    <div class="relative flex gap-3.5 pb-5 last:pb-0">
                        @if (! $loop->last)<span class="absolute left-[15px] top-8 bottom-0 w-px bg-ink-150"></span>@endif
                        <span class="relative z-10 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full" style="background: {{ $abg }}; color: {{ $afg }}">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M10 6.5v4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="10" cy="13.8" r="1" fill="currentColor"/><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.4"/></svg>
                        </span>
                        <div class="min-w-0 pt-0.5">
                            <div class="flex flex-wrap items-center gap-2">
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
</div>

@script
<script>
    (() => {
        if (! window.echarts) return;
        const d = @js($this->data);
        const g = window.echarts.graphic;
        const inter = 'Inter';
        const axisLabel = { color: '#B7BCC5', fontFamily: inter, fontSize: 11 };
        const charts = [];

        const adoptionEl = document.getElementById('chart-adoption');
        if (adoptionEl) {
            const c = window.echarts.init(adoptionEl);
            const markLines = (d.health.events || []).map(e => ({
                xAxis: e.index,
                label: { formatter: e.label, color: '#173C82', fontFamily: inter, fontSize: 10.5, fontWeight: 600, position: 'insideEndTop', backgroundColor: '#EEF3FE', padding: [3, 6], borderRadius: 4 },
                lineStyle: { color: '#2554C7', type: 'dashed', width: 1.2, opacity: 0.5 },
            }));
            c.setOption({
                grid: { left: 42, right: 22, top: 22, bottom: 28 },
                tooltip: { trigger: 'axis', valueFormatter: v => v + ' %' },
                xAxis: { type: 'category', boundaryGap: false, data: d.health.labels, axisTick: { show: false }, axisLine: { lineStyle: { color: '#E7E9ED' } }, axisLabel },
                yAxis: { type: 'value', splitLine: { lineStyle: { color: '#F0F1F3' } }, axisLabel: { ...axisLabel, formatter: '{value} %' } },
                series: [{
                    type: 'line', data: d.health.adoptionRate, smooth: 0.4, showSymbol: false,
                    symbol: 'circle', symbolSize: 8,
                    emphasis: { focus: 'series', itemStyle: { borderColor: '#2554C7', borderWidth: 3, color: '#fff' } },
                    lineStyle: { color: '#2554C7', width: 3.5, cap: 'round' },
                    itemStyle: { color: '#2554C7' },
                    areaStyle: { color: new g.LinearGradient(0, 0, 0, 1, [{ offset: 0, color: 'rgba(37,84,199,0.28)' }, { offset: 1, color: 'rgba(37,84,199,0.01)' }]) },
                    markLine: markLines.length ? { symbol: 'none', silent: true, data: markLines } : undefined,
                    markPoint: { symbol: 'pin', symbolSize: 42, itemStyle: { color: '#2554C7' }, label: { color: '#fff', fontSize: 10, fontWeight: 700 }, data: [{ type: 'max', name: 'Max' }] },
                    animationDuration: 900,
                }],
            });
            charts.push(c);
        }

        const revenueEl = document.getElementById('chart-revenue');
        if (revenueEl) {
            const c = window.echarts.init(revenueEl);
            c.setOption({
                grid: { left: 40, right: 40, top: 34, bottom: 28 },
                legend: { data: ['Paiements', 'Abonnements'], right: 0, top: 0, icon: 'roundRect', textStyle: { fontFamily: inter, fontSize: 11.5, color: '#3A4150' } },
                tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' }, valueFormatter: v => v + ' M' },
                xAxis: { type: 'category', data: d.health.labels, axisTick: { show: false }, axisLine: { lineStyle: { color: '#E7E9ED' } }, axisLabel },
                yAxis: [
                    { type: 'value', splitLine: { lineStyle: { color: '#F0F1F3' } }, axisLabel: { ...axisLabel, formatter: '{value}' } },
                    { type: 'value', splitLine: { show: false }, axisLabel: { ...axisLabel, formatter: '{value}' } },
                ],
                series: [
                    { name: 'Paiements', type: 'bar', data: d.health.revenue, barMaxWidth: 22, itemStyle: { borderRadius: [5, 5, 0, 0], color: new g.LinearGradient(0, 0, 0, 1, [{ offset: 0, color: '#2554C7' }, { offset: 1, color: '#7DA0EC' }]) }, animationDuration: 900 },
                    { name: 'Abonnements', type: 'line', yAxisIndex: 1, data: d.health.subRevenue, smooth: true, symbol: 'circle', symbolSize: 6, lineStyle: { color: '#D97706', width: 3, cap: 'round' }, itemStyle: { color: '#D97706' }, animationDuration: 1100 },
                ],
            });
            charts.push(c);
        }

        const donutEl = document.getElementById('chart-donut');
        if (donutEl) {
            const c = window.echarts.init(donutEl);
            const total = d.repartition.reduce((a, s) => a + s.value, 0);
            c.setOption({
                tooltip: { trigger: 'item', formatter: '{b}<br/>{c} ({d}%)' },
                series: [{
                    type: 'pie', radius: ['58%', '82%'], center: ['50%', '50%'], avoidLabelOverlap: false,
                    label: { show: true, position: 'center', formatter: () => 'Parents\n' + total.toLocaleString('fr-FR'), fontFamily: inter, fontSize: 13, fontWeight: 600, color: '#14181f', lineHeight: 18 },
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
