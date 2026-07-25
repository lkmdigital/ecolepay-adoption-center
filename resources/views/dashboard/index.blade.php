<?php

use App\Domains\Dashboard\Actions\ComputeDashboardCharts;
use App\Domains\Dashboard\Actions\ComputeDashboardKpis;
use App\Domains\Dashboard\Data\DashboardKpis;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function kpis(): DashboardKpis
    {
        return app(ComputeDashboardKpis::class)();
    }

    #[Computed]
    public function charts(): array
    {
        return app(ComputeDashboardCharts::class)();
    }
};

?>

@php
    $fr = fn (int|float $n) => number_format($n, 0, ',', ' ');
    $money = function (int $n) use ($fr) {
        return $n >= 1_000_000 ? $fr(round($n / 1_000_000, 1)).' M F' : $fr($n).' F';
    };
@endphp

<div class="mx-auto max-w-[1480px]">
    @php $k = $this->kpis; @endphp


        {{-- Indicateurs stratégiques --}}
        <div class="mb-4 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-600">Indicateurs stratégiques</div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {{-- Taux d'adoption --}}
            <x-eac.kpi-card
                label="Taux d'adoption"
                :value="number_format($k->adoptionRate(), 1, ',', ' ').' %'"
                icon-bg="var(--color-brand-50)" icon-color="var(--color-brand-600)"
                :sub="$fr($k->adoptants).' adoptants / '.$fr($k->connus).' connus'"
            >
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><circle cx="6" cy="6" r="2.3" stroke="currentColor" stroke-width="1.6"/><circle cx="14" cy="14" r="2.3" stroke="currentColor" stroke-width="1.6"/><line x1="15" y1="5" x2="5" y2="15" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            </x-eac.kpi-card>

            {{-- Parents adoptants --}}
            <x-eac.kpi-card
                label="Parents adoptants"
                :value="$fr($k->adoptants)"
                icon-bg="var(--color-success-soft)" icon-color="var(--color-success)"
                sub="Ont effectué un paiement via l'app"
            >
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M4 10.5l3.5 3.5L16 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </x-eac.kpi-card>

            {{-- Parents inscrits --}}
            <x-eac.kpi-card
                label="Parents inscrits"
                :value="$fr($k->inscrits)"
                icon-bg="var(--color-ink-100)" icon-color="var(--color-ink-800)"
                :sub="'Taux d\'activation : '.number_format($k->activationRate(), 1, ',', ' ').' %'"
            >
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><circle cx="7.2" cy="6.5" r="3" stroke="currentColor" stroke-width="1.6"/><circle cx="14" cy="8" r="2.2" stroke="currentColor" stroke-width="1.6" opacity="0.6"/><path d="M2.5 17c0-3 2.1-5 4.7-5s4.7 2 4.7 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            </x-eac.kpi-card>

            {{-- Chiffre d'affaires --}}
            <x-eac.kpi-card
                label="Volume de paiements"
                :value="$money($k->revenue)"
                icon-bg="var(--color-warning-soft)" icon-color="var(--color-warning)"
                :sub="$fr($k->activeSchools).' écoles actives'"
            >
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.6"/><path d="M10 6.5v7M12.2 8.2c0-.9-1-1.5-2.2-1.5s-2.2.7-2.2 1.6c0 2.2 4.4 1 4.4 3.2 0 .9-1 1.6-2.2 1.6s-2.2-.6-2.2-1.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
            </x-eac.kpi-card>
        </div>

        {{-- L'entonnoir d'adoption --}}
        <div class="mt-8 mb-4 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-600">L'entonnoir d'adoption</div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            {{-- Les 6 statuts --}}
            <div class="rounded-[14px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)] lg:col-span-2">
                <div class="mb-5 text-[15px] font-semibold text-ink-900">Répartition des parents</div>
                @php
                    $stages = [
                        ['Connus', $k->connus - $k->inscrits, '#94A3B8'],
                        ['Inscrits (sans paiement)', $k->inscrits - $k->adoptants, '#38BDF8'],
                        ['Adoptants', $k->adoptants, '#22C55E'],
                        ['Engagés', $k->engages, '#15803D'],
                        ['À risque', $k->aRisque, '#F59E0B'],
                        ['Perdus', $k->perdus, '#EF4444'],
                    ];
                    $max = max(1, collect($stages)->max(fn ($s) => $s[1]));
                @endphp
                <div class="flex flex-col gap-3.5">
                    @foreach ($stages as [$label, $count, $color])
                        <div class="flex items-center gap-3">
                            <div class="w-44 flex-shrink-0 text-[13px] font-medium text-ink-800">{{ $label }}</div>
                            <div class="h-6 flex-1 overflow-hidden rounded-md bg-ink-100">
                                <div class="h-full rounded-md" style="width: {{ max(2, round($count / $max * 100)) }}%; background: {{ $color }}"></div>
                            </div>
                            <div class="w-16 flex-shrink-0 text-right font-mono text-[13px] font-medium text-ink-900">{{ $fr($count) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Les 3 taux --}}
            <div class="rounded-[14px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="mb-5 text-[15px] font-semibold text-ink-900">Les 3 taux clés</div>
                <div class="flex flex-col gap-5">
                    <div>
                        <div class="text-[12.5px] font-semibold text-ink-600">Taux d'inscription</div>
                        <div class="mt-1 text-[26px] font-bold tracking-tight text-ink-900">{{ number_format($k->registrationRate(), 1, ',', ' ') }} %</div>
                        <div class="text-[11.5px] text-ink-500">efficacité de la communication</div>
                    </div>
                    <div class="border-t border-ink-150 pt-4">
                        <div class="text-[12.5px] font-semibold text-brand-600">Taux d'adoption ★</div>
                        <div class="mt-1 text-[26px] font-bold tracking-tight text-ink-900">{{ number_format($k->adoptionRate(), 1, ',', ' ') }} %</div>
                        <div class="text-[11.5px] text-ink-500">le chiffre suivi par la Direction</div>
                    </div>
                    <div class="border-t border-ink-150 pt-4">
                        <div class="text-[12.5px] font-semibold text-ink-600">Taux d'activation</div>
                        <div class="mt-1 text-[26px] font-bold tracking-tight text-ink-900">{{ number_format($k->activationRate(), 1, ',', ' ') }} %</div>
                        <div class="text-[11.5px] text-ink-500">inscrits transformés en payeurs</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Situation actuelle --}}
        <div class="mt-6 flex flex-wrap items-center gap-x-9 gap-y-5 overflow-hidden rounded-2xl px-8 py-7 text-white"
             style="background: linear-gradient(135deg, #173C82 0%, #2554C7 60%, #3E6FE0 100%)">
            <div class="flex min-w-[200px] flex-[1_1_240px] items-center gap-2.5">
                <div class="flex h-[38px] w-[38px] flex-shrink-0 items-center justify-center rounded-[10px]" style="background: rgba(255,255,255,0.15)">
                    <svg width="19" height="19" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7.5" stroke="#fff" stroke-width="1.5"/><circle cx="10" cy="10" r="2.6" fill="#fff"/></svg>
                </div>
                <div>
                    <div class="text-[11px] font-bold uppercase tracking-[0.07em]" style="color: rgba(255,255,255,0.65)">Situation actuelle</div>
                    <div class="mt-0.5 text-[15px] font-bold">Où en sommes-nous aujourd'hui ?</div>
                </div>
            </div>
            <div class="flex flex-[2_1_460px] flex-wrap gap-9">
                <div>
                    <div class="text-[32px] font-extrabold tracking-tight">{{ number_format($k->adoptionRate(), 1, ',', ' ') }} %</div>
                    <div class="mt-0.5 text-[12.5px]" style="color: rgba(255,255,255,0.7)">Adoption globale (1<sup>er</sup> paiement)</div>
                </div>
                <div class="w-px self-stretch" style="background: rgba(255,255,255,0.2)"></div>
                <div>
                    <div class="text-[32px] font-extrabold tracking-tight">{{ $fr($k->nonAdopters()) }}</div>
                    <div class="mt-0.5 text-[12.5px]" style="color: rgba(255,255,255,0.7)">Parents à convertir · ≈ {{ $money($k->potentialRevenue) }} d'abonnements</div>
                </div>
                <div class="w-px self-stretch" style="background: rgba(255,255,255,0.2)"></div>
                <div>
                    <div class="text-[32px] font-extrabold tracking-tight">{{ $fr($k->urgentSchools) }}</div>
                    <div class="mt-0.5 text-[12.5px]" style="color: rgba(255,255,255,0.7)">écoles à intervention prioritaire</div>
                </div>
            </div>
        </div>

        {{-- Tendances --}}
        <div class="mt-8 mb-4 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-600">Tendances</div>
        <div class="rounded-[14px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-1 text-[15px] font-semibold text-ink-900">Adoption & volume de paiements</div>
            <div class="mb-4 text-[12px] text-ink-500">Nouveaux adoptants (1<sup>er</sup> paiement) et volume payé via l'app, par mois</div>
            <div wire:ignore>
                <div id="eac-trend-chart" class="h-[300px] w-full"></div>
            </div>
        </div>

        {{-- Écoles --}}
        <div class="mt-8 mb-4 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-600">Écoles</div>
        <div class="overflow-hidden rounded-[14px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="border-b border-ink-150 px-6 py-4 text-[15px] font-semibold text-ink-900">Top écoles par adoptants</div>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-ink-50">
                        <th class="px-6 py-3 text-left text-[11.5px] font-bold uppercase tracking-wider text-ink-500">École</th>
                        <th class="px-6 py-3 text-right text-[11.5px] font-bold uppercase tracking-wider text-ink-500">Adoptants</th>
                        <th class="px-6 py-3 text-right text-[11.5px] font-bold uppercase tracking-wider text-ink-500">Connus</th>
                        <th class="px-6 py-3 text-right text-[11.5px] font-bold uppercase tracking-wider text-ink-500">Taux</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->charts['topSchools'] as $school)
                        @php
                            $rate = $school['rate'];
                            [$badgeBg, $badgeFg] = $rate >= 50 ? ['var(--color-success-soft)', '#0F7A44']
                                : ($rate >= 25 ? ['var(--color-warning-soft)', '#B45F04'] : ['var(--color-danger-soft)', '#B91C1C']);
                        @endphp
                        <tr class="border-b border-ink-150 last:border-0 hover:bg-ink-50">
                            <td class="px-6 py-3.5 text-[13.5px] font-semibold text-ink-900">{{ $school['name'] }}</td>
                            <td class="px-6 py-3.5 text-right font-mono text-[13.5px] text-ink-900">{{ $fr($school['adopters']) }}</td>
                            <td class="px-6 py-3.5 text-right font-mono text-[13.5px] text-ink-700">{{ $fr($school['known']) }}</td>
                            <td class="px-6 py-3.5 text-right">
                                <span class="inline-block rounded-full px-2.5 py-1 text-[12px] font-semibold" style="background: {{ $badgeBg }}; color: {{ $badgeFg }}">{{ number_format($rate, 1, ',', ' ') }} %</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

@script
<script>
    (() => {
        const data = @js($this->charts['trend']);
        const el = document.getElementById('eac-trend-chart');
        if (! el || ! window.echarts) return;

        const chart = window.echarts.init(el);
        chart.setOption({
            grid: { left: 48, right: 52, top: 40, bottom: 30 },
            legend: { data: ['Nouveaux adoptants', 'Volume (M F)'], right: 0, top: 0, icon: 'roundRect', textStyle: { fontFamily: 'Inter', fontSize: 12, color: '#3A4150' } },
            tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
            xAxis: { type: 'category', data: data.labels, axisLine: { lineStyle: { color: '#E7E9ED' } }, axisLabel: { color: '#B7BCC5', fontFamily: 'Inter', fontSize: 11 }, axisTick: { show: false } },
            yAxis: [
                { type: 'value', splitLine: { lineStyle: { color: '#F0F1F3' } }, axisLabel: { color: '#B7BCC5', fontFamily: 'Inter', fontSize: 11 } },
                { type: 'value', splitLine: { show: false }, axisLabel: { color: '#B7BCC5', fontFamily: 'Inter', fontSize: 11, formatter: '{value} M' } },
            ],
            series: [
                { name: 'Nouveaux adoptants', type: 'bar', data: data.adopters, itemStyle: { color: '#189B57', borderRadius: [4, 4, 0, 0] }, barMaxWidth: 26 },
                { name: 'Volume (M F)', type: 'line', yAxisIndex: 1, data: data.volume, smooth: true, symbol: 'circle', symbolSize: 6, lineStyle: { color: '#2554C7', width: 2.5 }, itemStyle: { color: '#2554C7' } },
            ],
        });

        const resize = () => chart.resize();
        window.addEventListener('resize', resize);
    })();
</script>
@endscript
