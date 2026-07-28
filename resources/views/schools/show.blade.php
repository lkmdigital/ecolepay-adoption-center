<?php

use App\Domains\Campaigns\Models\Campaign;
use App\Domains\Parents\Support\ParentLifecycle;
use App\Domains\Reports\Models\Report;
use App\Domains\Schools\Actions\ComputeSchoolProfile;
use App\Domains\Schools\Models\School;
use App\Domains\Schools\Support\IvorianGazetteer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $schoolId;

    // Localisation éditable (ville + commune).
    public string $geoCity = '';

    public string $geoCommune = '';

    public function mount(int $school): void
    {
        $this->schoolId = $school;
        abort_if($this->profile() === null, 404);

        $row = School::find($school);
        $this->geoCity = $row?->city ?? '';
        $this->geoCommune = $row?->district ?? '';
    }

    public function saveLocation(): void
    {
        $this->validate([
            'geoCity' => 'nullable|string|max:80',
            'geoCommune' => 'nullable|string|max:80',
        ]);

        $school = School::findOrFail($this->schoolId);
        $city = trim($this->geoCity);
        $commune = trim($this->geoCommune);

        // Géocodage via le répertoire ivoirien : la commune est plus précise que
        // la ville (ex. quartiers d'Abidjan), on l'essaie en premier.
        $loc = IvorianGazetteer::locate(trim($commune.' '.$city));

        $school->forceFill([
            'city' => $city ?: null,
            'district' => $commune ?: null,
            'region' => $loc['region'] ?? null,
            'latitude' => $loc['lat'] ?? null,
            'longitude' => $loc['lng'] ?? null,
        ])->save();

        unset($this->profile);

        $this->dispatch('school-geo-saved', mapped: (bool) $loc);
    }

    #[Computed]
    public function profile(): ?array
    {
        return app(ComputeSchoolProfile::class)($this->schoolId);
    }

    public function exportReport()
    {
        $p = $this->profile();
        $k = $p['kpis'];

        return response()->streamDownload(function () use ($p, $k) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Fiche école', $p['school']['name']]);
            fputcsv($out, ['Code', $p['school']['code']]);
            fputcsv($out, []);
            fputcsv($out, ['Indicateur', 'Valeur']);
            foreach ([
                'Élèves' => $k['students'], 'Parents connus' => $k['known'], 'Comptes créés' => $k['inscrits'],
                'Parents adoptants' => $k['actifs'], 'Paiements réalisés' => $k['paymentsCount'],
                'Taux adoption %' => $k['rate'], 'Score de santé' => $p['health']['score'],
                'Chiffre d\'affaires' => $k['revenue'], 'Potentiel restant' => $k['potential'],
            ] as $label => $value) {
                fputcsv($out, [$label, $value]);
            }
            fclose($out);
        }, 'fiche-'.$p['school']['code'].'-'.now()->format('Y-m-d').'.csv');
    }

    // --- Données des onglets (scopées à l'école) -------------------------------

    #[Computed]
    public function tabParents(): array
    {
        $rows = DB::table('fact_parent_journeys as j')
            ->leftJoin('dim_parents as p', 'p.id', '=', 'j.parent_id')
            ->where('j.is_test', false)->where('j.school_id', $this->schoolId)
            ->orderByDesc('j.has_ever_paid')->orderByDesc('j.successful_payment_count')->orderBy('p.full_name')
            ->limit(60)
            ->get(['p.id', 'p.full_name', 'p.phone_e164', 'p.account_created_at', 'j.has_ever_paid', 'j.successful_payment_count', 'j.first_payment_at']);

        $total = (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('school_id', $this->schoolId)->distinct()->count('parent_id');

        return ['rows' => $rows, 'total' => $total];
    }

    #[Computed]
    public function tabPayments(): array
    {
        // Historique des paiements RÉUSSIS uniquement (on n'affiche pas les
        // paiements en attente ou échoués dans la fiche).
        $rows = DB::table('fact_payments as f')
            ->leftJoin('dim_parents as p', 'p.id', '=', 'f.parent_id')
            ->leftJoin('dim_payment_methods as m', 'm.id', '=', 'f.payment_method_id')
            ->where('f.is_test', false)->where('f.school_id', $this->schoolId)->where('f.status', 'success')
            ->orderByDesc('f.paid_at')->limit(60)
            ->get(['f.id', 'f.parent_id', 'f.paid_at', 'f.amount', 'f.is_manual', 'f.is_first_payment', 'p.full_name', 'm.label_fr as method']);

        $agg = DB::table('fact_payments')->where('is_test', false)->where('school_id', $this->schoolId)->where('status', 'success')
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN is_manual=0 THEN amount ELSE 0 END),0) as revenue, COALESCE(SUM(CASE WHEN is_manual=1 THEN 1 ELSE 0 END),0) as manual_count')
            ->first();

        return ['rows' => $rows, 'total' => (int) $agg->total, 'revenue' => (int) $agg->revenue, 'manual' => (int) $agg->manual_count];
    }

    #[Computed]
    public function tabSubscriptions(): array
    {
        // Aucune table d'abonnements dans l'entrepôt : la portion abonnement se
        // lit via les paiements (fact_payments.subscription_amount).
        $base = DB::table('fact_payments')->where('is_test', false)->where('is_manual', false)->where('status', 'success')->where('school_id', $this->schoolId);
        $subRevenue = (int) (clone $base)->sum('subscription_amount');
        $subscribers = (int) (clone $base)->where('subscription_amount', '>', 0)->distinct()->count('parent_id');

        return ['subRevenue' => $subRevenue, 'subscribers' => $subscribers];
    }

    #[Computed]
    public function tabCampaigns(): Collection
    {
        return Campaign::query()->where('school_id', $this->schoolId)
            ->orderByDesc('campaign_date')->orderByDesc('id')->limit(30)->get();
    }

    #[Computed]
    public function tabReports(): Collection
    {
        return Report::query()->where('school_id', $this->schoolId)->latest('id')->limit(30)->get();
    }
};

?>

@php
    $p = $this->profile;
    $sc = $p['school'];
    $k = $p['kpis'];
    $h = $p['health'];
    $dg = $p['diagnostic'];
    $fr = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $money = fn ($n) => $n >= 1_000_000 ? number_format($n / 1_000_000, 1, ',', ' ').' M F' : $fr($n).' F';
    $monogram = \Illuminate\Support\Str::of($sc['name'])->explode(' ')->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
    $ago = function ($d) {
        if (! $d) {
            return '—';
        }
        $days = (int) \Illuminate\Support\Carbon::parse($d)->diffInDays(now());

        return $days === 0 ? "aujourd'hui" : ($days === 1 ? 'hier' : ($days < 30 ? "il y a {$days} j" : 'il y a '.intdiv($days, 30).' mois'));
    };
    $dateFr = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->locale('fr')->isoFormat('D MMM YYYY') : '—';

    $kpiCards = [
        ['Élèves', $fr($k['students']), 'eleves'],
        ['Numéros de parents', $fr($k['known']), 'parents'],
        ['Comptes créés', $fr($k['inscrits']), 'inscrits'],
        ['Parents adoptants', $fr($k['actifs']), 'actifs'],
        ['Parents abonnés', $k['abonnes'] > 0 ? $fr($k['abonnes']) : '—', 'sub'],
        ['Paiements réalisés', $fr($k['paymentsCount']), 'pay'],
        ["Chiffre d'affaires", $money($k['revenue']), 'ca'],
        ['Potentiel restant', $k['potential'] > 0 ? $money($k['potential']) : '—', 'potential'],
    ];
    $prio = [
        'Critique' => ['#B91C1C', '#FDECEC'], 'Élevée' => ['#B45F04', '#FEF3E2'],
        'Moyenne' => ['#1D3F9C', '#EEF3FE'], 'Faible' => ['#5B6472', '#F2F3F5'],
    ];
    $impactColor = ['Élevé' => '#0F7A44', 'Moyen' => '#B45F04', 'Faible' => '#5B6472'];
    $tabs = ['overview' => "Vue d'ensemble", 'parents' => 'Parents', 'paiements' => 'Paiements', 'abonnements' => 'Abonnements', 'campagnes' => 'Campagnes', 'rapports' => 'Rapports', 'historique' => 'Historique'];
@endphp

<div class="mx-auto max-w-[1480px]" x-data="{ tab: 'overview' }">

    {{-- Fil d'Ariane --}}
    <nav class="mb-4 flex items-center gap-1.5 text-[12.5px] text-ink-500">
        <a href="{{ route('dashboard.index') }}" wire:navigate class="hover:text-ink-800">Dashboard</a>
        <span>/</span>
        <a href="{{ route('schools.index') }}" wire:navigate class="hover:text-ink-800">Écoles</a>
        <span>/</span>
        <span class="font-semibold text-ink-800">{{ $sc['name'] }}</span>
    </nav>

    {{-- En-tête établissement --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4"
         x-data="{ editGeo: false, saved: false, mapped: false }"
         @school-geo-saved.window="editGeo = false; mapped = $event.detail.mapped; saved = true; clearTimeout(window._geo); window._geo = setTimeout(() => saved = false, 4000)">
        <div class="flex items-start gap-4">
            <span class="flex h-16 w-16 flex-shrink-0 items-center justify-center rounded-2xl bg-brand-50 text-[20px] font-bold text-brand-700">{{ $monogram }}</span>
            <div>
                <h1 class="text-[24px] font-bold tracking-tight text-ink-900">{{ $sc['name'] }}</h1>
                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-ink-500">
                    <span class="font-mono">{{ $sc['code'] ?: '—' }}</span>
                    <span>·</span>
                    <span>{{ $sc['city'] ?: 'Ville —' }}@if ($sc['region']), {{ $sc['region'] }}@else <span class="text-ink-400">(géo à renseigner)</span>@endif</span>
                    <button @click="editGeo = ! editGeo" class="inline-flex items-center gap-1 text-brand-600 hover:text-brand-700" title="Modifier la localisation">
                        <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M4 13.5V16h2.5l7-7L11 6.5zM12.5 5l1.2-1.2a1.4 1.4 0 012 2L14.5 7z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                        <span class="text-[12px] font-semibold">Modifier</span>
                    </button>
                    <span x-show="saved" x-cloak x-transition class="inline-flex items-center gap-1 text-[12px] font-semibold text-[#0F7A44]">
                        <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M4 10l4 4 8-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span x-text="mapped ? 'Localisation enregistrée · placée sur la carte' : 'Localisation enregistrée · hors répertoire carte'"></span>
                    </span>
                </div>

                {{-- Éditeur de localisation --}}
                <div x-show="editGeo" x-cloak x-transition class="mt-3 flex flex-wrap items-end gap-2.5 rounded-[12px] border border-ink-200 bg-ink-50 p-3">
                    <label class="block">
                        <span class="mb-1 block text-[11.5px] font-semibold text-ink-600">Ville</span>
                        <input type="text" wire:model="geoCity" placeholder="Ex. Abidjan, Bouaké…" class="eac-input w-44 bg-white">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-[11.5px] font-semibold text-ink-600">Commune / quartier</span>
                        <input type="text" wire:model="geoCommune" placeholder="Ex. Cocody, Marcory…" class="eac-input w-48 bg-white">
                    </label>
                    <button wire:click="saveLocation" wire:loading.attr="disabled" wire:target="saveLocation" class="rounded-[9px] bg-brand-600 px-3.5 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700 disabled:opacity-60">Enregistrer</button>
                    <button type="button" @click="editGeo = false" class="rounded-[9px] px-3 py-2 text-[12.5px] font-semibold text-ink-500 hover:bg-ink-100">Annuler</button>
                    <p class="w-full text-[11px] text-ink-400">La commune (ex. quartiers d'Abidjan) permet un placement plus précis sur la carte de répartition.</p>
                </div>
                <div class="mt-2.5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2.5 py-0.5 text-[11.5px] font-semibold text-success">
                        <span class="h-1.5 w-1.5 rounded-full bg-success"></span>Active
                    </span>
                    <span class="rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold" style="background: {{ $h['bg'] }}; color: {{ $h['color'] }}">Santé {{ $h['score'] }}/100</span>
                    <span class="rounded-full bg-ink-100 px-2.5 py-0.5 text-[11.5px] font-medium text-ink-600">{{ $sc['subscriptionModel'] === 'parent_paid' ? 'Abonnement parent' : 'Abonnement inclus' }}</span>
                    <span class="text-[11.5px] text-ink-400">Synchro {{ $ago($sc['syncedAt']) }}</span>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button wire:click="exportReport" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 3v9m0 0l-3.2-3.2M10 12l3.2-3.2M4 15.5h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Exporter le rapport
            </button>
            <a href="{{ route('parents.index') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">Voir les parents</a>
            <button disabled title="Module Campagnes à venir" class="inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg border border-dashed border-ink-200 bg-ink-50 px-3 py-2 text-[12.5px] font-medium text-ink-400">Lancer une campagne <span class="rounded bg-ink-100 px-1 text-[9px] font-bold uppercase">à venir</span></button>
            <button disabled title="Édition à venir" class="inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg border border-dashed border-ink-200 bg-ink-50 px-3 py-2 text-[12.5px] font-medium text-ink-400">Modifier</button>
        </div>
    </div>

    {{-- Onglets --}}
    <div class="mb-6 flex flex-wrap items-center gap-1 border-b border-ink-200">
        @foreach ($tabs as $key => $label)
            <button @click="tab = '{{ $key }}'"
                    class="relative -mb-px px-3.5 py-2.5 text-[13.5px] font-semibold transition-colors"
                    :class="tab === '{{ $key }}' ? 'text-brand-700' : 'text-ink-500 hover:text-ink-900'">
                {{ $label }}
                <span x-show="tab === '{{ $key }}'" class="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-brand-600"></span>
            </button>
        @endforeach
    </div>

    {{-- ══════════ VUE D'ENSEMBLE ══════════ --}}
    <div x-show="tab === 'overview'" class="flex flex-col gap-8">

        {{-- Résumé exécutif --}}
        <div class="overflow-hidden rounded-[20px] border border-brand-200 bg-white shadow-[0_4px_24px_rgba(23,60,130,0.06)]">
            <div class="flex flex-wrap items-stretch">
                <div class="flex flex-[1_1_100%] flex-col gap-4 p-6 lg:flex-[2_1_520px]">
                    <div class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.09em] text-brand-700">
                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.4"/><circle cx="10" cy="10" r="2.4" fill="currentColor"/></svg>
                        Situation actuelle
                    </div>
                    <div class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
                        <div>
                            <div class="text-[28px] font-extrabold tracking-tight" style="color: {{ $h['color'] }}">{{ number_format($k['rate'], 1, ',', ' ') }} %</div>
                            <div class="text-[12px] text-ink-500">Taux d'adoption</div>
                        </div>
                        <div>
                            <div class="text-[28px] font-extrabold tracking-tight" style="color: {{ $h['color'] }}">{{ $h['score'] }}<span class="text-[15px] text-ink-400">/100</span></div>
                            <div class="text-[12px] text-ink-500">Score de santé</div>
                        </div>
                        <div>
                            <div class="text-[28px] font-extrabold tracking-tight text-ink-900">{{ $fr($k['actifs']) }}</div>
                            <div class="text-[12px] text-ink-500">Parents adoptants</div>
                        </div>
                        <div>
                            <div class="text-[22px] font-bold tracking-tight text-ink-900">{{ $fr($k['nonAdopters']) }}</div>
                            <div class="text-[12px] text-ink-500">Parents à convertir</div>
                        </div>
                        <div>
                            <div class="text-[22px] font-bold tracking-tight text-ink-900">{{ $money($k['revenue']) }}</div>
                            <div class="text-[12px] text-ink-500">Revenu généré</div>
                        </div>
                        <div>
                            <div class="text-[22px] font-bold tracking-tight text-success">{{ $k['potential'] > 0 ? $money($k['potential']) : '—' }}</div>
                            <div class="text-[12px] text-ink-500">Revenu potentiel</div>
                        </div>
                    </div>
                </div>
                <div class="flex flex-[1_1_100%] items-center border-t border-ink-150 bg-ink-50 p-6 lg:flex-[1_1_260px] lg:border-l lg:border-t-0">
                    <p class="text-[13.5px] leading-relaxed text-ink-700"><span class="font-semibold text-ink-900">En bref.</span> {{ $dg['headline'] }}. {{ $k['potential'] > 0 ? 'Potentiel restant ≈ '.$money($k['potential']).'.' : '' }}</p>
                </div>
            </div>
        </div>

        {{-- Diagnostic d'adoption --}}
        @php $dgTarget = $dg['targetParents']; @endphp
        <div>
            <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Diagnostic d'adoption</div>
            <div class="overflow-hidden rounded-[18px] border bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]" style="border-color: {{ $dg['color'] }}30">
                <div class="flex items-center gap-2.5 border-b px-6 py-3.5" style="background: {{ $dg['bg'] }}; border-color: {{ $dg['color'] }}20">
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" style="color: {{ $dg['color'] }}"><path d="M10 2.5a5.5 5.5 0 00-3 10.1V15h6v-2.4a5.5 5.5 0 00-3-10.1z" stroke="currentColor" stroke-width="1.5"/><path d="M8 17h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    <span class="text-[14px] font-bold" style="color: {{ $dg['color'] }}">{{ $dg['headline'] }}</span>
                    <span class="ml-auto rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide" style="background: white; color: {{ $dg['color'] }}">{{ $dg['tone'] }}</span>
                </div>
                <div class="grid grid-cols-1 gap-6 p-6 lg:grid-cols-[1.6fr_1fr]">
                    <div>
                        {{-- Les deux taux clés de l'entonnoir --}}
                        <div class="mb-4 grid grid-cols-2 gap-3">
                            @foreach ([['Taux d\'inscription', $dg['registrationRate'], $dg['registrationLabel'], 'des parents connus créent un compte'], ['Taux d\'activation', $dg['activationRate'], $dg['activationLabel'], 'des inscrits effectuent un paiement']] as [$lbl, $val, $qual, $desc])
                                @php $qc = $val >= 55 ? '#0F7A44' : ($val >= 35 ? '#B45F04' : '#B91C1C'); @endphp
                                <div class="rounded-xl border border-ink-150 p-3.5">
                                    <div class="flex items-baseline justify-between">
                                        <span class="text-[12px] font-semibold text-ink-700">{{ $lbl }}</span>
                                        <span class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase" style="color: {{ $qc }}; background: {{ $qc }}14">{{ $qual }}</span>
                                    </div>
                                    <div class="mt-1.5 text-[24px] font-extrabold tracking-tight" style="color: {{ $qc }}">{{ number_format($val, 0, ',', ' ') }} %</div>
                                    <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-ink-100">
                                        <div class="h-full rounded-full" style="width: {{ min(100, $val) }}%; background: {{ $qc }}"></div>
                                    </div>
                                    <div class="mt-1.5 text-[11px] text-ink-500">{{ $desc }}</div>
                                </div>
                            @endforeach
                        </div>
                        <p class="text-[13.5px] leading-relaxed text-ink-700">{{ $dg['text'] }}</p>
                    </div>

                    {{-- Gain atteignable + levier --}}
                    <div class="flex flex-col justify-between gap-4 rounded-2xl p-5" style="background: {{ $dg['bg'] }}">
                        <div>
                            <div class="text-[11px] font-bold uppercase tracking-wide" style="color: {{ $dg['color'] }}">Gain atteignable</div>
                            <div class="mt-2 text-[32px] font-extrabold leading-none text-ink-900">{{ $fr($dgTarget) }}</div>
                            <div class="text-[12.5px] text-ink-600">{{ $dg['bottleneck'] === 'activation' ? 'inscrits à convertir' : 'parents à gagner' }}</div>
                            @if ($dg['annualRevenue'] > 0)
                                <div class="mt-3 border-t pt-3" style="border-color: {{ $dg['color'] }}20">
                                    <div class="text-[22px] font-bold" style="color: {{ $dg['color'] }}">≈ {{ $money($dg['annualRevenue']) }}</div>
                                    <div class="text-[12px] text-ink-600">de revenus annuels estimés</div>
                                </div>
                            @endif
                        </div>
                        <div>
                            <div class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-ink-500">Levier prioritaire</div>
                            <div class="flex items-start gap-2 rounded-xl bg-white/70 px-3 py-2.5">
                                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" class="mt-0.5 flex-shrink-0" style="color: {{ $dg['color'] }}"><path d="M4 10.5l3.5 3.5L16 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <span class="text-[13px] font-semibold text-ink-900">{{ $dg['lever'] }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- KPI détaillés --}}
        <div>
            <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Indicateurs détaillés</div>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach ($kpiCards as [$label, $value, $ic])
                    <div class="rounded-[13px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                        <div class="flex h-8 w-8 items-center justify-center rounded-[9px] bg-ink-100 text-ink-700">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="6.5" stroke="currentColor" stroke-width="1.5"/></svg>
                        </div>
                        <div class="mt-3 text-[20px] font-bold tracking-tight text-ink-900">{{ $value }}</div>
                        <div class="text-[12px] text-ink-500">{{ $label }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Analyse de l'adoption : area + funnel --}}
        <div>
            <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Analyse de l'adoption</div>
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                    <div class="text-[15px] font-semibold text-ink-900">Évolution du taux d'adoption</div>
                    <div class="mb-3 text-[12px] text-ink-500">Adoptants cumulés / parents connus · repère métier annoté</div>
                    <div wire:ignore><div id="chart-school-adoption" class="h-[260px] w-full"></div></div>
                </div>
                <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                    <div class="mb-4 text-[15px] font-semibold text-ink-900">Entonnoir d'adoption</div>
                    @php $funnelMax = max(1, $p['funnel'][0]['value']); @endphp
                    <div class="flex flex-col gap-2.5">
                        @foreach ($p['funnel'] as $i => $stage)
                            <div>
                                <div class="mb-1 flex items-center justify-between text-[12.5px]">
                                    <span class="font-medium text-ink-800">{{ $stage['label'] }}</span>
                                    <span class="font-mono font-semibold text-ink-900">{{ $fr($stage['value']) }}@if ($stage['conv'] !== null)<span class="ml-1.5 text-[11px] font-normal text-ink-400">{{ number_format($stage['conv'], 0, ',', ' ') }} %</span>@endif</span>
                                </div>
                                <div class="h-6 overflow-hidden rounded-md bg-ink-100">
                                    <div class="flex h-full items-center rounded-md" style="width: {{ max(3, round($stage['value'] / $funnelMax * 100)) }}%; background: linear-gradient(90deg, #2554C7, #4E7DE0); opacity: {{ 1 - $i * 0.12 }}"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Analyse des campagnes (à venir) --}}
        <div>
            <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Analyse des campagnes</div>
            <div class="flex flex-col items-center gap-2 rounded-[16px] border border-dashed border-ink-300 bg-white py-12 text-center">
                <svg width="30" height="30" viewBox="0 0 20 20" fill="none" class="text-ink-300"><rect x="2.5" y="4" width="15" height="10" rx="3" stroke="currentColor" stroke-width="1.5"/><polygon points="6,14 6,18 10,14" fill="currentColor"/></svg>
                <div class="text-[14px] font-semibold text-ink-800">Module Campagnes à venir</div>
                <div class="max-w-md text-[12.5px] text-ink-500">Le suivi des campagnes WhatsApp (contacts ciblés, nouveaux comptes, conversion) apparaîtra ici une fois le module Campagnes connecté.</div>
            </div>
        </div>

        {{-- Répartition + Opportunités --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="mb-1 text-[15px] font-semibold text-ink-900">Répartition des parents</div>
                <div class="mb-3 text-[12px] text-ink-500">Parcours de la base connue de l'école</div>
                <div class="flex flex-col items-center gap-4 sm:flex-row">
                    <div class="w-full max-w-[200px] flex-shrink-0" wire:ignore><div id="chart-school-donut" class="h-[180px] w-full"></div></div>
                    @php $repTotal = max(1, collect($p['repartition'])->sum('value')); @endphp
                    <div class="flex w-full flex-col gap-2.5">
                        @foreach ($p['repartition'] as $seg)
                            <div class="flex items-center gap-2 text-[12.5px]">
                                <span class="h-2.5 w-2.5 flex-shrink-0 rounded-full" style="background: {{ $seg['color'] }}"></span>
                                <span class="flex-1 text-ink-700">{{ $seg['label'] }}</span>
                                <span class="font-mono font-semibold text-ink-900">{{ $fr($seg['value']) }}</span>
                                <span class="w-10 text-right text-[11px] text-ink-400">{{ round($seg['value'] / $repTotal * 100) }} %</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="mb-4 flex items-center justify-between">
                    <div class="text-[15px] font-semibold text-ink-900">Opportunités de croissance</div>
                    @php [$pfg, $pbg] = $prio[$p['opportunities']['priority']] ?? $prio['Moyenne']; @endphp
                    <span class="rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold" style="background: {{ $pbg }}; color: {{ $pfg }}">Priorité {{ $p['opportunities']['priority'] }}</span>
                </div>
                <div class="mb-4 grid grid-cols-3 gap-2">
                    <div class="rounded-xl bg-ink-50 px-3 py-2.5">
                        <div class="text-[17px] font-bold text-ink-900">{{ $fr($p['opportunities']['nonInscrits']) }}</div>
                        <div class="text-[11px] text-ink-500">non inscrits</div>
                    </div>
                    <div class="rounded-xl bg-ink-50 px-3 py-2.5">
                        <div class="text-[17px] font-bold text-ink-900">{{ $fr($p['opportunities']['inscritsInactifs']) }}</div>
                        <div class="text-[11px] text-ink-500">inscrits inactifs</div>
                    </div>
                    <div class="rounded-xl bg-ink-50 px-3 py-2.5">
                        <div class="text-[17px] font-bold text-success">{{ $p['opportunities']['potential'] > 0 ? $money($p['opportunities']['potential']) : '—' }}</div>
                        <div class="text-[11px] text-ink-500">potentiel</div>
                    </div>
                </div>
                <div class="flex flex-col gap-2">
                    @foreach ($p['opportunities']['actions'] as $a)
                        <div class="rounded-xl border border-ink-150 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[13px] font-semibold text-ink-900">{{ $a['title'] }}</span>
                            </div>
                            <div class="mt-1 text-[12px] text-ink-600">{{ $a['why'] }}</div>
                            <div class="mt-2 flex items-center gap-3 text-[11px]">
                                <span class="text-ink-500">Impact <span class="font-semibold" style="color: {{ $impactColor[$a['impact']] ?? '#5B6472' }}">{{ $a['impact'] }}</span></span>
                                <span class="text-ink-500">Difficulté <span class="font-semibold text-ink-700">{{ $a['difficulty'] }}</span></span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- ══════════ HISTORIQUE ══════════ --}}
    <div x-show="tab === 'historique'" x-cloak class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-5 text-[15px] font-semibold text-ink-900">Historique des performances</div>
            <div class="flex flex-col">
                @foreach ($p['timeline'] as $t)
                    <div class="relative flex gap-3.5 pb-5 last:pb-0 {{ $t['available'] ? '' : 'opacity-45' }}">
                        @if (! $loop->last)<span class="absolute left-[13px] top-7 bottom-0 w-px bg-ink-150"></span>@endif
                        <span class="relative z-10 mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full {{ $t['available'] ? 'bg-brand-50 text-brand-600' : 'bg-ink-100 text-ink-400' }}">
                            <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="3" fill="currentColor"/></svg>
                        </span>
                        <div>
                            <div class="text-[13.5px] font-semibold text-ink-900">{{ $t['label'] }}</div>
                            <div class="text-[12px] text-ink-500">{{ $t['available'] ? $dateFr($t['date']) : 'non disponible (module/donnée à venir)' }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-5 text-[15px] font-semibold text-ink-900">Activité récente</div>
            @if (count($p['recent']))
                <div class="flex flex-col gap-3">
                    @foreach ($p['recent'] as $act)
                        <div class="flex items-center gap-3">
                            <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full {{ $act['type'] === 'first' ? 'bg-success-soft text-success' : 'bg-brand-50 text-brand-600' }}">
                                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M5 10.5l3 3 7-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="text-[13px] font-medium text-ink-900">{{ $act['label'] }}</div>
                                <div class="text-[11.5px] text-ink-500">{{ $dateFr($act['date']) }}</div>
                            </div>
                            <div class="font-mono text-[12.5px] font-semibold text-ink-800">{{ $money($act['amount']) }}</div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="py-8 text-center text-[13px] text-ink-500">Aucune activité de paiement enregistrée.</div>
            @endif
        </div>
    </div>

    @php
        $channelLabels = ['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'push' => 'Push', 'social' => 'Réseaux sociaux', 'field' => 'Terrain', 'voice' => 'Appel', 'open_house' => 'Portes ouvertes'];
        $campStatus = ['completed' => ['Terminée', '#0F7A44', '#E7F6EE'], 'running' => ['En cours', '#1D3F9C', '#EEF3FE'], 'scheduled' => ['Planifiée', '#B45F04', '#FEF3E2'], 'draft' => ['Brouillon', '#5B6472', '#F2F3F5']];
    @endphp

    {{-- ══════════ PARENTS ══════════ --}}
    @php $tp = $this->tabParents; @endphp
    <div x-show="tab === 'parents'" x-cloak class="rounded-[16px] border border-ink-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-150 px-5 py-4">
            <div>
                <div class="text-[14px] font-bold text-ink-900">Parents de l'établissement</div>
                <div class="text-[12px] text-ink-500">{{ $fr($tp['total']) }} parent(s) rattaché(s) à cette école.</div>
            </div>
            <a href="{{ route('parents.index', ['school' => $sc['id']]) }}" wire:navigate class="rounded-lg bg-brand-600 px-3.5 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700">Ouvrir dans l'explorateur</a>
        </div>
        @if ($tp['rows']->isEmpty())
            <div class="py-14 text-center text-[13px] text-ink-500">Aucun parent rattaché pour le moment.</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-[12.5px]">
                    <thead class="border-b border-ink-150 text-[11px] font-bold uppercase tracking-wide text-ink-400">
                        <tr><th class="px-5 py-2.5">Parent</th><th class="px-3 py-2.5">Téléphone</th><th class="px-3 py-2.5">Statut</th><th class="px-3 py-2.5 text-right">Paiements</th><th class="px-5 py-2.5">Compte</th></tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($tp['rows'] as $r)
                            @php $lc = ParentLifecycle::of((bool) $r->account_created_at, (bool) $r->has_ever_paid, $r->successful_payment_count >= 2); @endphp
                            <tr class="hover:bg-ink-50">
                                <td class="px-5 py-2.5">
                                    @if ($r->id)
                                        <a href="{{ route('parents.show', $r->id) }}" wire:navigate class="inline-flex items-center gap-1.5 font-semibold text-brand-700 hover:underline">
                                            {{ $r->full_name ?: 'Parent' }}
                                            <svg width="12" height="12" viewBox="0 0 20 20" fill="none" class="text-ink-300"><path d="M7 4l6 6-6 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </a>
                                    @else
                                        <span class="font-semibold text-ink-900">{{ $r->full_name ?: 'Parent' }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 font-mono text-ink-600">{{ $r->phone_e164 ?: '—' }}</td>
                                <td class="px-3 py-2.5"><span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background: {{ $lc['bg'] }}; color: {{ $lc['color'] }}">{{ $lc['star'] ? '⭐ ' : '' }}{{ $lc['label'] }}</span></td>
                                <td class="px-3 py-2.5 text-right font-semibold text-ink-800">{{ $fr($r->successful_payment_count) }}</td>
                                <td class="px-5 py-2.5 text-ink-500">{{ $r->account_created_at ? 'Inscrit' : 'Non inscrit' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($tp['total'] > $tp['rows']->count())
                <div class="border-t border-ink-100 px-5 py-3 text-center text-[12px] text-ink-500">Affichage des {{ $tp['rows']->count() }} premiers · <a href="{{ route('parents.index', ['school' => $sc['id']]) }}" wire:navigate class="font-semibold text-brand-600 hover:underline">voir les {{ $fr($tp['total']) }} parents</a></div>
            @endif
        @endif
    </div>

    {{-- ══════════ PAIEMENTS ══════════ --}}
    @php $tpay = $this->tabPayments; @endphp
    <div x-show="tab === 'paiements'" x-cloak class="flex flex-col gap-4">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div class="rounded-[13px] border border-ink-200 bg-white p-4"><div class="text-[12px] text-ink-500">Paiements réussis</div><div class="mt-1 text-[20px] font-bold text-ink-900">{{ $fr($tpay['total']) }}</div></div>
            <div class="rounded-[13px] border border-ink-200 bg-white p-4"><div class="text-[12px] text-ink-500">Revenu via l'app</div><div class="mt-1 text-[20px] font-bold text-ink-900">{{ $money($tpay['revenue']) }}</div></div>
            <div class="rounded-[13px] border border-ink-200 bg-white p-4"><div class="text-[12px] text-ink-500">Dont saisis manuellement</div><div class="mt-1 text-[20px] font-bold text-ink-900">{{ $fr($tpay['manual']) }}</div></div>
        </div>
        <div class="rounded-[16px] border border-ink-200 bg-white">
            <div class="border-b border-ink-150 px-5 py-3 text-[12.5px] font-semibold text-ink-700">Historique des paiements réussis</div>
            @if ($tpay['rows']->isEmpty())
                <div class="py-14 text-center text-[13px] text-ink-500">Aucun paiement réussi enregistré pour cette école.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-[12.5px]">
                        <thead class="border-b border-ink-150 text-[11px] font-bold uppercase tracking-wide text-ink-400">
                            <tr><th class="px-5 py-2.5">Date</th><th class="px-3 py-2.5">Parent</th><th class="px-3 py-2.5 text-right">Montant</th><th class="px-3 py-2.5">Méthode</th><th class="px-5 py-2.5">Type</th></tr>
                        </thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($tpay['rows'] as $r)
                                <tr class="hover:bg-ink-50">
                                    <td class="px-5 py-2.5 text-ink-700">{{ $dateFr($r->paid_at) }}</td>
                                    <td class="px-3 py-2.5">@if ($r->parent_id)<a href="{{ route('parents.show', $r->parent_id) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $r->full_name ?: 'Parent' }}</a>@else <span class="text-ink-800">{{ $r->full_name ?: '—' }}</span>@endif</td>
                                    <td class="px-3 py-2.5 text-right font-semibold text-ink-900">{{ $money($r->amount) }}</td>
                                    <td class="px-3 py-2.5 text-ink-600">{{ $r->method ?: '—' }}@if ($r->is_manual) <span class="ml-1 rounded bg-ink-100 px-1 text-[9.5px] font-bold uppercase text-ink-500">manuel</span>@endif</td>
                                    <td class="px-5 py-2.5 text-ink-600">{{ $r->is_first_payment ? '1ᵉʳ paiement' : 'Renouvellement' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($tpay['total'] > $tpay['rows']->count())
                    <div class="border-t border-ink-100 px-5 py-3 text-center text-[12px] text-ink-500">Affichage des {{ $tpay['rows']->count() }} paiements les plus récents sur {{ $fr($tpay['total']) }} réussis.</div>
                @endif
            @endif
        </div>
    </div>

    {{-- ══════════ ABONNEMENTS ══════════ --}}
    @php $tsub = $this->tabSubscriptions; $pp = $sc['subscriptionModel'] === 'parent_paid'; @endphp
    <div x-show="tab === 'abonnements'" x-cloak class="flex flex-col gap-4">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-[13px] border border-ink-200 bg-white p-4"><div class="text-[12px] text-ink-500">Modèle d'abonnement</div><div class="mt-1 text-[15px] font-bold text-ink-900">{{ $pp ? 'Parent payant' : 'Inclus (intégré)' }}</div></div>
            <div class="rounded-[13px] border border-ink-200 bg-white p-4"><div class="text-[12px] text-ink-500">Montant unitaire</div><div class="mt-1 text-[15px] font-bold text-ink-900">{{ $sc['subscriptionAmount'] > 0 ? $money($sc['subscriptionAmount']) : '—' }}</div></div>
            <div class="rounded-[13px] border border-ink-200 bg-white p-4"><div class="text-[12px] text-ink-500">Parents abonnés</div><div class="mt-1 text-[20px] font-bold text-ink-900">{{ $pp ? $fr($tsub['subscribers']) : '—' }}</div></div>
            <div class="rounded-[13px] border border-ink-200 bg-white p-4"><div class="text-[12px] text-ink-500">Revenu abonnement</div><div class="mt-1 text-[20px] font-bold text-ink-900">{{ $tsub['subRevenue'] > 0 ? $money($tsub['subRevenue']) : '—' }}</div></div>
        </div>
        <div class="rounded-[16px] border border-ink-200 bg-white p-5">
            @if ($pp)
                <p class="text-[13px] leading-relaxed text-ink-700">Dans cette école, <strong>le parent paie l'abonnement</strong> ({{ $money($sc['subscriptionAmount']) }} par abonnement). {{ $fr($tsub['subscribers']) }} parent(s) ont réglé un abonnement via l'app, pour un revenu d'abonnement de {{ $money($tsub['subRevenue']) }}. Le potentiel restant est de {{ $k['potential'] > 0 ? $money($k['potential']) : '0' }}.</p>
            @else
                <p class="text-[13px] leading-relaxed text-ink-700">Cette école est en <strong>abonnement intégré</strong> : le parent ne paie pas d'abonnement individuel, il n'y a donc pas de revenu d'abonnement parent à capter ici. L'adoption reste mesurée par les premiers paiements via l'app.</p>
            @endif
            <div class="mt-3 flex items-start gap-2 rounded-[11px] bg-ink-50 px-3.5 py-3 text-[12px] text-ink-600">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" class="mt-0.5 flex-shrink-0 text-ink-400"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.4"/><path d="M10 9v4M10 6.5h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                <span>Les abonnements sont lus via les paiements (portion abonnement). Le détail par abonnement — dates de début/fin, statut — arrivera avec la synchronisation de la table d'abonnements EcolePay.</span>
            </div>
        </div>
    </div>

    {{-- ══════════ CAMPAGNES ══════════ --}}
    @php $tcamp = $this->tabCampaigns; @endphp
    <div x-show="tab === 'campagnes'" x-cloak class="rounded-[16px] border border-ink-200 bg-white">
        <div class="flex items-center justify-between border-b border-ink-150 px-5 py-4">
            <div class="text-[14px] font-bold text-ink-900">Opérations marketing de l'école</div>
            <a href="{{ route('campaigns.index') }}" wire:navigate class="rounded-lg border border-ink-200 px-3.5 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">Module Campagnes</a>
        </div>
        @if ($tcamp->isEmpty())
            <div class="flex flex-col items-center gap-2 py-14 text-center">
                <div class="text-[13.5px] font-semibold text-ink-800">Aucune opération pour cette école</div>
                <p class="max-w-sm text-[12px] text-ink-500">Importez une opération marketing et mesurez son impact sur l'adoption.</p>
                <a href="{{ route('campaigns.index') }}" wire:navigate class="mt-1 rounded-lg bg-brand-600 px-4 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700">Nouvelle opération</a>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-[12.5px]">
                    <thead class="border-b border-ink-150 text-[11px] font-bold uppercase tracking-wide text-ink-400">
                        <tr><th class="px-5 py-2.5">Opération</th><th class="px-3 py-2.5">Canal</th><th class="px-3 py-2.5">Date</th><th class="px-3 py-2.5 text-right">Contacts</th><th class="px-5 py-2.5">Statut</th></tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($tcamp as $c)
                            @php
                                $ch = is_object($c->channel) ? ($c->channel->value ?? '') : $c->channel;
                                [$clbl, $cfg, $cbg] = $campStatus[is_object($c->status) ? $c->status->value : $c->status] ?? [$c->status, '#5B6472', '#F2F3F5'];
                            @endphp
                            <tr class="hover:bg-ink-50">
                                <td class="px-5 py-2.5"><a href="{{ route('campaigns.show', $c->id) }}" wire:navigate class="font-semibold text-brand-700 hover:underline">{{ $c->name }}</a></td>
                                <td class="px-3 py-2.5 text-ink-600">{{ $channelLabels[$ch] ?? ($ch ?: '—') }}</td>
                                <td class="px-3 py-2.5 text-ink-600">{{ $dateFr($c->campaign_date) }}</td>
                                <td class="px-3 py-2.5 text-right text-ink-700">{{ $c->valid_count !== null ? $fr($c->valid_count) : '—' }}</td>
                                <td class="px-5 py-2.5"><span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background: {{ $cbg }}; color: {{ $cfg }}">{{ $clbl }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ══════════ RAPPORTS ══════════ --}}
    @php $trep = $this->tabReports; @endphp
    <div x-show="tab === 'rapports'" x-cloak class="rounded-[16px] border border-ink-200 bg-white">
        <div class="flex items-center justify-between border-b border-ink-150 px-5 py-4">
            <div class="text-[14px] font-bold text-ink-900">Rapports de l'école</div>
            <div class="flex items-center gap-2">
                <button wire:click="exportReport" class="rounded-lg border border-ink-200 px-3.5 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">Export CSV rapide</button>
                <a href="{{ route('reports.index') }}" wire:navigate class="rounded-lg bg-brand-600 px-3.5 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700">Générer un rapport</a>
            </div>
        </div>
        @if ($trep->isEmpty())
            <div class="flex flex-col items-center gap-2 py-14 text-center">
                <div class="text-[13.5px] font-semibold text-ink-800">Aucun rapport enregistré pour cette école</div>
                <p class="max-w-sm text-[12px] text-ink-500">Générez un rapport dédié depuis le module Rapports, ou exportez les indicateurs en CSV.</p>
            </div>
        @else
            <div class="divide-y divide-ink-100">
                @foreach ($trep as $r)
                    <a href="{{ route('reports.show', $r->id) }}" wire:navigate class="flex items-center justify-between gap-3 px-5 py-3.5 hover:bg-ink-50">
                        <div class="min-w-0"><div class="truncate text-[13.5px] font-semibold text-ink-900">{{ $r->name }}</div><div class="text-[11.5px] text-ink-500">{{ ucfirst($r->type ?? 'rapport') }} · {{ $r->period ?: 'période libre' }}</div></div>
                        <div class="flex-shrink-0 text-[11.5px] text-ink-400">{{ $r->last_generated_at ? $dateFr($r->last_generated_at) : $dateFr($r->created_at) }}</div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>

@script
<script>
    (() => {
        if (! window.echarts) return;
        const p = @js($this->profile);
        const g = window.echarts.graphic;
        const inter = 'Inter';
        const axisLabel = { color: '#B7BCC5', fontFamily: inter, fontSize: 11 };

        const adoptionEl = document.getElementById('chart-school-adoption');
        if (adoptionEl) {
            const c = window.echarts.init(adoptionEl);
            const marks = (p.adoption.events || []).map(e => ({
                xAxis: e.index,
                label: { formatter: e.label, color: '#173C82', fontFamily: inter, fontSize: 10, fontWeight: 600, position: 'insideEndTop', backgroundColor: '#EEF3FE', padding: [3, 5], borderRadius: 4 },
                lineStyle: { color: '#2554C7', type: 'dashed', width: 1.2, opacity: 0.5 },
            }));
            c.setOption({
                grid: { left: 40, right: 20, top: 20, bottom: 26 },
                tooltip: { trigger: 'axis', valueFormatter: v => v + ' %' },
                xAxis: { type: 'category', boundaryGap: false, data: p.adoption.labels, axisTick: { show: false }, axisLine: { lineStyle: { color: '#E7E9ED' } }, axisLabel },
                yAxis: { type: 'value', splitLine: { lineStyle: { color: '#F0F1F3' } }, axisLabel: { ...axisLabel, formatter: '{value} %' } },
                series: [{
                    type: 'line', data: p.adoption.rate, smooth: 0.4, showSymbol: false,
                    lineStyle: { color: '#2554C7', width: 3, cap: 'round' }, itemStyle: { color: '#2554C7' },
                    areaStyle: { color: new g.LinearGradient(0, 0, 0, 1, [{ offset: 0, color: 'rgba(37,84,199,0.28)' }, { offset: 1, color: 'rgba(37,84,199,0.01)' }]) },
                    markLine: marks.length ? { symbol: 'none', silent: true, data: marks } : undefined,
                    animationDuration: 800,
                }],
            });
        }

        const donutEl = document.getElementById('chart-school-donut');
        if (donutEl) {
            const c = window.echarts.init(donutEl);
            const total = p.repartition.reduce((a, s) => a + s.value, 0);
            c.setOption({
                tooltip: { trigger: 'item', formatter: '{b}<br/>{c} ({d}%)' },
                series: [{
                    type: 'pie', radius: ['58%', '84%'], center: ['50%', '50%'],
                    label: { show: true, position: 'center', formatter: () => 'Parents\n' + total.toLocaleString('fr-FR'), fontFamily: inter, fontSize: 12, fontWeight: 600, color: '#14181f', lineHeight: 16 },
                    labelLine: { show: false }, itemStyle: { borderColor: '#fff', borderWidth: 2 },
                    data: p.repartition.map(s => ({ name: s.label, value: s.value, itemStyle: { color: s.color } })),
                }],
            });
        }
    })();
</script>
@endscript
