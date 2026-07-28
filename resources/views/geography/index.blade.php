<?php

use App\Domains\Schools\Actions\ListSchoolsForPilotage;
use App\Domains\Schools\Support\IvorianGazetteer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    // Métrique de coloration : adoption | potential.
    #[Url]
    public string $metric = 'adoption';

    // Région mise en avant (clic sur une ligne du tableau).
    #[Url]
    public string $region = '';

    // Cadre géographique de la Côte d'Ivoire.
    private const LNG_MIN = -8.8;

    private const LNG_MAX = -2.4;

    private const LAT_MIN = 4.1;

    private const LAT_MAX = 10.9;

    private const W = 760;

    private const H = 660;

    #[Computed]
    public function located(): Collection
    {
        // Géo saisie manuellement (fiche école) — fait autorité sur l'inférence.
        $manual = \Illuminate\Support\Facades\DB::table('dim_schools')
            ->where('is_current', true)->whereNotNull('latitude')->whereNotNull('longitude')
            ->get(['id', 'latitude', 'longitude', 'region', 'city'])->keyBy('id');

        return collect(app(ListSchoolsForPilotage::class)()['rows'])
            ->map(function ($s) use ($manual) {
                $m = $manual[$s['id']] ?? null;
                if ($m) {
                    $loc = ['lat' => (float) $m->latitude, 'lng' => (float) $m->longitude, 'region' => $m->region ?: 'Non classée', 'city' => $m->city ?: '—', 'source' => 'manuel'];
                } else {
                    $loc = IvorianGazetteer::locate($s['name']);
                    if ($loc) {
                        $loc['source'] = 'inféré';
                    }
                }

                return $loc ? array_merge($s, ['loc' => $loc]) : null;
            })
            ->filter()
            ->values();
    }

    #[Computed]
    public function manualCount(): int
    {
        return $this->located->filter(fn ($s) => ($s['loc']['source'] ?? '') === 'manuel')->count();
    }

    #[Computed]
    public function points(): array
    {
        return $this->located->map(function ($s) {
            // Projection quasi-équirectangulaire sur le cadre CI.
            $x = (($s['loc']['lng'] - self::LNG_MIN) / (self::LNG_MAX - self::LNG_MIN)) * self::W;
            $y = ((self::LAT_MAX - $s['loc']['lat']) / (self::LAT_MAX - self::LAT_MIN)) * self::H;

            // Éclatement déterministe des écoles d'une même localité (ex. Abidjan).
            $seed = crc32((string) $s['id']);
            $angle = ($seed % 360) * M_PI / 180;
            $radius = 6 + ($seed % 22);
            $x += cos($angle) * $radius;
            $y += sin($angle) * $radius;

            $r = max(4, min(16, sqrt(max($s['known'], 1)) * 0.7));

            return [
                'id' => $s['id'], 'name' => $s['name'], 'city' => $s['loc']['city'], 'region' => $s['loc']['region'],
                'x' => round($x, 1), 'y' => round($y, 1), 'r' => round($r, 1),
                'rate' => $s['rate'], 'known' => $s['known'], 'actifs' => $s['actifs'], 'potential' => $s['potential'],
                'color' => $this->color($s),
            ];
        })->all();
    }

    private function color(array $s): string
    {
        if ($this->metric === 'potential') {
            return $s['potential'] > 5_000_000 ? '#7C3AED' : ($s['potential'] > 0 ? '#B37FEB' : '#C7CBD1');
        }

        return match (true) {
            $s['known'] < 20 => '#9AA1AB',
            $s['rate'] >= 40 => '#189B57',
            $s['rate'] >= 20 => '#D97706',
            default => '#DC2626',
        };
    }

    #[Computed]
    public function regions(): array
    {
        return $this->located->groupBy(fn ($s) => $s['loc']['region'])
            ->map(function ($group, $region) {
                $known = $group->sum('known');
                $actifs = $group->sum('actifs');

                return [
                    'region' => $region,
                    'schools' => $group->count(),
                    'known' => $known,
                    'actifs' => $actifs,
                    'rate' => $known > 0 ? round($actifs / $known * 100, 1) : 0.0,
                    'potential' => (int) $group->sum('potential'),
                ];
            })
            ->sortByDesc('known')->values()->all();
    }

    #[Computed]
    public function stats(): array
    {
        $total = count(app(ListSchoolsForPilotage::class)()['rows']);
        $located = $this->located->count();

        return [
            'total' => $total,
            'located' => $located,
            'unlocated' => $total - $located,
            'regions' => count($this->regions),
        ];
    }

    public function selectRegion(string $region): void
    {
        $this->region = $this->region === $region ? '' : $region;
    }
};

?>

@php
    $fr = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $pct = fn ($n) => number_format((float) $n, 1, ',', ' ').' %';
    $money = fn ($n) => $n >= 1_000_000 ? number_format($n / 1_000_000, 1, ',', ' ').' M' : $fr($n);
    $s = $this->stats;

    // Silhouette schématique de la Côte d'Ivoire (repère visuel, non cadastral).
    $border = [
        [-8.0, 10.3], [-6.9, 10.4], [-6.2, 10.2], [-5.5, 10.3], [-4.7, 9.9], [-4.1, 9.9], [-2.9, 9.6],
        [-2.5, 8.8], [-2.6, 8.0], [-2.8, 7.6], [-3.0, 6.6], [-2.73, 5.6], [-3.2, 5.15],
        [-4.6, 5.1], [-5.5, 4.9], [-6.6, 4.62], [-7.5, 4.35], [-7.6, 5.9], [-8.2, 6.2],
        [-8.6, 7.9], [-8.2, 8.5], [-8.1, 9.4],
    ];
    $proj = function ($lng, $lat) {
        $x = (($lng - (-8.8)) / (-2.4 - (-8.8))) * 760;
        $y = ((10.9 - $lat) / (10.9 - 4.1)) * 660;
        return round($x, 1).','.round($y, 1);
    };
    $borderPath = collect($border)->map(fn ($p) => $proj($p[0], $p[1]))->implode(' ');
@endphp

<div class="mx-auto max-w-[1480px]">

    {{-- Bandeau honnêteté --}}
    <div class="mb-5 flex items-start gap-3 rounded-[13px] border border-warning/25 bg-[#FEF9EF] p-4">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" class="mt-0.5 flex-shrink-0 text-warning"><path d="M10 3l7 12H3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 8v3.5M10 13.5h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        <div>
            @php $manual = $this->manualCount; @endphp
            <div class="text-[13.5px] font-bold text-[#8A5A06]">Localisation : saisie manuelle + déduction du nom</div>
            <p class="mt-1 text-[12.5px] leading-relaxed text-[#8A5A06]/85">
                {{ $fr($s['located']) }}/{{ $fr($s['total']) }} écoles sont placées sur la carte — dont <strong>{{ $fr($manual) }} saisies manuellement</strong> (ville/commune renseignées dans la fiche école, coordonnées exactes de la localité) et {{ $fr($s['located'] - $manual) }} <strong>déduites du nom</strong> (répertoire ivoirien, approximation). {{ $fr($s['unlocated']) }} restent sans localisation. Renseignez la ville et la commune depuis chaque fiche école pour fiabiliser la carte.
            </p>
        </div>
    </div>

    {{-- KPIs --}}
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([['Écoles localisées', $s['located'], '#2554C7'], ['Régions couvertes', $s['regions'], '#189B57'], ['Localisation inconnue', $s['unlocated'], '#5B6472'], ['Total écoles', $s['total'], '#8A1C6B']] as [$label, $val, $color])
            <div class="rounded-[13px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="text-[12px] font-semibold text-ink-500">{{ $label }}</div>
                <div class="mt-1.5 text-[22px] font-bold tracking-tight" style="color: {{ $color }}">{{ $fr($val) }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[1fr_360px]">

        {{-- Carte --}}
        <div class="rounded-[16px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)] sm:p-5">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-[15px] font-bold text-ink-900">Carte de l'adoption — Côte d'Ivoire</h2>
                <div class="inline-flex rounded-[9px] border border-ink-300 p-0.5">
                    <button wire:click="$set('metric','adoption')" class="rounded-[7px] px-3 py-1.5 text-[12px] font-semibold {{ $metric === 'adoption' ? 'bg-brand-600 text-white' : 'text-ink-600 hover:bg-ink-100' }}">Adoption</button>
                    <button wire:click="$set('metric','potential')" class="rounded-[7px] px-3 py-1.5 text-[12px] font-semibold {{ $metric === 'potential' ? 'bg-brand-600 text-white' : 'text-ink-600 hover:bg-ink-100' }}">Potentiel</button>
                </div>
            </div>

            <div class="relative overflow-hidden rounded-[12px] bg-gradient-to-b from-[#F3F7FF] to-[#EEF3FB]">
                <svg viewBox="0 0 760 660" class="h-auto w-full" style="max-height: 560px">
                    {{-- Silhouette CI --}}
                    <polygon points="{{ $borderPath }}" fill="#E4EBF6" stroke="#C3CEDF" stroke-width="1.5" stroke-linejoin="round"/>

                    {{-- Points écoles --}}
                    @foreach ($this->points as $p)
                        @php $dim = $region !== '' && $p['region'] !== $region; @endphp
                        <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="{{ $p['r'] }}"
                                fill="{{ $p['color'] }}" fill-opacity="{{ $dim ? 0.12 : 0.72 }}"
                                stroke="#fff" stroke-width="{{ $dim ? 0 : 1 }}" class="transition-opacity">
                            <title>{{ $p['name'] }} — {{ $p['city'] }} ({{ $p['region'] }}) · {{ $pct($p['rate']) }} adoption · {{ $fr($p['known']) }} parents</title>
                        </circle>
                    @endforeach
                </svg>

                {{-- Légende --}}
                <div class="absolute bottom-3 left-3 rounded-[10px] border border-ink-200 bg-white/95 px-3 py-2 text-[11px] shadow-sm">
                    <div class="mb-1 font-bold text-ink-700">{{ $metric === 'potential' ? 'Potentiel de revenu' : "Taux d'adoption" }}</div>
                    @if ($metric === 'potential')
                        <div class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#7C3AED"></span> Élevé</div>
                        <div class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#B37FEB"></span> Modéré</div>
                        <div class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#C7CBD1"></span> Nul (intégré)</div>
                    @else
                        <div class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#189B57"></span> ≥ 40 %</div>
                        <div class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#D97706"></span> 20–40 %</div>
                        <div class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#DC2626"></span> &lt; 20 %</div>
                        <div class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#9AA1AB"></span> Base insuffisante</div>
                    @endif
                    <div class="mt-1 border-t border-ink-100 pt-1 text-ink-400">Taille = parents connus</div>
                </div>
                @if ($region !== '')
                    <button wire:click="selectRegion('{{ $region }}')" class="absolute right-3 top-3 inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-2.5 py-1 text-[11.5px] font-semibold text-white shadow">{{ $region }} <svg width="12" height="12" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
                @endif
            </div>
        </div>

        {{-- Répartition par région --}}
        <div class="rounded-[16px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)] sm:p-5">
            <h2 class="mb-1 text-[15px] font-bold text-ink-900">Répartition par région</h2>
            <p class="mb-3 text-[12px] text-ink-500">Cliquez une région pour la mettre en avant sur la carte.</p>
            <div class="max-h-[520px] space-y-1.5 overflow-y-auto">
                @forelse ($this->regions as $r)
                    @php $active = $region === $r['region']; $rc = $r['rate'] >= 40 ? '#189B57' : ($r['rate'] >= 20 ? '#D97706' : '#DC2626'); @endphp
                    <button wire:click="selectRegion('{{ $r['region'] }}')" wire:key="reg-{{ $r['region'] }}"
                            class="w-full rounded-[11px] border p-3 text-left transition-colors {{ $active ? 'border-brand-400 bg-brand-50' : 'border-ink-200 hover:border-brand-300 hover:bg-brand-50/30' }}">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[13.5px] font-bold text-ink-900">{{ $r['region'] }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-bold text-white" style="background: {{ $rc }}">{{ $pct($r['rate']) }}</span>
                        </div>
                        <div class="mt-1.5 flex items-center gap-x-4 gap-y-0.5 text-[11.5px] text-ink-500">
                            <span>{{ $fr($r['schools']) }} école{{ $r['schools'] > 1 ? 's' : '' }}</span>
                            <span>{{ $fr($r['known']) }} connus</span>
                            <span>{{ $fr($r['actifs']) }} adoptants</span>
                        </div>
                        @if ($r['potential'] > 0)
                            <div class="mt-0.5 text-[11.5px] font-semibold text-[#7C3AED]">Potentiel : {{ $money($r['potential']) }} FCFA</div>
                        @endif
                    </button>
                @empty
                    <p class="py-8 text-center text-[13px] text-ink-500">Aucune école localisée.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
