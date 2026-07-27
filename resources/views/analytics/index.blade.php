<?php

use App\Domains\Analytics\Actions\ComputeAnalytics;
use App\Domains\Schools\Models\School;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $period = 'school_year';

    #[Url]
    public string $model = '';

    #[Url]
    public ?int $school = null;

    #[Computed]
    public function data(): array
    {
        return app(ComputeAnalytics::class)($this->period, $this->model, $this->school);
    }

    public function schools(): array
    {
        return School::query()->where('is_test', false)->current()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function updated(): void
    {
        unset($this->data);
        $this->dispatch('analytics:data', payload: $this->data);
    }

    public function export()
    {
        $kpis = $this->data['kpis'];

        return response()->streamDownload(function () use ($kpis) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Indicateur', 'Valeur']);
            foreach ($kpis as $k) {
                fputcsv($out, [$k['label'], $k['value']]);
            }
            fclose($out);
        }, 'analytics-'.now()->format('Y-m-d').'.csv');
    }
};

?>

@php
    $fr = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $money = fn ($n) => $n >= 1_000_000 ? number_format($n / 1_000_000, 1, ',', ' ').' M F' : $fr($n).' F';
    $fmtKpi = function ($k) use ($fr, $money) {
        return match ($k['format']) {
            'pct' => number_format($k['value'], 1, ',', ' ').' %',
            'money' => $money($k['value']),
            'delta' => $k['value'] === null ? '—' : ($k['value'] >= 0 ? '+' : '').number_format($k['value'], 1, ',', ' ').' %',
            default => $fr($k['value']),
        };
    };
    $spark = function (?array $s) {
        if (! $s || count($s) < 2 || max($s) == 0) {
            return '';
        }
        [$w, $h] = [80, 26];
        $mx = max($s);
        $mn = min($s);
        $rg = max($mx - $mn, 1);
        $pts = [];
        foreach ($s as $i => $v) {
            $pts[] = round($i / (count($s) - 1) * $w, 1).','.round($h - 2 - ($v - $mn) / $rg * ($h - 5), 1);
        }

        return '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" fill="none"><polyline points="'.implode(' ', $pts).'" fill="none" stroke="#2554C7" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    };
    $periods = ['7d' => '7 jours', '30d' => '30 jours', 'this_year' => 'Cette année', 'school_year' => 'Année scolaire'];
    $models = ['' => 'Tous abonnements', 'parent_paid' => 'Payé par les parents', 'bundled' => 'Intégré à la scolarité'];
    $prio = ['critique' => ['#B91C1C', '#FDECEC'], 'elevee' => ['#B45F04', '#FEF3E2'], 'moyenne' => ['#1D3F9C', '#EEF3FE'], 'faible' => ['#5B6472', '#F2F3F5']];
    $prioLbl = ['critique' => 'Critique', 'elevee' => 'Élevée', 'moyenne' => 'Moyenne', 'faible' => 'Faible'];
    $alert = ['danger' => ['#B91C1C', '#FDECEC'], 'warning' => ['#B45F04', '#FEF3E2'], 'info' => ['#1D3F9C', '#EEF3FE']];
    $d = $this->data;
@endphp

<div class="mx-auto max-w-[1480px]">

    {{-- Barre de filtres --}}
    <div class="mb-6 flex flex-wrap items-center gap-2">
        <flux:dropdown>
            <button class="inline-flex items-center gap-2 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold text-ink-800 hover:bg-ink-50">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" class="text-ink-500"><rect x="3" y="4.5" width="14" height="12" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M3 8h14M7 3v3M13 3v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                {{ $periods[$period] }}
                <svg width="12" height="12" viewBox="0 0 20 20" fill="none"><path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <flux:menu>
                @foreach ($periods as $key => $label)<flux:menu.item wire:click="$set('period','{{ $key }}')" icon="{{ $period === $key ? 'check' : '' }}">{{ $label }}</flux:menu.item>@endforeach
            </flux:menu>
        </flux:dropdown>
        <flux:dropdown>
            <button class="inline-flex items-center gap-2 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold text-ink-800 hover:bg-ink-50">
                {{ $models[$model] }}
                <svg width="12" height="12" viewBox="0 0 20 20" fill="none"><path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <flux:menu>
                @foreach ($models as $key => $label)<flux:menu.item wire:click="$set('model','{{ $key }}')" icon="{{ $model === $key ? 'check' : '' }}">{{ $label }}</flux:menu.item>@endforeach
            </flux:menu>
        </flux:dropdown>
        <select wire:model.live="school" class="rounded-lg border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold text-ink-800">
            <option value="">Toutes les écoles</option>
            @foreach ($this->schools() as $id => $sname)<option value="{{ $id }}">{{ $sname }}</option>@endforeach
        </select>
        @foreach ([['Région', 'Géographie absente'], ['Ville', 'Géographie absente'], ['Commercial', 'Non renseigné'], ['Niveau scolaire', 'Rattachement élève→parent — via le Laboratoire'], ['Type campagne', 'Filtre dans le module Campagnes']] as [$fl, $ft])
            <button type="button" disabled title="{{ $ft }}" class="inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg border border-dashed border-ink-200 bg-ink-50 px-3 py-2 text-[13px] font-medium text-ink-400">{{ $fl }}<span class="rounded bg-ink-100 px-1 text-[9px] font-bold uppercase">à venir</span></button>
        @endforeach
        <div class="ml-auto flex items-center gap-2">
            <button wire:click="export" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 3v9m0 0l-3.2-3.2M10 12l3.2-3.2M4 15.5h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Exporter
            </button>
            <button wire:click="$refresh" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">Actualiser</button>
        </div>
    </div>

    {{-- 1. KPI avancés --}}
    <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Vue d'ensemble analytique</div>
    <div class="mb-8 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($d['kpis'] as $k)
            <div class="group relative rounded-[13px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)]" title="{{ $k['tip'] }}">
                <div class="flex items-start justify-between">
                    <div class="text-[12px] font-semibold text-ink-700">{{ $k['label'] }}</div>
                    @if (! empty($k['spark']))<div class="opacity-80">{!! $spark($k['spark']) !!}</div>@endif
                </div>
                <div class="mt-1.5 text-[22px] font-bold tracking-tight {{ $k['format'] === 'delta' && $k['value'] !== null ? ($k['value'] >= 0 ? 'text-success' : 'text-danger') : 'text-ink-900' }}">{{ $fmtKpi($k) }}</div>
                <div class="mt-0.5 truncate text-[10.5px] text-ink-400">{{ \Illuminate\Support\Str::limit($k['tip'], 42) }}</div>
            </div>
        @endforeach
    </div>

    {{-- 2. Entonnoir de conversion + frictions --}}
    <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Analyse des conversions</div>
    <div class="mb-8 grid grid-cols-1 gap-4 lg:grid-cols-[1.7fr_1fr]">
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-4 text-[15px] font-semibold text-ink-900">Entonnoir d'adoption</div>
            @php $fmax = max(1, $d['funnel']['stages'][0]['value']); @endphp
            <div class="flex flex-col gap-2.5">
                @foreach ($d['funnel']['stages'] as $i => $st)
                    <div>
                        <div class="mb-1 flex items-center justify-between text-[13px]">
                            <span class="font-medium text-ink-800">{{ $st['label'] }}{{ $st['star'] ? ' ⭐' : '' }}</span>
                            <span class="font-mono font-semibold text-ink-900">{{ $fr($st['value']) }}@if ($st['conv'] !== null)<span class="ml-2 text-[11px] font-normal text-ink-400">{{ number_format($st['conv'], 0, ',', ' ') }} %</span>@endif</span>
                        </div>
                        <div class="h-7 overflow-hidden rounded-md bg-ink-100"><div class="h-full rounded-md" style="width: {{ max(3, round($st['value'] / $fmax * 100)) }}%; background: linear-gradient(90deg,#173C82,#2554C7,#4E7DE0); opacity: {{ 1 - $i * 0.12 }}"></div></div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-4 text-[15px] font-semibold text-ink-900">Points de friction</div>
            @forelse ($d['funnel']['frictions'] as $fr2)
                <div class="mb-3 rounded-xl border border-warning/25 bg-warning-soft/60 p-3.5">
                    <div class="text-[22px] font-extrabold text-warning">{{ number_format($fr2['loss'], 0, ',', ' ') }} %</div>
                    <div class="text-[12.5px] text-ink-700">de pertes entre <span class="font-semibold">{{ $fr2['from'] }}</span> et <span class="font-semibold">{{ $fr2['to'] }}</span></div>
                </div>
            @empty
                <div class="text-[13px] text-ink-500">Aucune fuite majeure (&gt; 30 %) détectée dans l'entonnoir.</div>
            @endforelse
        </div>
    </div>

    {{-- 3. Tendances --}}
    <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Analyse des tendances</div>
    <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-1 text-[15px] font-semibold text-ink-900">Évolution du taux d'adoption</div>
            <div class="mb-3 text-[12px] text-ink-500">Zoom et survol interactifs</div>
            <div wire:ignore><div id="an-adoption" class="h-[260px] w-full"></div></div>
        </div>
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-1 text-[15px] font-semibold text-ink-900">Nouveaux adoptants &amp; engagés</div>
            <div class="mb-3 text-[12px] text-ink-500">Par mois</div>
            <div wire:ignore><div id="an-newparents" class="h-[260px] w-full"></div></div>
        </div>
    </div>

    {{-- 4. Comparaison des écoles --}}
    <div class="mb-3 mt-8 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Comparaison des établissements</div>
    <div class="mb-8 grid grid-cols-1 gap-4 lg:grid-cols-[1.4fr_1fr]">
        <div class="overflow-x-auto rounded-[16px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-ink-50 text-[11px] font-bold uppercase tracking-wider text-ink-500">
                        <th class="px-4 py-3 text-left">École</th><th class="px-2 py-3 text-right">Insc.</th><th class="px-2 py-3 text-right">Activ.</th>
                        <th class="px-2 py-3 text-right">Adopt.</th><th class="px-2 py-3 text-right">Engagés</th><th class="px-2 py-3 text-right">Santé</th><th class="px-3 py-3 text-right">Potentiel</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($d['comparison'] as $s)
                        <tr class="border-b border-ink-150 last:border-0 hover:bg-ink-50">
                            <td class="px-4 py-2.5 text-[13px] font-semibold text-ink-900">{{ $s['name'] }}</td>
                            <td class="px-2 py-2.5 text-right font-mono text-[12px] text-ink-700">{{ number_format($s['registration'], 0, ',', ' ') }}%</td>
                            <td class="px-2 py-2.5 text-right font-mono text-[12px] text-ink-700">{{ number_format($s['activation'], 0, ',', ' ') }}%</td>
                            <td class="px-2 py-2.5 text-right font-mono text-[12px] font-semibold text-ink-900">{{ number_format($s['adoption'], 0, ',', ' ') }}%</td>
                            <td class="px-2 py-2.5 text-right font-mono text-[12px] text-ink-700">{{ $fr($s['engages']) }}</td>
                            <td class="px-2 py-2.5 text-right font-mono text-[12px] font-semibold" style="color: {{ $s['health'] >= 70 ? '#0F7A44' : ($s['health'] >= 40 ? '#B45F04' : '#B91C1C') }}">{{ $s['health'] }}</td>
                            <td class="px-3 py-2.5 text-right font-mono text-[12px] text-ink-700">{{ $s['potential'] > 0 ? $money($s['potential']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-3 text-[15px] font-semibold text-ink-900">Profil comparé (radar)</div>
            <div wire:ignore><div id="an-radar" class="h-[280px] w-full"></div></div>
        </div>
    </div>

    {{-- 5. Revenus --}}
    <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Analyse des revenus</div>
    <div class="mb-8 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-3 text-[15px] font-semibold text-ink-900">Par mode d'abonnement</div>
            <div wire:ignore><div id="an-revsub" class="h-[220px] w-full"></div></div>
        </div>
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-3 text-[15px] font-semibold text-ink-900">Top écoles</div>
            <div wire:ignore><div id="an-revschool" class="h-[220px] w-full"></div></div>
        </div>
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-1 text-[15px] font-semibold text-ink-900">Revenus cumulés</div>
            <div class="mb-2 text-[12px] text-ink-500">Prévision mois +1 : <span class="font-semibold text-brand-600">{{ $d['revenue']['forecast'] }} M F/mois</span></div>
            <div wire:ignore><div id="an-revcum" class="h-[190px] w-full"></div></div>
        </div>
    </div>

    {{-- 6. Campagnes --}}
    <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Analyse des campagnes</div>
    <div class="mb-8 rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
        @if (count($d['campaigns']['ranking']))
            <div class="mb-4 flex flex-wrap gap-6 text-[13px]">
                <div><span class="text-ink-500">Meilleur canal :</span> <span class="font-semibold text-ink-900">{{ $d['campaigns']['byChannel'][0]['channel'] ?? '—' }}</span> ({{ number_format($d['campaigns']['byChannel'][0]['conversion'] ?? 0, 1, ',', ' ') }} %)</div>
                <div><span class="text-ink-500">Délai moyen campagne → 1ᵉʳ paiement :</span> <span class="font-semibold text-ink-900">{{ $d['campaigns']['avgDaysToPayment'] !== null ? $d['campaigns']['avgDaysToPayment'].' jours' : '—' }}</span></div>
            </div>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-ink-50 text-[11px] font-bold uppercase tracking-wider text-ink-500">
                        <th class="px-4 py-2.5 text-left">#</th><th class="px-2 py-2.5 text-left">Campagne</th><th class="px-3 py-2.5 text-right">Contacts</th>
                        <th class="px-3 py-2.5 text-right">Adoptants</th><th class="px-3 py-2.5 text-right">Conversion</th><th class="px-4 py-2.5 text-right">Revenus</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($d['campaigns']['ranking'] as $i => $c)
                        <tr class="border-b border-ink-150 last:border-0 hover:bg-ink-50 cursor-pointer" onclick="window.location='{{ route('campaigns.show', $c['id']) }}'">
                            <td class="px-4 py-2.5 text-[13px] font-bold text-ink-400">{{ $i + 1 }}</td>
                            <td class="px-2 py-2.5 text-[13px] font-semibold text-ink-900">{{ $c['name'] }} <span class="text-[11px] text-ink-400">· {{ $c['channel']->label() }}</span></td>
                            <td class="px-3 py-2.5 text-right font-mono text-[12.5px] text-ink-700">{{ $c['channel']->isContactBased() ? $fr($c['contacts']) : '—' }}</td>
                            <td class="px-3 py-2.5 text-right font-mono text-[12.5px] text-ink-900">{{ $fr($c['newPayments']) }}</td>
                            <td class="px-3 py-2.5 text-right font-mono text-[12.5px] font-bold text-brand-700">{{ number_format($c['conversion'], 1, ',', ' ') }} %</td>
                            <td class="px-4 py-2.5 text-right font-mono text-[12.5px] text-ink-700">{{ $c['revenue'] > 0 ? $money($c['revenue']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="py-8 text-center text-[13px] text-ink-500">Aucune campagne mesurée pour le moment.</div>
        @endif
    </div>

    {{-- 7. Anomalies + Recommandations --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div>
            <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Anomalies détectées</div>
            <div class="flex flex-col gap-2.5">
                @forelse ($d['anomalies'] as $a)
                    @php [$afg, $abg] = $alert[$a['level']]; @endphp
                    <div class="rounded-[14px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full" style="background: {{ $abg }}; color: {{ $afg }}"><svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 6.5v4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="10" cy="14" r="1" fill="currentColor"/><path d="M8.6 3.5L2.5 15a1.5 1.5 0 001.3 2.2h12.4A1.5 1.5 0 0017.5 15L11.4 3.5a1.6 1.6 0 00-2.8 0z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg></span>
                            <div class="min-w-0 flex-1">
                                <div class="text-[13.5px] font-semibold text-ink-900">{{ $a['title'] }}</div>
                                <div class="mt-0.5 text-[12.5px] text-ink-600">{{ $a['detail'] }}</div>
                                <div class="mt-1.5 flex items-center gap-2 text-[11px]">
                                    <span class="rounded-full px-1.5 py-0.5 font-semibold" style="background: {{ $abg }}; color: {{ $afg }}">{{ $a['impact'] }}</span>
                                    <a href="{{ route('schools.show', $a['school']) }}" wire:navigate class="font-semibold text-brand-600 hover:underline">Voir l'analyse →</a>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-[14px] border border-dashed border-ink-200 bg-white py-8 text-center text-[13px] text-ink-500">Aucune anomalie détectée.</div>
                @endforelse
            </div>
        </div>
        <div>
            <div class="mb-3 flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Analyse intelligente <span class="rounded bg-ink-100 px-1.5 py-0.5 text-[9.5px] font-bold tracking-normal text-ink-500 normal-case">règles métier · v1</span></div>
            <div class="flex flex-col gap-2.5">
                @foreach ($d['recommendations'] as $r)
                    @php [$rfg, $rbg] = $prio[$r['priority']]; @endphp
                    <div class="rounded-[14px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                        <div class="flex items-start justify-between gap-3">
                            <span class="text-[13.5px] font-semibold text-ink-900">{{ $r['title'] }}</span>
                            <span class="flex-shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background: {{ $rbg }}; color: {{ $rfg }}">{{ $prioLbl[$r['priority']] }}</span>
                        </div>
                        <div class="mt-1 text-[12.5px] text-ink-600">{{ $r['why'] }}</div>
                        <div class="mt-1.5 text-[11.5px] font-medium text-ink-500">Impact : {{ $r['impact'] }}</div>
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
        const g = window.echarts.graphic;
        const inter = 'Inter';
        const axis = { color: '#B7BCC5', fontFamily: inter, fontSize: 11 };
        const charts = {};
        const init = (id) => { const el = document.getElementById(id); if (! el) return null; if (charts[id]) charts[id].dispose(); charts[id] = window.echarts.init(el); return charts[id]; };

        const render = (d) => {
            const a = init('an-adoption');
            if (a) a.setOption({
                grid: { left: 40, right: 16, top: 16, bottom: 44 },
                tooltip: { trigger: 'axis', valueFormatter: v => v + ' %' },
                dataZoom: [{ type: 'inside' }, { type: 'slider', height: 16, bottom: 8 }],
                xAxis: { type: 'category', boundaryGap: false, data: d.trends.labels, axisTick: { show: false }, axisLine: { lineStyle: { color: '#E7E9ED' } }, axisLabel: axis },
                yAxis: { type: 'value', splitLine: { lineStyle: { color: '#F0F1F3' } }, axisLabel: { ...axis, formatter: '{value} %' } },
                series: [{ type: 'line', data: d.trends.adoptionRate, smooth: 0.4, showSymbol: false, lineStyle: { color: '#2554C7', width: 3, cap: 'round' }, areaStyle: { color: new g.LinearGradient(0, 0, 0, 1, [{ offset: 0, color: 'rgba(37,84,199,0.28)' }, { offset: 1, color: 'rgba(37,84,199,0.01)' }]) } }],
            });

            const np = init('an-newparents');
            if (np) np.setOption({
                grid: { left: 34, right: 16, top: 30, bottom: 26 },
                legend: { data: ['Adoptants', 'Engagés'], right: 0, top: 0, icon: 'roundRect', textStyle: { fontFamily: inter, fontSize: 11.5, color: '#3A4150' } },
                tooltip: { trigger: 'axis' },
                xAxis: { type: 'category', data: d.trends.labels, axisTick: { show: false }, axisLine: { lineStyle: { color: '#E7E9ED' } }, axisLabel: axis },
                yAxis: { type: 'value', splitLine: { lineStyle: { color: '#F0F1F3' } }, axisLabel: axis },
                series: [
                    { name: 'Adoptants', type: 'bar', data: d.trends.newAdopters, barMaxWidth: 14, itemStyle: { borderRadius: [4, 4, 0, 0], color: '#189B57' } },
                    { name: 'Engagés', type: 'bar', data: d.trends.newEngaged, barMaxWidth: 14, itemStyle: { borderRadius: [4, 4, 0, 0], color: '#0B6A3B' } },
                ],
            });

            const rd = init('an-radar');
            if (rd && d.comparison.length) {
                const top = d.comparison.slice(0, 5);
                rd.setOption({
                    tooltip: {},
                    radar: { indicator: [{ name: 'Inscription', max: 100 }, { name: 'Activation', max: 100 }, { name: 'Adoption', max: 100 }, { name: 'Santé', max: 100 }, { name: 'Progression', max: Math.max(10, ...top.map(s => s.progression)) }], radius: '62%', axisName: { color: '#6B7280', fontFamily: inter, fontSize: 10 }, splitLine: { lineStyle: { color: '#EEF0F3' } }, splitArea: { show: false } },
                    series: [{ type: 'radar', data: top.map(s => ({ name: s.name, value: [s.registration, s.activation, s.adoption, s.health, s.progression] })), lineStyle: { width: 1.5 }, symbolSize: 3, areaStyle: { opacity: 0.05 } }],
                    color: ['#2554C7', '#189B57', '#D97706', '#B91C1C', '#7C3AED'],
                });
            }

            const rs = init('an-revsub');
            if (rs) rs.setOption({
                tooltip: { trigger: 'item', formatter: p => p.name + '<br/>' + (p.value / 1e6).toFixed(1) + ' M F (' + p.percent + '%)' },
                series: [{ type: 'pie', radius: ['52%', '80%'], center: ['50%', '50%'], label: { show: false }, itemStyle: { borderColor: '#fff', borderWidth: 2 }, data: d.revenue.bySubscription.map((s, i) => ({ name: s.label, value: s.value, itemStyle: { color: ['#2554C7', '#94A3B8'][i] } })) }],
                legend: { bottom: 0, textStyle: { fontFamily: inter, fontSize: 11 } },
            });

            const rsc = init('an-revschool');
            if (rsc) rsc.setOption({
                grid: { left: 6, right: 40, top: 8, bottom: 8, containLabel: true },
                tooltip: { trigger: 'axis', valueFormatter: v => (v / 1e6).toFixed(1) + ' M F' },
                xAxis: { type: 'value', splitLine: { lineStyle: { color: '#F0F1F3' } }, axisLabel: { ...axis, formatter: v => (v / 1e6).toFixed(0) + ' M' } },
                yAxis: { type: 'category', inverse: true, data: d.revenue.bySchool.map(s => s.name.length > 16 ? s.name.slice(0, 15) + '…' : s.name), axisLabel: { ...axis, fontSize: 10 }, axisTick: { show: false }, axisLine: { show: false } },
                series: [{ type: 'bar', data: d.revenue.bySchool.map(s => s.value), barMaxWidth: 13, itemStyle: { borderRadius: [0, 4, 4, 0], color: new g.LinearGradient(0, 0, 1, 0, [{ offset: 0, color: '#2554C7' }, { offset: 1, color: '#7DA0EC' }]) } }],
            });

            const rc = init('an-revcum');
            if (rc) rc.setOption({
                grid: { left: 38, right: 12, top: 12, bottom: 22 },
                tooltip: { trigger: 'axis', valueFormatter: v => v + ' M F' },
                xAxis: { type: 'category', boundaryGap: false, data: d.revenue.labels, axisTick: { show: false }, axisLine: { lineStyle: { color: '#E7E9ED' } }, axisLabel: axis },
                yAxis: { type: 'value', splitLine: { lineStyle: { color: '#F0F1F3' } }, axisLabel: { ...axis, formatter: '{value}' } },
                series: [{ type: 'line', data: d.revenue.cumulative, smooth: true, showSymbol: false, lineStyle: { color: '#0F7A44', width: 3 }, areaStyle: { color: 'rgba(15,122,68,0.12)' } }],
            });
        };

        render(@js($this->data));
        window.addEventListener('resize', () => Object.values(charts).forEach(c => c.resize()));
        $wire.on('analytics:data', (e) => render(e.payload ?? e[0]?.payload ?? e));
    })();
</script>
@endscript
