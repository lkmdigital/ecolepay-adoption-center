<?php

use App\Domains\Campaigns\Actions\ListCampaigns;
use App\Domains\Campaigns\Actions\MeasureCampaign;
use App\Domains\Campaigns\Models\Campaign;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $campaignId;

    public function mount(int $campaign): void
    {
        $this->campaignId = $campaign;
        abort_if(Campaign::find($campaign) === null, 404);
    }

    #[Computed]
    public function campaign(): Campaign
    {
        return Campaign::with('school')->findOrFail($this->campaignId);
    }

    #[Computed]
    public function measure(): array
    {
        return app(MeasureCampaign::class)($this->campaign);
    }

    #[Computed]
    public function contacts(): array
    {
        $d = $this->campaign->campaign_date ? Carbon::parse($this->campaign->campaign_date) : Carbon::parse($this->campaign->created_at);

        return DB::table('fact_campaign_contacts as c')
            ->leftJoin('dim_parents as p', 'p.id', '=', 'c.parent_id')
            ->leftJoin('fact_parent_journeys as j', function ($join) {
                $join->on('j.parent_id', '=', 'c.parent_id')->where('j.is_test', false);
                if ($this->campaign->school_id) {
                    $join->where('j.school_id', $this->campaign->school_id);
                }
            })
            ->where('c.campaign_id', $this->campaignId)->where('c.is_valid', true)
            ->groupBy('c.id', 'c.full_name', 'c.parent_id', 'p.account_created_at')
            ->selectRaw('c.id, c.full_name, c.parent_id, p.account_created_at, MAX(j.first_payment_at) as first_payment_at, MAX(j.last_activity_at) as last_activity_at, MAX(j.has_ever_paid) as paid, MAX(j.current_stage_id) as stage')
            ->limit(60)->get()
            ->map(function ($r) use ($d) {
                $acc = $r->account_created_at ? Carbon::parse($r->account_created_at) : null;
                $status = $r->parent_id === null ? ['Hors base', '#5B6472', '#F2F3F5']
                    : ($acc === null ? ['Sans compte', '#6B7280', '#F2F3F5']
                    : ($acc >= $d ? ['Nouvel inscrit', '#0F7A44', '#E9F8EF'] : ['Déjà inscrit', '#1D3F9C', '#EEF3FE']));
                $engagement = ($acc ? 30 : 0) + ($r->paid ? 40 : 0) + (in_array((int) $r->stage, [3, 4], true) ? 30 : 0);

                return [
                    'name' => $r->full_name ?: '—',
                    'known' => $r->parent_id !== null,
                    'status' => $status,
                    'account' => $r->account_created_at,
                    'firstPayment' => $r->first_payment_at,
                    'lastActivity' => $r->last_activity_at,
                    'engagement' => $engagement,
                ];
            })->all();
    }

    /** Analyse « intelligente » par règles : compare à la moyenne des campagnes. */
    #[Computed]
    public function analysis(): array
    {
        $m = $this->measure;

        // Opération sans liste de contacts : mesure au niveau de l'école.
        if ($m['mode'] !== 'contacts') {
            $text = "Opération sans liste de contacts individuels, mesurée au niveau de l'école. Depuis l'opération, {$this->fr($m['newAccounts'])} nouveaux comptes ont été créés et {$this->fr($m['newPayments'])} parents ont effectué un premier paiement dans la fenêtre d'attribution.";

            return [
                'text' => $text,
                'actions' => [
                    'Prolonger l\'action par une relance ciblée des inscrits inactifs.',
                    'Mesurer sur une fenêtre plus longue si l\'effet est différé.',
                    'Documenter l\'action pour la comparer aux prochaines opérations.',
                ],
                'delta' => null,
            ];
        }

        $global = app(ListCampaigns::class)()['kpis'];
        $avgConv = $global['conversion'];
        $delta = $avgConv > 0 ? round(($m['conversion'] - $avgConv) / $avgConv * 100) : null;

        $text = "Cette campagne a ciblé {$this->fr($m['contacts'])} contacts. {$this->fr($m['newAccounts'])} nouveaux comptes ont été créés et {$this->fr($m['newPayments'])} parents ont effectué un premier paiement dans la fenêtre d'attribution.";
        if ($delta !== null && $global['campaigns'] > 1) {
            $text .= ' Le taux de conversion est '.($delta >= 0 ? "supérieur de {$delta} %" : 'inférieur de '.abs($delta).' %').' à la moyenne des campagnes.';
        }

        $actions = [];
        $nonInscrits = collect($this->contacts)->where('known', true)->whereNull('account')->count();
        if ($nonInscrits > 0) {
            $actions[] = 'Relancer les parents connus non inscrits.';
        }
        if ($m['conversion'] < max($avgConv, 5)) {
            $actions[] = 'Organiser une campagne complémentaire ciblée.';
        }
        $actions[] = 'Programmer une relance dans 15 jours.';

        return ['text' => $text, 'actions' => $actions, 'delta' => $delta];
    }

    private function fr($n): string
    {
        return number_format((float) $n, 0, ',', ' ');
    }
};

?>

@php
    $c = $this->campaign;
    $m = $this->measure;
    $fr = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $money = fn ($n) => $n >= 1_000_000 ? number_format($n / 1_000_000, 1, ',', ' ').' M F' : $fr($n).' F';
    $dateFr = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->locale('fr')->isoFormat('D MMM YYYY') : '—';
    [$sfg, $sbg] = $c->status->colors();
    $isSchool = $m['mode'] !== 'contacts';
    $summaryCards = $isSchool
        ? [['Nouveaux comptes', $fr($m['newAccounts'])], ['Nouveaux adoptants', $fr($m['newPayments'])], ['Actifs (école)', $fr($m['active'])], ['Conversion', number_format($m['conversion'], 1, ',', ' ').' %'], ['Revenus', $m['revenue'] > 0 ? $money($m['revenue']) : '—'], ['Type', $c->channel->label()]]
        : [['Contacts', $fr($m['contacts'])], ['Rapprochés', $fr($m['matched'])], ['Nouveaux comptes', $fr($m['newAccounts'])], ['Nouveaux adoptants', $fr($m['newPayments'])], ['Conversion', number_format($m['conversion'], 1, ',', ' ').' %'], ['Revenus', $m['revenue'] > 0 ? $money($m['revenue']) : '—']];
@endphp

<div class="mx-auto max-w-[1480px]">

    {{-- Fil d'Ariane + en-tête --}}
    <nav class="mb-4 flex items-center gap-1.5 text-[12.5px] text-ink-500">
        <a href="{{ route('dashboard.index') }}" wire:navigate class="hover:text-ink-800">Dashboard</a><span>/</span>
        <a href="{{ route('campaigns.index') }}" wire:navigate class="hover:text-ink-800">Campagnes</a><span>/</span>
        <span class="font-semibold text-ink-800">{{ $c->name }}</span>
    </nav>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-[23px] font-bold tracking-tight text-ink-900">{{ $c->name }}</h1>
            <div class="mt-1.5 flex flex-wrap items-center gap-2 text-[12.5px] text-ink-500">
                <span class="rounded-full px-2.5 py-0.5 font-semibold" style="background: {{ $sbg }}; color: {{ $sfg }}">{{ $c->status->label() }}</span>
                <span class="rounded-full bg-ink-100 px-2.5 py-0.5 font-medium text-ink-600">{{ $c->channel->label() }}</span>
                <span>{{ $c->school?->name ?: 'Toutes écoles' }}</span><span>·</span>
                <span>{{ $dateFr($c->campaign_date) }}</span><span>·</span>
                <span>{{ $c->owner ?: '—' }}</span>
            </div>
            @if ($c->description)<p class="mt-2 max-w-xl text-[13px] text-ink-600">{{ $c->description }}</p>@endif
        </div>
    </div>

    @if ($isSchool)
        <div class="mb-4 flex items-start gap-2.5 rounded-xl border border-brand-100 bg-brand-50/60 px-4 py-3">
            <svg width="17" height="17" viewBox="0 0 20 20" fill="none" class="mt-0.5 flex-shrink-0 text-brand-600"><path d="M10 2l7 4v4c0 4-3 6.5-7 8-4-1.5-7-4-7-8V6z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
            <div class="text-[12.5px] leading-snug text-ink-700"><span class="font-semibold text-ink-900">Mesure au niveau de l'école.</span> Cette opération ({{ $c->channel->label() }}) n'a pas de liste de contacts individuels : son impact est estimé par l'évolution des inscriptions et paiements de l'école dans la fenêtre d'attribution.</div>
        </div>
    @endif

    {{-- Résumé --}}
    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        @foreach ($summaryCards as [$l, $v])
            <div class="rounded-[13px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="text-[21px] font-bold tracking-tight text-ink-900">{{ $v }}</div>
                <div class="text-[11.5px] text-ink-500">{{ $l }}</div>
            </div>
        @endforeach
    </div>
    <div class="mb-6 text-[11.5px] text-ink-400">Fenêtre d'attribution : {{ $dateFr($m['window'][0]) }} → {{ $dateFr($m['window'][1]) }} ({{ $c->attribution_window_days }} jours).</div>

    {{-- Funnel + Répartition --}}
    <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-4 text-[15px] font-semibold text-ink-900">{{ $isSchool ? "Entonnoir de l'école" : 'Entonnoir de conversion' }}</div>
            @php $fmax = max(1, $m['funnel'][0]['value']); @endphp
            <div class="flex flex-col gap-2.5">
                @foreach ($m['funnel'] as $i => $stage)
                    <div>
                        <div class="mb-1 flex items-center justify-between text-[12.5px]">
                            <span class="font-medium text-ink-800">{{ $stage['label'] }}</span>
                            <span class="font-mono font-semibold text-ink-900">{{ $fr($stage['value']) }}@if ($stage['conv'] !== null)<span class="ml-1.5 text-[11px] font-normal text-ink-400">{{ number_format($stage['conv'], 0, ',', ' ') }} %</span>@endif</span>
                        </div>
                        <div class="h-6 overflow-hidden rounded-md bg-ink-100">
                            <div class="h-full rounded-md" style="width: {{ max(3, round($stage['value'] / $fmax * 100)) }}%; background: linear-gradient(90deg,#2554C7,#4E7DE0); opacity: {{ 1 - $i * 0.12 }}"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-1 text-[15px] font-semibold text-ink-900">{{ $isSchool ? "Répartition de l'école" : 'Répartition des contacts' }}</div>
            <div class="mb-3 text-[12px] text-ink-500">{{ $isSchool ? 'Situation actuelle de la base' : 'Situation des contacts face à EcolePay' }}</div>
            <div class="flex flex-col items-center gap-4 sm:flex-row">
                <div class="w-full max-w-[190px] flex-shrink-0" wire:ignore><div id="camp-donut" class="h-[180px] w-full"></div></div>
                @php $rt = max(1, collect($m['repartition'])->sum('value')); @endphp
                <div class="flex w-full flex-col gap-2.5">
                    @foreach ($m['repartition'] as $seg)
                        <div class="flex items-center gap-2 text-[12.5px]">
                            <span class="h-2.5 w-2.5 flex-shrink-0 rounded-full" style="background: {{ $seg['color'] }}"></span>
                            <span class="flex-1 text-ink-700">{{ $seg['label'] }}</span>
                            <span class="font-mono font-semibold text-ink-900">{{ $fr($seg['value']) }}</span>
                            <span class="w-9 text-right text-[11px] text-ink-400">{{ round($seg['value'] / $rt * 100) }} %</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Évolution --}}
    <div class="mb-6 rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]" x-data="{ range: 30 }">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div>
                <div class="text-[15px] font-semibold text-ink-900">Évolution après la campagne</div>
                <div class="text-[12px] text-ink-500">Inscriptions et premiers paiements cumulés des contacts</div>
            </div>
            <div class="inline-flex rounded-lg border border-ink-200 p-0.5">
                @foreach ([7, 15, 30, 90] as $rg)
                    <button @click="range = {{ $rg }}; window.campSetRange && window.campSetRange({{ $rg }})"
                            :class="range === {{ $rg }} ? 'bg-brand-600 text-white' : 'text-ink-600 hover:bg-ink-100'"
                            class="rounded-md px-2.5 py-1 text-[12px] font-semibold">{{ $rg }} j</button>
                @endforeach
            </div>
        </div>
        <div wire:ignore><div id="camp-evolution" class="h-[260px] w-full"></div></div>
    </div>

    {{-- Analyse IA --}}
    <div class="mb-6 rounded-[18px] border border-brand-200 bg-brand-50/40 p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
        <div class="mb-3 flex items-center gap-2">
            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-600 text-white">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 2.5a5.5 5.5 0 00-3 10.1V15h6v-2.4a5.5 5.5 0 00-3-10.1z" stroke="currentColor" stroke-width="1.6"/><path d="M8 17h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            </span>
            <span class="text-[15px] font-bold text-ink-900">Analyse intelligente</span>
            <span class="rounded bg-white px-1.5 py-0.5 text-[9.5px] font-bold uppercase tracking-wide text-ink-500">règles métier · v1</span>
        </div>
        <p class="text-[13.5px] leading-relaxed text-ink-700">{{ $this->analysis['text'] }}</p>
        <div class="mt-4">
            <div class="mb-2 text-[11px] font-bold uppercase tracking-wide text-ink-500">Actions recommandées</div>
            <div class="flex flex-col gap-2">
                @foreach ($this->analysis['actions'] as $action)
                    <div class="flex items-center gap-2.5 rounded-xl border border-ink-150 bg-white px-3.5 py-2.5">
                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-brand-600"><path d="M4 10.5l3.5 3.5L16 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span class="text-[13px] font-medium text-ink-800">{{ $action }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Contacts (uniquement pour les opérations à liste) --}}
    @unless ($isSchool)
    <div class="mb-6 overflow-hidden rounded-[16px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
        <div class="flex items-center justify-between border-b border-ink-150 px-6 py-4">
            <span class="text-[15px] font-semibold text-ink-900">Contacts de la campagne</span>
            <span class="text-[12px] text-ink-500">{{ count($this->contacts) }} affichés{{ $m['contacts'] > count($this->contacts) ? ' / '.$fr($m['contacts']) : '' }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-ink-50 text-[11px] font-bold uppercase tracking-wider text-ink-500">
                        <th class="px-5 py-2.5 text-left">Parent</th>
                        <th class="px-3 py-2.5 text-left">Statut</th>
                        <th class="px-3 py-2.5 text-left">Compte créé</th>
                        <th class="px-3 py-2.5 text-left">Premier paiement</th>
                        <th class="px-3 py-2.5 text-left">Dernière activité</th>
                        <th class="px-5 py-2.5 text-right">Engagement</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->contacts as $ct)
                        <tr class="border-b border-ink-150 last:border-0 hover:bg-ink-50">
                            <td class="px-5 py-2.5 text-[13px] font-medium text-ink-900">{{ $ct['name'] }}</td>
                            <td class="px-3 py-2.5"><span class="inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background: {{ $ct['status'][2] }}; color: {{ $ct['status'][1] }}">{{ $ct['status'][0] }}</span></td>
                            <td class="px-3 py-2.5 text-[12px] text-ink-600">{{ $dateFr($ct['account']) }}</td>
                            <td class="px-3 py-2.5 text-[12px] text-ink-600">{{ $dateFr($ct['firstPayment']) }}</td>
                            <td class="px-3 py-2.5 text-[12px] text-ink-600">{{ $dateFr($ct['lastActivity']) }}</td>
                            <td class="px-5 py-2.5">
                                <div class="flex items-center justify-end gap-2">
                                    <div class="h-1.5 w-16 overflow-hidden rounded-full bg-ink-100"><div class="h-full rounded-full bg-brand-600" style="width: {{ $ct['engagement'] }}%"></div></div>
                                    <span class="w-6 text-right font-mono text-[11.5px] text-ink-600">{{ $ct['engagement'] }}</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endunless

    {{-- Historique --}}
    <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
        <div class="mb-4 text-[15px] font-semibold text-ink-900">Historique</div>
        <div class="flex flex-col">
            @foreach ([['Campagne créée dans EAC', $c->created_at], ['Import des contacts', $c->created_at], ['Dernière mise à jour', $c->updated_at]] as $i => [$label, $date])
                <div class="relative flex gap-3.5 pb-4 last:pb-0">
                    @if (! $loop->last)<span class="absolute left-[13px] top-7 bottom-0 w-px bg-ink-150"></span>@endif
                    <span class="relative z-10 mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600"><svg width="13" height="13" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="3" fill="currentColor"/></svg></span>
                    <div><div class="text-[13.5px] font-semibold text-ink-900">{{ $label }}</div><div class="text-[12px] text-ink-500">{{ $date ? \Illuminate\Support\Carbon::parse($date)->locale('fr')->isoFormat('D MMM YYYY à HH:mm') : '—' }}</div></div>
                </div>
            @endforeach
        </div>
    </div>
</div>

@script
<script>
    (() => {
        if (! window.echarts) return;
        const m = @js($this->measure);
        const g = window.echarts.graphic;
        const inter = 'Inter';
        const axisLabel = { color: '#B7BCC5', fontFamily: inter, fontSize: 11 };

        const donutEl = document.getElementById('camp-donut');
        if (donutEl) {
            const c = window.echarts.init(donutEl);
            const total = m.repartition.reduce((a, s) => a + s.value, 0);
            c.setOption({
                tooltip: { trigger: 'item', formatter: '{b}<br/>{c} ({d}%)' },
                series: [{
                    type: 'pie', radius: ['58%', '84%'], center: ['50%', '50%'],
                    label: { show: true, position: 'center', formatter: () => 'Contacts\n' + total.toLocaleString('fr-FR'), fontFamily: inter, fontSize: 12, fontWeight: 600, color: '#14181f', lineHeight: 16 },
                    labelLine: { show: false }, itemStyle: { borderColor: '#fff', borderWidth: 2 },
                    data: m.repartition.map(s => ({ name: s.label, value: s.value, itemStyle: { color: s.color } })),
                }],
            });
        }

        const evoEl = document.getElementById('camp-evolution');
        if (evoEl) {
            const c = window.echarts.init(evoEl);
            const render = (days) => {
                const n = days + 1;
                c.setOption({
                    grid: { left: 38, right: 20, top: 32, bottom: 26 },
                    legend: { data: ['Inscriptions', 'Paiements'], right: 0, top: 0, icon: 'roundRect', textStyle: { fontFamily: inter, fontSize: 11.5, color: '#3A4150' } },
                    tooltip: { trigger: 'axis' },
                    xAxis: { type: 'category', boundaryGap: false, data: m.evolution.labels.slice(0, n).map(d => 'J+' + d), axisTick: { show: false }, axisLine: { lineStyle: { color: '#E7E9ED' } }, axisLabel: { ...axisLabel, interval: Math.ceil(n / 8) } },
                    yAxis: { type: 'value', splitLine: { lineStyle: { color: '#F0F1F3' } }, axisLabel },
                    series: [
                        { name: 'Inscriptions', type: 'line', data: m.evolution.accounts.slice(0, n), smooth: 0.3, showSymbol: false, lineStyle: { color: '#2554C7', width: 3 }, itemStyle: { color: '#2554C7' }, areaStyle: { color: new g.LinearGradient(0, 0, 0, 1, [{ offset: 0, color: 'rgba(37,84,199,0.22)' }, { offset: 1, color: 'rgba(37,84,199,0.01)' }]) } },
                        { name: 'Paiements', type: 'line', data: m.evolution.payments.slice(0, n), smooth: 0.3, showSymbol: false, lineStyle: { color: '#189B57', width: 3 }, itemStyle: { color: '#189B57' } },
                    ],
                });
            };
            render(30);
            window.campSetRange = render;
        }
    })();
</script>
@endscript
