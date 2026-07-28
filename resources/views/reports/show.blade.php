<?php

use App\Domains\Reports\Actions\BuildReport;
use App\Domains\Reports\Models\Report;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $reportId;

    public function mount(int $report): void
    {
        $this->reportId = $report;
        abort_if(Report::find($report) === null, 404);
    }

    #[Computed]
    public function report(): Report
    {
        return Report::findOrFail($this->reportId);
    }

    #[Computed]
    public function content(): array
    {
        return app(BuildReport::class)($this->report);
    }

    public function toggleFavorite(): void
    {
        $r = $this->report;
        $r->update(['is_favorite' => ! $r->is_favorite]);
        unset($this->report);
    }

    public function exportCsv()
    {
        $content = $this->content;

        return response()->streamDownload(function () use ($content) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [$content['meta']['title']]);
            fputcsv($out, ['Généré le', $content['meta']['generatedAt']->format('Y-m-d H:i')]);
            fputcsv($out, []);
            fputcsv($out, ['Indicateur', 'Valeur']);
            foreach ($content['kpis'] as $k) {
                fputcsv($out, [$k['label'], $k['value']]);
            }
            foreach ($content['sections'] as $s) {
                if (($s['kind'] ?? '') === 'table') {
                    fputcsv($out, []);
                    fputcsv($out, [$s['title']]);
                    fputcsv($out, $s['columns']);
                    foreach ($s['rows'] as $row) {
                        fputcsv($out, $row);
                    }
                }
            }
            fclose($out);
        }, \Illuminate\Support\Str::slug($content['meta']['title']).'.csv');
    }
};

?>

@php
    $c = $this->content;
    $m = $c['meta'];
    $r = $this->report;
    $prio = ['critique' => ['#B91C1C', '#FDECEC'], 'elevee' => ['#B45F04', '#FEF3E2'], 'moyenne' => ['#1D3F9C', '#EEF3FE'], 'faible' => ['#5B6472', '#F2F3F5']];
    $prioLbl = ['critique' => 'Critique', 'elevee' => 'Élevée', 'moyenne' => 'Moyenne', 'faible' => 'Faible'];
    $fr = fn ($n) => number_format((float) $n, 0, ',', ' ');
@endphp

<div class="mx-auto max-w-[900px]" x-data>

    {{-- Barre d'actions (masquée à l'impression) --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 print:hidden">
        <nav class="flex items-center gap-1.5 text-[12.5px] text-ink-500">
            <a href="{{ route('reports.index') }}" wire:navigate class="hover:text-ink-800">Rapports</a><span>/</span>
            <span class="font-semibold text-ink-800">{{ $m['title'] }}</span>
        </nav>
        <div class="flex flex-wrap items-center gap-2">
            <button wire:click="toggleFavorite" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-ink-200 bg-white hover:bg-ink-50" title="Favori">
                <svg width="16" height="16" viewBox="0 0 20 20" fill="{{ $r->is_favorite ? '#B45309' : 'none' }}" stroke="{{ $r->is_favorite ? '#B45309' : '#6B7280' }}"><path d="M10 2l2.2 5.3L18 8l-4 3.9.9 5.6L10 15l-4.9 2.5.9-5.6L2 8l5.8-.7z" stroke-width="1.4" stroke-linejoin="round"/></svg>
            </button>
            <button @click="navigator.clipboard.writeText(window.location.href); $el.querySelector('span').textContent='Lien copié'" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M8 12a3 3 0 004.2 0l2.3-2.3a3 3 0 00-4.2-4.2l-1 1M12 8a3 3 0 00-4.2 0L5.5 10.3a3 3 0 004.2 4.2l1-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                <span>Copier le lien</span>
            </button>
            <button wire:click="exportCsv" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 3v9m0 0l-3.2-3.2M10 12l3.2-3.2M4 15.5h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                CSV
            </button>
            <button onclick="window.print()" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M6 8V3h8v5M6 15H4v-4a2 2 0 012-2h8a2 2 0 012 2v4h-2M6 12h8v5H6z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                Imprimer / PDF
            </button>
        </div>
    </div>

    {{-- Document --}}
    <div class="overflow-hidden rounded-[18px] border border-ink-200 bg-white shadow-[0_2px_16px_rgba(15,23,42,0.05)] print:border-0 print:shadow-none">

        {{-- Couverture --}}
        <div class="relative overflow-hidden px-10 py-12 text-white" style="background: radial-gradient(130% 150% at 0% 0%, #2E5BD0 0%, #173C82 55%, #102C61 100%)">
            <div class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.12em] text-white/60">
                <img src="/images/ecolepay-mark.png" alt="" class="h-5 w-5 object-contain opacity-90"> EcolePay Adoption Center
            </div>
            <h1 class="mt-6 text-[30px] font-extrabold leading-tight tracking-tight">{{ $m['title'] }}</h1>
            <div class="mt-2 text-[14px] text-white/75">{{ $m['template'] }}</div>
            <div class="mt-8 flex flex-wrap gap-x-8 gap-y-2 text-[12.5px] text-white/70">
                <div><span class="text-white/50">Période</span> · <span class="font-semibold text-white">{{ $m['periodLabel'] }}</span></div>
                <div><span class="text-white/50">Périmètre</span> · <span class="font-semibold text-white">{{ $m['filterLabel'] }}</span></div>
                <div><span class="text-white/50">Généré le</span> · <span class="font-semibold text-white">{{ $m['generatedAt']->locale('fr')->isoFormat('D MMMM YYYY à HH:mm') }}</span></div>
            </div>
        </div>

        <div class="px-10 py-8">
            {{-- Résumé exécutif --}}
            <div class="mb-8">
                <div class="mb-2 text-[11px] font-bold uppercase tracking-[0.08em] text-brand-600">Résumé exécutif</div>
                <p class="text-[14px] leading-relaxed text-ink-800">{{ $c['summary'] }}</p>
            </div>

            {{-- KPI --}}
            @if (count($c['kpis']))
                <div class="mb-8">
                    <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Indicateurs clés</div>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach ($c['kpis'] as $k)
                            <div class="rounded-[12px] border border-ink-150 bg-ink-50 px-4 py-3">
                                <div class="text-[20px] font-bold tracking-tight text-ink-900">{{ $k['value'] }}</div>
                                <div class="text-[11.5px] text-ink-500">{{ $k['label'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Sections --}}
            @foreach ($c['sections'] as $s)
                <div class="mb-8 break-inside-avoid">
                    <div class="mb-3 text-[14px] font-bold text-ink-900">{{ $s['title'] }}</div>
                    @if ($s['kind'] === 'funnel')
                        @php $fmax = max(1, $s['data'][0]['value']); @endphp
                        <div class="flex flex-col gap-2">
                            @foreach ($s['data'] as $i => $st)
                                <div>
                                    <div class="mb-1 flex items-center justify-between text-[12.5px]">
                                        <span class="font-medium text-ink-800">{{ $st['label'] }}</span>
                                        <span class="font-mono font-semibold text-ink-900">{{ $fr($st['value']) }}@if ($st['conv'] !== null)<span class="ml-2 text-[11px] font-normal text-ink-400">{{ number_format($st['conv'], 0, ',', ' ') }} %</span>@endif</span>
                                    </div>
                                    <div class="h-6 overflow-hidden rounded-md bg-ink-100"><div class="h-full rounded-md" style="width: {{ max(3, round($st['value'] / $fmax * 100)) }}%; background: linear-gradient(90deg,#173C82,#2554C7,#4E7DE0); opacity: {{ 1 - $i * 0.12 }}"></div></div>
                                </div>
                            @endforeach
                        </div>
                    @elseif ($s['kind'] === 'table')
                        <div class="overflow-hidden rounded-xl border border-ink-150">
                            <table class="w-full border-collapse">
                                <thead>
                                    <tr class="bg-ink-50 text-[11px] font-bold uppercase tracking-wider text-ink-500">
                                        @foreach ($s['columns'] as $i => $col)<th class="px-3 py-2 {{ $i === 0 ? 'text-left' : 'text-right' }}">{{ $col }}</th>@endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($s['rows'] as $row)
                                        <tr class="border-b border-ink-150 last:border-0">
                                            @foreach ($row as $i => $cell)<td class="px-3 py-1.5 text-[12.5px] {{ $i === 0 ? 'text-left font-semibold text-ink-900' : 'text-right font-mono text-ink-700' }}">{{ $cell }}</td>@endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @elseif ($s['kind'] === 'list')
                        <ul class="flex flex-col gap-1.5">
                            @foreach ($s['items'] as $item)
                                <li class="flex items-start gap-2 text-[13px] text-ink-700"><span class="mt-1.5 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-warning"></span>{{ $item }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach

            {{-- Recommandations IA --}}
            @if (count($c['reco']))
                <div class="mb-8 break-inside-avoid rounded-[16px] border border-brand-200 bg-brand-50/40 p-6">
                    <div class="mb-3 flex items-center gap-2">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-600 text-white"><svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M10 2.5a5.5 5.5 0 00-3 10.1V15h6v-2.4a5.5 5.5 0 00-3-10.1z" stroke="currentColor" stroke-width="1.6"/><path d="M8 17h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span>
                        <span class="text-[14px] font-bold text-ink-900">Recommandations</span>
                        <span class="rounded bg-white px-1.5 py-0.5 text-[9.5px] font-bold uppercase tracking-wide text-ink-500">règles métier · v1</span>
                    </div>
                    <div class="flex flex-col gap-2.5">
                        @foreach ($c['reco'] as $rec)
                            @php [$rfg, $rbg] = $prio[$rec['priority']] ?? $prio['moyenne']; @endphp
                            <div class="rounded-xl border border-ink-150 bg-white p-3.5">
                                <div class="flex items-start justify-between gap-3">
                                    <span class="text-[13px] font-semibold text-ink-900">{{ $rec['title'] }}</span>
                                    <span class="flex-shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background: {{ $rbg }}; color: {{ $rfg }}">{{ $prioLbl[$rec['priority']] ?? 'Moyenne' }}</span>
                                </div>
                                <div class="mt-1 text-[12.5px] text-ink-600">{{ $rec['why'] }}</div>
                                @if (! empty($rec['impact']))<div class="mt-1 text-[11.5px] font-medium text-ink-500">Impact : {{ $rec['impact'] }}</div>@endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Conclusion --}}
            @if ($c['conclusion'])
                <div class="break-inside-avoid border-t border-ink-150 pt-6">
                    <div class="mb-2 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Conclusion</div>
                    <p class="text-[13.5px] leading-relaxed text-ink-700">{{ $c['conclusion'] }}</p>
                </div>
            @endif

            <div class="mt-8 border-t border-ink-150 pt-4 text-center text-[11px] text-ink-400">EcolePay Adoption Center · Rapport généré automatiquement · {{ $m['generatedAt']->format('d/m/Y H:i') }}</div>
        </div>
    </div>
</div>

@push('styles')
@endpush
<style>
    @media print {
        aside, header { display: none !important; }
        main { overflow: visible !important; padding: 0 !important; }
    }
</style>
