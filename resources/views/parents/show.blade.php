<?php

use App\Domains\Parents\Actions\ComputeParentProfile;
use App\Domains\Campaigns\Enums\CampaignChannel;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $parentId;

    public function mount(int $parent): void
    {
        $this->parentId = $parent;
        abort_if($this->profile() === null, 404);
    }

    #[Computed]
    public function profile(): ?array
    {
        return app(ComputeParentProfile::class)($this->parentId);
    }
};

?>

@php
    $p = $this->profile;
    $pt = $p['parent'];
    $lc = $p['lifecycle'];
    $k = $p['kpis'];
    $eng = $p['engagement'];
    $fr = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $money = fn ($n) => $n >= 1_000_000 ? number_format($n / 1_000_000, 1, ',', ' ').' M F' : $fr($n).' F';
    $monogram = \Illuminate\Support\Str::of($pt['name'])->explode(' ')->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
    $dateFr = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->locale('fr')->isoFormat('D MMM YYYY') : '—';
    $dateTimeFr = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->locale('fr')->isoFormat('D MMM YYYY · HH:mm') : '—';
    $prio = ['critique' => ['#B91C1C', '#FDECEC'], 'elevee' => ['#B45F04', '#FEF3E2'], 'moyenne' => ['#1D3F9C', '#EEF3FE'], 'faible' => ['#5B6472', '#F2F3F5']];
    $prioLabel = ['critique' => 'Critique', 'elevee' => 'Élevée', 'moyenne' => 'Moyenne', 'faible' => 'Faible'];

    // Chronologie construite depuis les vraies dates.
    $timeline = collect([
        ['label' => 'Numéro importé', 'date' => $pt['firstKnownAt'], 'color' => '#6B7280'],
        ['label' => 'Compte créé', 'date' => $pt['accountCreatedAt'], 'color' => '#1D4ED8'],
        ['label' => 'Premier paiement (adoption)', 'date' => $k['firstPayment'], 'color' => '#0F7A44'],
        ['label' => 'Dernier paiement', 'date' => $k['lastPayment'], 'color' => '#0B6A3B'],
        ['label' => 'Dernière activité', 'date' => $k['lastActivity'], 'color' => '#2554C7'],
    ])->filter(fn ($e) => $e['date'])->sortBy('date')->values();
@endphp

<div class="mx-auto max-w-[1480px]">

    {{-- Fil d'Ariane --}}
    <nav class="mb-4 flex items-center gap-1.5 text-[12.5px] text-ink-500">
        <a href="{{ route('dashboard.index') }}" wire:navigate class="hover:text-ink-800">Dashboard</a><span>/</span>
        <a href="{{ route('parents.index') }}" wire:navigate class="hover:text-ink-800">Parents</a><span>/</span>
        <span class="font-semibold text-ink-800">{{ $pt['name'] }}</span>
    </nav>

    {{-- En-tête --}}
    <div class="mb-6 flex flex-wrap items-start gap-4">
        <span class="flex h-16 w-16 flex-shrink-0 items-center justify-center rounded-2xl text-[20px] font-bold" style="background: {{ $lc['bg'] }}; color: {{ $lc['color'] }}">{{ $monogram }}</span>
        <div class="min-w-0 flex-1">
            <h1 class="text-[23px] font-bold tracking-tight text-ink-900">{{ $pt['name'] }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-ink-500">
                <span class="font-mono">{{ $pt['phone'] ?: '—' }}</span><span>·</span>
                <span>{{ $pt['email'] ?: 'sans email' }}</span><span>·</span>
                <span>{{ $pt['school'] ?: 'école —' }}</span>
            </div>
            <div class="mt-2.5 flex flex-wrap items-center gap-2">
                <span class="rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold" style="background: {{ $lc['bg'] }}; color: {{ $lc['color'] }}">{{ $lc['label'] }}{{ $lc['star'] ? ' ⭐' : '' }}</span>
                <span class="rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold" style="background: {{ $eng['bg'] }}; color: {{ $eng['color'] }}">Engagement {{ $eng['score'] }}/100</span>
                <span class="text-[11.5px] text-ink-400">Synchro {{ $dateFr($pt['syncedAt']) }}</span>
            </div>
        </div>
    </div>

    {{-- Parcours d'adoption (barre de progression) --}}
    <div class="mb-6 rounded-[18px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
        <div class="mb-5 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Parcours d'adoption</div>
        <div class="flex flex-col gap-0 sm:flex-row sm:items-start">
            @foreach ($p['journey'] as $i => $step)
                <div class="flex flex-1 items-start gap-3 sm:flex-col sm:items-center sm:text-center">
                    <div class="flex items-center sm:w-full sm:flex-col">
                        <span class="relative z-10 flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full {{ $step['reached'] ? 'text-white' : 'border-2 border-dashed border-ink-300 bg-white text-ink-400' }}"
                              style="{{ $step['reached'] ? 'background: '.($step['star'] ? '#0F7A44' : $lc['color']) : '' }}">
                            @if ($step['reached'])
                                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M5 10.5l3.5 3.5L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            @else
                                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                            @endif
                        </span>
                        @if (! $loop->last)<span class="hidden h-0.5 flex-1 sm:block" style="background: {{ $p['journey'][$i + 1]['reached'] ? '#0F7A44' : '#E7E9ED' }}"></span>@endif
                    </div>
                    <div class="pb-4 sm:pb-0 sm:pt-2">
                        <div class="text-[13px] font-semibold text-ink-900">{{ $step['label'] }}{{ $step['star'] ? ' ⭐' : '' }}</div>
                        <div class="text-[11.5px] text-ink-500">{{ $step['reached'] ? $dateFr($step['date']) : $step['sub'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Diagnostic + Score --}}
    <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-[1.6fr_1fr]">
        <div class="rounded-[16px] border border-brand-200 bg-brand-50/40 p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-2 flex items-center gap-2">
                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-600 text-white"><svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 2.5a5.5 5.5 0 00-3 10.1V15h6v-2.4a5.5 5.5 0 00-3-10.1z" stroke="currentColor" stroke-width="1.6"/><path d="M8 17h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span>
                <span class="text-[15px] font-bold text-ink-900">Diagnostic du parcours</span>
                <span class="rounded bg-white px-1.5 py-0.5 text-[9.5px] font-bold uppercase tracking-wide text-ink-500">règles métier · v1</span>
            </div>
            <p class="text-[13.5px] leading-relaxed text-ink-700">{{ $p['diagnostic'] }}</p>
        </div>
        <div class="rounded-[16px] border p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]" style="border-color: {{ $eng['color'] }}30; background: {{ $eng['bg'] }}">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wide" style="color: {{ $eng['color'] }}">Score d'engagement</span>
                <span class="rounded-full bg-white px-2 py-0.5 text-[11px] font-bold" style="color: {{ $eng['color'] }}">{{ $eng['level'] }}</span>
            </div>
            <div class="mt-1"><span class="text-[36px] font-extrabold leading-none" style="color: {{ $eng['color'] }}">{{ $eng['score'] }}</span><span class="text-[15px] font-bold text-ink-400">/100</span></div>
            <div class="mt-3 flex flex-col gap-1.5">
                @foreach ($eng['breakdown'] as $c)
                    <div class="flex items-center gap-2 text-[11.5px]">
                        <span class="w-36 flex-shrink-0 text-ink-700">{{ $c['label'] }}</span>
                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-white/70"><div class="h-full rounded-full" style="width: {{ $c['weight'] > 0 ? round($c['score'] / $c['weight'] * 100) : 0 }}%; background: {{ $eng['color'] }}"></div></div>
                        <span class="w-9 text-right font-mono text-ink-600">{{ $c['score'] }}/{{ $c['weight'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Timeline + Enfants --}}
    <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-4 text-[15px] font-semibold text-ink-900">Chronologie du parcours</div>
            <div class="flex flex-col">
                @foreach ($timeline as $e)
                    <div class="relative flex gap-3.5 pb-4 last:pb-0">
                        @if (! $loop->last)<span class="absolute left-[13px] top-7 bottom-0 w-px bg-ink-150"></span>@endif
                        <span class="relative z-10 mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full" style="background: {{ $e['color'] }}1A; color: {{ $e['color'] }}"><svg width="13" height="13" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="3" fill="currentColor"/></svg></span>
                        <div><div class="text-[13.5px] font-semibold text-ink-900">{{ $e['label'] }}</div><div class="text-[12px] text-ink-500">{{ $dateTimeFr($e['date']) }}</div></div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-4 text-[15px] font-semibold text-ink-900">Enfants ({{ count($p['children']) }})</div>
            @if (count($p['children']))
                <div class="flex flex-col gap-2">
                    @foreach ($p['children'] as $child)
                        <div class="flex items-center justify-between rounded-xl border border-ink-150 px-4 py-2.5 text-[13px]">
                            <span class="font-medium text-ink-900">{{ $child['ref'] ?: 'Élève' }}</span>
                            <span class="text-ink-500">{{ $child['class'] ?: '—' }}@if ($child['school']) · {{ $child['school'] }}@endif</span>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="py-8 text-center text-[13px] text-ink-500">Aucun enfant rattaché.</div>
            @endif
        </div>
    </div>

    {{-- Analyse d'engagement --}}
    <div class="mb-6 rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
        <div class="mb-1 text-[15px] font-semibold text-ink-900">Activité de paiement</div>
        <div class="mb-3 text-[12px] text-ink-500">Nombre de paiements par mois (12 derniers mois)</div>
        <div wire:ignore><div id="parent-activity" class="h-[220px] w-full"></div></div>
    </div>

    {{-- Paiements --}}
    <div class="mb-6 overflow-hidden rounded-[16px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-150 px-6 py-4">
            <span class="text-[15px] font-semibold text-ink-900">Paiements</span>
            <div class="flex gap-5 text-[12.5px]">
                <span class="text-ink-500">Total : <span class="font-semibold text-ink-900">{{ $fr($k['payCount']) }}</span></span>
                <span class="text-ink-500">Montant : <span class="font-semibold text-ink-900">{{ $money($k['total']) }}</span></span>
                <span class="text-ink-500">Dernier : <span class="font-semibold text-ink-900">{{ $dateFr($k['lastPayment']) }}</span></span>
            </div>
        </div>
        @if (count($p['payments']))
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-ink-50 text-[11px] font-bold uppercase tracking-wider text-ink-500">
                        <th class="px-6 py-2.5 text-left">Date</th><th class="px-3 py-2.5 text-left">Type</th><th class="px-3 py-2.5 text-left">Élève</th>
                        <th class="px-3 py-2.5 text-right">Montant</th><th class="px-6 py-2.5 text-left">Statut</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($p['payments'] as $pay)
                        @php $ok = $pay['status'] === 'success'; @endphp
                        <tr class="border-b border-ink-150 last:border-0 hover:bg-ink-50">
                            <td class="px-6 py-2.5 text-[12.5px] text-ink-700">{{ $dateFr($pay['date']) }}</td>
                            <td class="px-3 py-2.5 text-[12.5px] text-ink-700">{{ $pay['label'] }}</td>
                            <td class="px-3 py-2.5 text-[12.5px] text-ink-500">{{ $pay['student'] ?: '—' }}</td>
                            <td class="px-3 py-2.5 text-right font-mono text-[12.5px] font-semibold text-ink-900">{{ $money($pay['amount']) }}</td>
                            <td class="px-6 py-2.5"><span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background: {{ $ok ? '#E9F8EF' : '#FDECEC' }}; color: {{ $ok ? '#0F7A44' : '#B91C1C' }}">{{ $ok ? 'Réussi' : $pay['status'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="py-10 text-center text-[13px] text-ink-500">Aucun paiement enregistré — ce parent n'a pas encore adopté EcolePay.</div>
        @endif
    </div>

    {{-- Campagnes + Recommandations --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-4 text-[15px] font-semibold text-ink-900">Campagnes reçues</div>
            @if (count($p['campaigns']))
                <div class="flex flex-col gap-2.5">
                    @foreach ($p['campaigns'] as $camp)
                        <a href="{{ route('campaigns.show', $camp['id']) }}" wire:navigate class="block rounded-xl border border-ink-150 p-3.5 hover:bg-ink-50">
                            <div class="flex items-center justify-between">
                                <span class="text-[13px] font-semibold text-ink-900">{{ $camp['name'] }}</span>
                                <span class="text-[11.5px] text-ink-500">{{ $dateFr($camp['date']) }}</span>
                            </div>
                            <div class="mt-1.5 flex flex-wrap items-center gap-2 text-[11px]">
                                <span class="rounded bg-ink-100 px-1.5 py-0.5 font-medium text-ink-600">{{ CampaignChannel::tryFrom($camp['channel'])?->label() ?? $camp['channel'] }}</span>
                                <span class="rounded-full px-1.5 py-0.5 font-semibold" style="background: {{ $camp['accountAfter'] ? '#E9F8EF' : '#F2F3F5' }}; color: {{ $camp['accountAfter'] ? '#0F7A44' : '#6B7280' }}">Compte après : {{ $camp['accountAfter'] ? 'oui' : 'non' }}</span>
                                <span class="rounded-full px-1.5 py-0.5 font-semibold" style="background: {{ $camp['paymentAfter'] ? '#E9F8EF' : '#F2F3F5' }}; color: {{ $camp['paymentAfter'] ? '#0F7A44' : '#6B7280' }}">Paiement après : {{ $camp['paymentAfter'] ? 'oui' : 'non' }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="py-8 text-center text-[13px] text-ink-500">Ce parent n'a été associé à aucune campagne importée.</div>
            @endif
        </div>
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-4 text-[15px] font-semibold text-ink-900">Recommandations</div>
            <div class="flex flex-col gap-2.5">
                @foreach ($p['recommendations'] as $r)
                    @php [$rfg, $rbg] = $prio[$r['priority']]; @endphp
                    <div class="rounded-xl border border-ink-150 p-3.5">
                        <div class="flex items-start justify-between gap-3">
                            <span class="text-[13.5px] font-semibold text-ink-900">{{ $r['title'] }}</span>
                            <span class="flex-shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background: {{ $rbg }}; color: {{ $rfg }}">{{ $prioLabel[$r['priority']] }}</span>
                        </div>
                        <div class="mt-1 text-[12.5px] text-ink-600">{{ $r['why'] }}</div>
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
        const a = @js($this->profile['analysis']);
        const el = document.getElementById('parent-activity');
        if (! el) return;
        const g = window.echarts.graphic;
        const c = window.echarts.init(el);
        c.setOption({
            grid: { left: 32, right: 16, top: 16, bottom: 26 },
            tooltip: { trigger: 'axis' },
            xAxis: { type: 'category', data: a.labels, axisTick: { show: false }, axisLine: { lineStyle: { color: '#E7E9ED' } }, axisLabel: { color: '#B7BCC5', fontFamily: 'Inter', fontSize: 11 } },
            yAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { color: '#F0F1F3' } }, axisLabel: { color: '#B7BCC5', fontFamily: 'Inter', fontSize: 11 } },
            series: [{ type: 'bar', data: a.payments, barMaxWidth: 22, itemStyle: { borderRadius: [5, 5, 0, 0], color: new g.LinearGradient(0, 0, 0, 1, [{ offset: 0, color: '#2554C7' }, { offset: 1, color: '#7DA0EC' }]) } }],
        });
    })();
</script>
@endscript
