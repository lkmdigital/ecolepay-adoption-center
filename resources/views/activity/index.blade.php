<?php

use App\Domains\Activity\Actions\SyncActivityLog;
use App\Domains\Activity\Models\ActivityLog;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $category = 'metier';

    #[Url]
    public string $module = '';

    #[Url]
    public string $action = '';

    #[Url]
    public string $level = '';

    #[Url]
    public string $search = '';

    public ?int $selectedId = null;

    public function mount(): void
    {
        app(SyncActivityLog::class)();
    }

    #[Computed]
    public function base(): Collection
    {
        return ActivityLog::query()->where('category', $this->category)->orderByDesc('occurred_at')->get();
    }

    #[Computed]
    public function logs(): Collection
    {
        $term = trim(mb_strtolower($this->search));

        return $this->base
            ->when($this->module !== '', fn ($c) => $c->where('module', $this->module))
            ->when($this->action !== '', fn ($c) => $c->where('action', $this->action))
            ->when($this->level !== '', fn ($c) => $c->where('level', $this->level))
            ->when($term !== '', fn ($c) => $c->filter(fn ($l) => str_contains(mb_strtolower($l->title.' '.$l->description.' '.$l->actor), $term)))
            ->values();
    }

    #[Computed]
    public function counts(): array
    {
        $all = ActivityLog::query()->where('category', $this->category)->get();

        return [
            'today' => $all->filter(fn ($l) => $l->occurred_at && $l->occurred_at->isToday())->count(),
            'actors' => $all->pluck('actor')->unique()->count(),
            'critical' => $all->where('level', 'critique')->count(),
            'failures' => $all->where('result', 'failure')->count(),
        ];
    }

    #[Computed]
    public function charts(): array
    {
        $all = $this->base;
        $modules = $all->groupBy('module')->map->count()->sortDesc();
        $days = collect(range(13, 0))->map(fn ($d) => now()->subDays($d)->format('Y-m-d'));
        $byDay = $all->groupBy(fn ($l) => $l->occurred_at?->format('Y-m-d'))->map->count();

        return [
            'moduleLabels' => $modules->keys()->all(),
            'moduleValues' => $modules->values()->all(),
            'dayLabels' => $days->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->locale('fr')->isoFormat('D MMM'))->all(),
            'dayValues' => $days->map(fn ($d) => (int) ($byDay[$d] ?? 0))->all(),
        ];
    }

    #[Computed]
    public function selected(): ?ActivityLog
    {
        return $this->selectedId ? ActivityLog::find($this->selectedId) : null;
    }

    public function updated(): void
    {
        unset($this->base);
        $this->dispatch('activity:data', payload: $this->charts);
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
    }

    public function closePanel(): void
    {
        $this->selectedId = null;
    }

    public function refreshLog(): void
    {
        app(SyncActivityLog::class)();
        unset($this->base);
    }

    public function export()
    {
        $logs = $this->logs;

        return response()->streamDownload(function () use ($logs) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Acteur', 'Catégorie', 'Module', 'Action', 'Niveau', 'Titre', 'Résultat']);
            foreach ($logs as $l) {
                fputcsv($out, [$l->occurred_at?->format('Y-m-d H:i'), $l->actor, $l->category, $l->module, $l->action, $l->level, $l->title, $l->result]);
            }
            fclose($out);
        }, 'journal-'.now()->format('Y-m-d').'.csv');
    }
};

?>

@php
    $fr = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $levelMeta = ['info' => ['Info', '#1D3F9C', '#EEF3FE'], 'warning' => ['Avertissement', '#B45F04', '#FEF3E2'], 'critique' => ['Critique', '#B91C1C', '#FDECEC']];
    $actionMeta = [
        'consultation' => ['Consultation', '#6B7280'], 'creation' => ['Création', '#0F7A44'], 'modification' => ['Modification', '#1D3F9C'],
        'suppression' => ['Suppression', '#B91C1C'], 'export' => ['Export', '#B45F04'], 'import' => ['Import', '#2554C7'],
        'connexion' => ['Connexion', '#0B6A3B'], 'milestone' => ['Jalon', '#2554C7'], 'alerte' => ['Alerte', '#B91C1C'],
    ];
    $moduleLabels = ['ecoles' => 'Écoles', 'campagnes' => 'Campagnes', 'parents' => 'Parents', 'rapports' => 'Rapports', 'analytics' => 'Analytics', 'notifications' => 'Notifications', 'revenus' => 'Revenus', 'systeme' => 'Système'];
    $dateTime = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->locale('fr')->isoFormat('D MMM YYYY · HH:mm') : '—';
    $c = $this->counts;
@endphp

<div class="mx-auto max-w-[1480px]" x-data="{ panel: @entangle('selectedId') }">

    {{-- Actions --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex rounded-lg border border-ink-200 bg-white p-0.5">
            <button wire:click="$set('category','metier')" class="rounded-md px-3.5 py-1.5 text-[12.5px] font-semibold {{ $category === 'metier' ? 'bg-brand-600 text-white' : 'text-ink-600 hover:bg-ink-100' }}">Journal métier</button>
            <button wire:click="$set('category','technique')" class="rounded-md px-3.5 py-1.5 text-[12.5px] font-semibold {{ $category === 'technique' ? 'bg-brand-600 text-white' : 'text-ink-600 hover:bg-ink-100' }}">Journal technique</button>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="export" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 3v9m0 0l-3.2-3.2M10 12l3.2-3.2M4 15.5h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Exporter
            </button>
            <button wire:click="refreshLog" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">Actualiser</button>
        </div>
    </div>

    @if ($category === 'technique')
        <div class="mb-4 flex items-start gap-2.5 rounded-xl border border-warning/25 bg-warning-soft/50 px-4 py-2.5">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" class="mt-0.5 flex-shrink-0 text-warning"><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="13.5" r="1" fill="currentColor"/></svg>
            <div class="text-[12.5px] leading-snug text-ink-700">Le journal technique est reconstruit depuis les vraies traces de l'application. L'<span class="font-semibold text-ink-900">attribution par utilisateur, l'adresse IP et le navigateur</span> nécessitent l'authentification (module Utilisateurs & Rôles à venir) — l'acteur est « Système » en attendant.</div>
        </div>
    @endif

    {{-- KPI --}}
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([['Activités aujourd\'hui', $fr($c['today']), '#2554C7', '#EEF3FE'], ['Acteurs', $fr($c['actors']), '#0F7A44', '#E9F8EF'], ['Événements critiques', $fr($c['critical']), '#B91C1C', '#FDECEC'], ['Échecs détectés', $fr($c['failures']), '#B45F04', '#FEF3E2']] as [$lbl, $val, $fg, $bg])
            <div class="rounded-[13px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="flex h-8 w-8 items-center justify-center rounded-[9px]" style="background: {{ $bg }}; color: {{ $fg }}"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M4 4h12v12H4z" stroke="currentColor" stroke-width="1.5"/><path d="M7 8h6M7 11h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></div>
                <div class="mt-2.5 text-[22px] font-bold tracking-tight text-ink-900">{{ $val }}</div>
                <div class="text-[12px] font-semibold text-ink-800">{{ $lbl }}</div>
            </div>
        @endforeach
    </div>

    {{-- Filtres --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="flex min-w-[220px] flex-1 items-center gap-2 rounded-lg border border-ink-300 bg-white px-3 py-2 focus-within:border-brand-600">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-500"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="M17 17l-3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            <input wire:model.live.debounce.250ms="search" placeholder="Rechercher un événement, un module…" class="w-full border-none bg-transparent text-[13.5px] text-ink-900 outline-none placeholder:text-ink-500">
        </div>
        @php
            $filters = [
                ['module', ['' => 'Tous modules', 'ecoles' => 'Écoles', 'campagnes' => 'Campagnes', 'parents' => 'Parents', 'rapports' => 'Rapports', 'analytics' => 'Analytics', 'revenus' => 'Revenus']],
                ['level', ['' => 'Tous niveaux', 'info' => 'Information', 'warning' => 'Avertissement', 'critique' => 'Critique']],
            ];
        @endphp
        @foreach ($filters as [$prop, $opts])
            <flux:dropdown>
                <button class="inline-flex items-center gap-2 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold text-ink-800 hover:bg-ink-50">{{ $opts[$$prop] ?? reset($opts) }}<svg width="12" height="12" viewBox="0 0 20 20" fill="none"><path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                <flux:menu>@foreach ($opts as $val => $label)<flux:menu.item wire:click="$set('{{ $prop }}','{{ $val }}')" icon="{{ $$prop === $val ? 'check' : '' }}">{{ $label }}</flux:menu.item>@endforeach</flux:menu>
            </flux:dropdown>
        @endforeach
        <div class="ml-auto text-[13px] text-ink-500"><span class="font-mono font-semibold text-ink-900">{{ $fr($this->logs->count()) }}</span> événements</div>
    </div>

    {{-- Tableau détaillé --}}
    <div class="mb-6 overflow-x-auto rounded-[14px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
        <table class="w-full border-collapse">
            <thead>
                <tr class="bg-ink-50 text-[11px] font-bold uppercase tracking-wider text-ink-500">
                    <th class="px-5 py-3 text-left">Événement</th><th class="px-3 py-3 text-left">Module</th><th class="px-3 py-3 text-left">Action</th>
                    <th class="px-3 py-3 text-left">Acteur</th><th class="px-3 py-3 text-left">Date</th><th class="px-3 py-3 text-left">Niveau</th><th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->logs->take(50) as $l)
                    @php [$llabel, $lfg, $lbg] = $levelMeta[$l->level]; [$alabel, $afg] = $actionMeta[$l->action] ?? ['—', '#6B7280']; @endphp
                    <tr wire:key="log-{{ $l->id }}" class="cursor-pointer border-b border-ink-150 last:border-0 hover:bg-ink-50" wire:click="select({{ $l->id }})">
                        <td class="px-5 py-2.5 text-[13px] font-semibold text-ink-900">{!! $l->title !!}</td>
                        <td class="px-3 py-2.5"><span class="rounded bg-ink-100 px-1.5 py-0.5 text-[11px] font-semibold text-ink-600">{{ $moduleLabels[$l->module] ?? $l->module }}</span></td>
                        <td class="px-3 py-2.5"><span class="text-[12px] font-semibold" style="color: {{ $afg }}">{{ $alabel }}</span></td>
                        <td class="px-3 py-2.5 text-[12.5px] text-ink-600">{{ $l->actor }}</td>
                        <td class="px-3 py-2.5 text-[12px] text-ink-500">{{ $dateTime($l->occurred_at) }}</td>
                        <td class="px-3 py-2.5"><span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background: {{ $lbg }}; color: {{ $lfg }}">{{ $llabel }}</span></td>
                        <td class="px-4 py-2.5 text-right"><svg width="15" height="15" viewBox="0 0 20 20" fill="none" class="text-ink-400"><path d="M7 4l6 6-6 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-6 py-12 text-center text-[13px] text-ink-500">Aucun événement pour ces filtres.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Activités critiques + graphiques --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_1.3fr]">
        <div class="rounded-[16px] border border-ink-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div class="mb-3 flex items-center gap-2 text-[13px] font-semibold text-ink-900"><span class="h-2 w-2 rounded-full bg-danger"></span>Activités critiques</div>
            <div class="flex flex-col gap-2">
                @forelse ($this->base->where('level', 'critique')->take(6) as $l)
                    <button wire:click="select({{ $l->id }})" class="flex items-start gap-2.5 rounded-xl border border-danger/20 bg-danger-soft/40 p-2.5 text-left hover:bg-danger-soft/70" wire:key="crit-{{ $l->id }}">
                        <span class="mt-0.5 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-danger"></span>
                        <span><span class="block text-[12.5px] font-semibold text-ink-900">{!! $l->title !!}</span><span class="block text-[11px] text-ink-500">{{ $dateTime($l->occurred_at) }}</span></span>
                    </button>
                @empty
                    <div class="py-6 text-center text-[12.5px] text-ink-500">Aucune activité critique.</div>
                @endforelse
            </div>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="rounded-[16px] border border-ink-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="mb-2 text-[13px] font-semibold text-ink-900">Par module</div>
                <div wire:ignore><div id="act-module" class="h-[180px] w-full"></div></div>
            </div>
            <div class="rounded-[16px] border border-ink-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="mb-2 text-[13px] font-semibold text-ink-900">Par jour (14 j)</div>
                <div wire:ignore><div id="act-day" class="h-[180px] w-full"></div></div>
            </div>
        </div>
    </div>

    {{-- Panneau détail --}}
    <div x-show="panel" x-cloak class="fixed inset-0 z-50" style="display:none">
        <div class="absolute inset-0 bg-ink-900/30" x-show="panel" x-transition.opacity @click="$wire.closePanel()"></div>
        <div class="absolute inset-y-0 right-0 flex w-full max-w-[440px] flex-col bg-white shadow-2xl" x-show="panel" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full">
            @if ($l = $this->selected)
                @php [$llabel, $lfg, $lbg] = $levelMeta[$l->level]; @endphp
                <div class="flex items-start justify-between gap-3 border-b border-ink-150 px-6 py-5">
                    <div>
                        <div class="text-[15px] font-bold text-ink-900">{!! $l->title !!}</div>
                        <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background: {{ $lbg }}; color: {{ $lfg }}">{{ $llabel }}</span>
                    </div>
                    <button wire:click="closePanel" class="flex h-8 w-8 items-center justify-center rounded-lg text-ink-500 hover:bg-ink-100"><svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5">
                    @if ($l->description)<p class="mb-5 text-[13px] leading-relaxed text-ink-700">{{ $l->description }}</p>@endif
                    <div class="mb-1 text-[11px] font-bold uppercase tracking-wider text-ink-500">Informations générales</div>
                    <div class="mb-5 grid grid-cols-2 gap-y-2 text-[13px]">
                        <div class="text-ink-500">Acteur</div><div class="text-right font-medium text-ink-800">{{ $l->actor }}</div>
                        <div class="text-ink-500">Module</div><div class="text-right font-medium text-ink-800">{{ $moduleLabels[$l->module] ?? $l->module }}</div>
                        <div class="text-ink-500">Action</div><div class="text-right font-medium text-ink-800">{{ $actionMeta[$l->action][0] ?? $l->action }}</div>
                        <div class="text-ink-500">Date</div><div class="text-right font-medium text-ink-800">{{ $dateTime($l->occurred_at) }}</div>
                        <div class="text-ink-500">Résultat</div><div class="text-right"><span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background: {{ $l->result === 'success' ? '#E9F8EF' : '#FDECEC' }}; color: {{ $l->result === 'success' ? '#0F7A44' : '#B91C1C' }}">{{ $l->result === 'success' ? 'Succès' : 'Échec' }}</span></div>
                    </div>
                    <div class="mb-2 text-[11px] font-bold uppercase tracking-wider text-ink-500">Détails techniques</div>
                    <div class="mb-5 grid grid-cols-2 gap-y-2 text-[13px]">
                        <div class="text-ink-500">Adresse IP</div><div class="text-right font-medium text-ink-400">{{ $l->meta['ip'] ?? '—' }}</div>
                        <div class="text-ink-500">Navigateur</div><div class="text-right font-medium text-ink-400">{{ $l->meta['browser'] ?? '—' }}</div>
                        <div class="text-ink-500">Session</div><div class="text-right font-medium text-ink-400">{{ $l->meta['session'] ?? '—' }}</div>
                    </div>
                    <div class="text-[11px] text-ink-400">IP / navigateur / session seront renseignés dès l'activation de l'authentification.</div>
                </div>
                @if ($l->link_route)
                    <div class="border-t border-ink-150 px-6 py-4">
                        <a href="{{ $l->resource_id ? route($l->link_route, $l->resource_id) : route($l->link_route) }}" wire:navigate class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2.5 text-[12.5px] font-semibold text-white hover:bg-brand-700">Voir la ressource concernée</a>
                    </div>
                @endif
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
        const axis = { color: '#B7BCC5', fontFamily: inter, fontSize: 10 };
        const charts = {};
        const init = (id) => { const el = document.getElementById(id); if (! el) return null; if (charts[id]) charts[id].dispose(); charts[id] = window.echarts.init(el); return charts[id]; };
        const modLabels = { ecoles: 'Écoles', campagnes: 'Campagnes', parents: 'Parents', rapports: 'Rapports', analytics: 'Analytics', notifications: 'Notif.', revenus: 'Revenus', systeme: 'Système' };

        const render = (d) => {
            const m = init('act-module');
            if (m) m.setOption({
                grid: { left: 6, right: 20, top: 8, bottom: 6, containLabel: true },
                tooltip: { trigger: 'axis' },
                xAxis: { type: 'value', splitLine: { lineStyle: { color: '#F0F1F3' } }, axisLabel: axis },
                yAxis: { type: 'category', inverse: true, data: d.moduleLabels.map(x => modLabels[x] || x), axisLabel: axis, axisTick: { show: false }, axisLine: { show: false } },
                series: [{ type: 'bar', data: d.moduleValues, barMaxWidth: 14, itemStyle: { borderRadius: [0, 4, 4, 0], color: new g.LinearGradient(0, 0, 1, 0, [{ offset: 0, color: '#2554C7' }, { offset: 1, color: '#7DA0EC' }]) } }],
            });
            const day = init('act-day');
            if (day) day.setOption({
                grid: { left: 26, right: 12, top: 10, bottom: 22 },
                tooltip: { trigger: 'axis' },
                xAxis: { type: 'category', boundaryGap: false, data: d.dayLabels, axisTick: { show: false }, axisLine: { lineStyle: { color: '#E7E9ED' } }, axisLabel: { ...axis, interval: 2 } },
                yAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { color: '#F0F1F3' } }, axisLabel: axis },
                series: [{ type: 'line', data: d.dayValues, smooth: 0.3, showSymbol: false, lineStyle: { color: '#2554C7', width: 2.5 }, areaStyle: { color: 'rgba(37,84,199,0.12)' } }],
            });
        };
        render(@js($this->charts));
        window.addEventListener('resize', () => Object.values(charts).forEach(c => c.resize()));
        $wire.on('activity:data', (e) => render(e.payload ?? e[0]?.payload ?? e));
    })();
</script>
@endscript
