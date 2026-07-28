<?php

use App\Domains\Notifications\Actions\DetectNotifications;
use App\Domains\Notifications\Models\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $kind = '';

    #[Url]
    public string $priority = '';

    #[Url]
    public string $module = '';

    #[Url]
    public string $status = 'active';

    public bool $prefsOpen = false;

    public function mount(): void
    {
        app(DetectNotifications::class)();
    }

    #[Computed]
    public function all(): Collection
    {
        return Notification::query()->orderByRaw("FIELD(priority,'critique','haute','moyenne','faible')")->orderByDesc('detected_at')->get();
    }

    #[Computed]
    public function items(): Collection
    {
        return $this->all
            ->when($this->kind !== '', fn ($c) => $c->where('kind', $this->kind))
            ->when($this->priority !== '', fn ($c) => $c->where('priority', $this->priority))
            ->when($this->module !== '', fn ($c) => $c->where('module', $this->module))
            ->when($this->status === 'active', fn ($c) => $c->where('status', '!=', 'resolved'))
            ->when($this->status === 'resolved', fn ($c) => $c->where('status', 'resolved'))
            ->when($this->status === 'unread', fn ($c) => $c->where('status', 'unread'))
            ->values();
    }

    #[Computed]
    public function counts(): array
    {
        return [
            'unread' => $this->all->where('status', 'unread')->count(),
            'critical' => $this->all->where('priority', 'critique')->where('status', '!=', 'resolved')->count(),
            'pending' => $this->all->where('kind', 'alerte')->where('status', '!=', 'resolved')->count(),
            'resolvedToday' => $this->all->where('status', 'resolved')->filter(fn ($n) => $n->resolved_at && $n->resolved_at->isToday())->count(),
        ];
    }

    #[Computed]
    public function summary(): string
    {
        $newAdopters = (int) DB::table('fact_parent_journeys')->where('is_test', false)->where('first_payment_at', '>=', now()->subDays(30))->distinct()->count('parent_id');
        $critical = $this->all->where('priority', 'critique')->where('status', '!=', 'resolved')->count();
        $actions = $this->all->whereIn('priority', ['critique', 'haute'])->where('status', '!=', 'resolved')->count();

        $nf = fn ($n) => number_format((float) $n, 0, ',', ' ');
        $s = "Sur les 30 derniers jours, {$nf($newAdopters)} nouveaux parents ont adopté EcolePay. ";
        $s .= $critical > 0 ? "{$nf($critical)} écoles présentent une adoption critique nécessitant une intervention. " : 'Aucune école en situation critique. ';
        $s .= "{$nf($actions)} actions prioritaires sont recommandées ci-dessous.";

        return $s;
    }

    public function markRead(int $id): void
    {
        Notification::whereKey($id)->where('status', 'unread')->update(['status' => 'in_progress']);
        unset($this->all);
    }

    public function resolve(int $id): void
    {
        Notification::whereKey($id)->update(['status' => 'resolved', 'resolved_at' => now()]);
        unset($this->all);
    }

    public function reopen(int $id): void
    {
        Notification::whereKey($id)->update(['status' => 'in_progress', 'resolved_at' => null]);
        unset($this->all);
    }

    public function markAllRead(): void
    {
        Notification::where('status', 'unread')->update(['status' => 'in_progress']);
        unset($this->all);
    }

    public function refreshDetection(): void
    {
        app(DetectNotifications::class)();
        unset($this->all);
    }
};

?>

@php
    $fr = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $prio = [
        'critique' => ['Critique', '#B91C1C', '#FDECEC'],
        'haute' => ['Haute', '#B45F04', '#FEF3E2'],
        'moyenne' => ['Moyenne', '#1D3F9C', '#EEF3FE'],
        'faible' => ['Faible', '#5B6472', '#F2F3F5'],
    ];
    $kindMeta = [
        'alerte' => ['Alerte', '#B91C1C'],
        'notification' => ['Notification', '#2554C7'],
        'information' => ['Information', '#0F7A44'],
    ];
    $moduleLabels = ['ecoles' => 'Écoles', 'campagnes' => 'Campagnes', 'parents' => 'Parents', 'rapports' => 'Rapports', 'revenus' => 'Revenus', 'adoption' => 'Adoption', 'sync' => 'Synchro'];
    $c = $this->counts;
    $alerts = $this->items->where('kind', 'alerte');
    $events = $this->items->whereIn('kind', ['notification', 'information']);
@endphp

<div class="mx-auto max-w-[1480px]">

    {{-- Actions --}}
    <div class="mb-5 flex flex-wrap items-center justify-end gap-2">
        <button wire:click="markAllRead" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">Marquer tout comme lu</button>
        <button wire:click="refreshDetection" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none" wire:loading.class="animate-spin" wire:target="refreshDetection"><path d="M16 6a7 7 0 10.9 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M16.5 3v3.2h-3.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Actualiser
        </button>
        <button wire:click="$set('prefsOpen', true)" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="2.6" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="10" r="6.5" stroke="currentColor" stroke-width="1.3" stroke-dasharray="2 2.4"/></svg>
            Préférences
        </button>
    </div>

    {{-- KPI --}}
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([['Non lues', $fr($c['unread']), '#2554C7', '#EEF3FE'], ['Alertes critiques', $fr($c['critical']), '#B91C1C', '#FDECEC'], ['Alertes en attente', $fr($c['pending']), '#B45F04', '#FEF3E2'], ['Résolues aujourd\'hui', $fr($c['resolvedToday']), '#0F7A44', '#E9F8EF']] as [$label, $val, $fg, $bg])
            <div class="rounded-[13px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="flex h-8 w-8 items-center justify-center rounded-[9px]" style="background: {{ $bg }}; color: {{ $fg }}"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M5 8a5 5 0 0110 0c0 4 1.5 5 1.5 5h-13S5 12 5 8z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M8 16a2 2 0 004 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></div>
                <div class="mt-2.5 text-[22px] font-bold tracking-tight text-ink-900">{{ $val }}</div>
                <div class="text-[12px] font-semibold text-ink-800">{{ $label }}</div>
            </div>
        @endforeach
    </div>

    {{-- Synthèse IA --}}
    <div class="mb-6 rounded-[16px] border border-brand-200 bg-brand-50/40 p-5 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
        <div class="mb-2 flex items-center gap-2">
            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-600 text-white"><svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M10 2.5a5.5 5.5 0 00-3 10.1V15h6v-2.4a5.5 5.5 0 00-3-10.1z" stroke="currentColor" stroke-width="1.6"/><path d="M8 17h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span>
            <span class="text-[14px] font-bold text-ink-900">Synthèse du jour</span>
            <span class="rounded bg-white px-1.5 py-0.5 text-[9.5px] font-bold uppercase tracking-wide text-ink-500">règles métier · v1</span>
        </div>
        <p class="text-[13.5px] leading-relaxed text-ink-700">{{ $this->summary }}</p>
    </div>

    {{-- Filtres --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        @php
            $filterDefs = [
                ['status', ['active' => 'Actives', 'unread' => 'Non lues', 'resolved' => 'Résolues', '' => 'Toutes']],
                ['kind', ['' => 'Tous types', 'alerte' => 'Alertes', 'notification' => 'Notifications', 'information' => 'Informations']],
                ['priority', ['' => 'Toutes priorités', 'critique' => 'Critique', 'haute' => 'Haute', 'moyenne' => 'Moyenne', 'faible' => 'Faible']],
                ['module', ['' => 'Tous modules', 'ecoles' => 'Écoles', 'campagnes' => 'Campagnes', 'parents' => 'Parents']],
            ];
        @endphp
        @foreach ($filterDefs as [$prop, $opts])
            <flux:dropdown>
                <button class="inline-flex items-center gap-2 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold text-ink-800 hover:bg-ink-50">
                    {{ $opts[$$prop] ?? reset($opts) }}
                    <svg width="12" height="12" viewBox="0 0 20 20" fill="none"><path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <flux:menu>
                    @foreach ($opts as $val => $label)<flux:menu.item wire:click="$set('{{ $prop }}','{{ $val }}')" icon="{{ $$prop === $val ? 'check' : '' }}">{{ $label }}</flux:menu.item>@endforeach
                </flux:menu>
            </flux:dropdown>
        @endforeach
        <div class="ml-auto text-[13px] text-ink-500"><span class="font-mono font-semibold text-ink-900">{{ $fr($this->items->count()) }}</span> événements</div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1.5fr_1fr]">
        {{-- Alertes intelligentes --}}
        <div>
            <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Alertes intelligentes</div>
            <div class="flex flex-col gap-2.5">
                @forelse ($alerts as $n)
                    @php [$plabel, $pfg, $pbg] = $prio[$n->priority]; @endphp
                    <div class="rounded-[14px] border bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)] {{ $n->status === 'resolved' ? 'opacity-60' : '' }}" style="border-color: {{ $pfg }}25" wire:key="al-{{ $n->id }}">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full" style="background: {{ $pbg }}; color: {{ $pfg }}"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M10 6.5v4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="10" cy="14" r="1" fill="currentColor"/><path d="M8.6 3.5L2.5 15a1.5 1.5 0 001.3 2.2h12.4A1.5 1.5 0 0017.5 15L11.4 3.5a1.6 1.6 0 00-2.8 0z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-[13.5px] font-semibold text-ink-900">{!! $n->title !!}</span>
                                    <span class="rounded-full px-1.5 py-0.5 text-[10.5px] font-bold uppercase" style="background: {{ $pbg }}; color: {{ $pfg }}">{{ $plabel }}</span>
                                    @if ($n->status === 'resolved')<span class="rounded-full bg-success-soft px-1.5 py-0.5 text-[10.5px] font-bold uppercase text-success">Résolue</span>@endif
                                </div>
                                <div class="mt-0.5 text-[12.5px] leading-snug text-ink-600">{{ $n->description }}</div>
                                @if ($n->impact)<div class="mt-1 text-[11.5px] font-medium text-ink-500">Impact : {{ $n->impact }}</div>@endif
                                <div class="mt-2.5 flex flex-wrap items-center gap-2">
                                    @if ($n->link_route)
                                        <a href="{{ $n->link_param ? route($n->link_route, $n->link_param) : route($n->link_route) }}" wire:navigate class="inline-flex items-center gap-1 rounded-lg border border-ink-200 px-2.5 py-1 text-[12px] font-semibold text-ink-800 hover:bg-ink-50">
                                            {{ $n->action ?: 'Voir les détails' }}
                                            <svg width="12" height="12" viewBox="0 0 20 20" fill="none"><path d="M7 4l6 6-6 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </a>
                                    @endif
                                    @if ($n->status !== 'resolved')
                                        <button wire:click="resolve({{ $n->id }})" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-[12px] font-semibold text-success hover:bg-success-soft"><svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M5 10.5l3.5 3.5L15 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>Marquer résolue</button>
                                    @else
                                        <button wire:click="reopen({{ $n->id }})" class="text-[12px] font-semibold text-ink-500 hover:underline">Rouvrir</button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-[14px] border border-dashed border-ink-200 bg-white py-10 text-center text-[13px] text-ink-500">Aucune alerte à traiter. Tout est sous contrôle.</div>
                @endforelse
            </div>
        </div>

        {{-- Timeline des événements --}}
        <div>
            <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Timeline des événements</div>
            <div class="rounded-[16px] border border-ink-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                @forelse ($events as $n)
                    @php [$kl, $kc] = $kindMeta[$n->kind]; @endphp
                    <div class="relative flex gap-3 pb-4 last:pb-0" wire:key="ev-{{ $n->id }}">
                        @if (! $loop->last)<span class="absolute left-[13px] top-7 bottom-0 w-px bg-ink-150"></span>@endif
                        <span class="relative z-10 mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full" style="background: {{ $kc }}1A; color: {{ $kc }}"><svg width="13" height="13" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="3" fill="currentColor"/></svg></span>
                        <div class="min-w-0 flex-1">
                            <div class="text-[13px] font-semibold text-ink-900">{!! $n->title !!}</div>
                            <div class="text-[11.5px] text-ink-500">{{ $n->description }}</div>
                            <div class="mt-1 flex items-center gap-2 text-[10.5px] text-ink-400">
                                <span class="rounded bg-ink-100 px-1.5 py-0.5 font-semibold text-ink-500">{{ $moduleLabels[$n->module] ?? $n->module }}</span>
                                <span>{{ $n->detected_at?->locale('fr')->isoFormat('D MMM · HH:mm') }}</span>
                                @if ($n->link_route)<a href="{{ $n->link_param ? route($n->link_route, $n->link_param) : route($n->link_route) }}" wire:navigate class="font-semibold text-brand-600 hover:underline">Voir →</a>@endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center text-[13px] text-ink-500">Aucun événement.</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Préférences --}}
    @if ($prefsOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink-900/40" wire:click="$set('prefsOpen', false)"></div>
            <div class="relative w-full max-w-[520px] rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <div class="text-[15px] font-bold text-ink-900">Préférences des notifications</div>
                    <button wire:click="$set('prefsOpen', false)" class="flex h-8 w-8 items-center justify-center rounded-lg text-ink-500 hover:bg-ink-100"><svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button>
                </div>
                <div class="mb-4">
                    <div class="mb-2 text-[12px] font-bold uppercase tracking-wide text-ink-500">Types suivis</div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach (['Adoption', 'Campagnes', 'Revenus', 'Paiements', 'Nouveaux parents', 'Erreurs', 'Synchronisations'] as $t)
                            <span class="rounded-lg border border-brand-600 bg-brand-50 px-2.5 py-1 text-[12px] font-semibold text-brand-700">{{ $t }}</span>
                        @endforeach
                    </div>
                </div>
                <div class="mb-4">
                    <div class="mb-2 text-[12px] font-bold uppercase tracking-wide text-ink-500">Canaux</div>
                    <div class="flex flex-wrap gap-1.5">
                        <span class="rounded-lg border border-brand-600 bg-brand-50 px-2.5 py-1 text-[12px] font-semibold text-brand-700">Interface</span>
                        @foreach (['E-mail', 'Push', 'WhatsApp'] as $ch)
                            <span class="inline-flex items-center gap-1 rounded-lg border border-dashed border-ink-200 bg-ink-50 px-2.5 py-1 text-[12px] font-medium text-ink-400">{{ $ch }}<span class="rounded bg-ink-100 px-1 text-[9px] font-bold uppercase">prévu</span></span>
                        @endforeach
                    </div>
                </div>
                <div>
                    <div class="mb-2 text-[12px] font-bold uppercase tracking-wide text-ink-500">Fréquence</div>
                    <div class="flex gap-1.5">
                        <span class="rounded-lg border border-brand-600 bg-brand-50 px-3 py-1 text-[12px] font-semibold text-brand-700">Immédiate</span>
                        <span class="rounded-lg border border-ink-200 px-3 py-1 text-[12px] font-medium text-ink-500">Quotidienne</span>
                        <span class="rounded-lg border border-ink-200 px-3 py-1 text-[12px] font-medium text-ink-500">Hebdomadaire</span>
                    </div>
                </div>
                <div class="mt-5 rounded-lg bg-warning-soft/70 px-3 py-2 text-[11.5px] text-warning">Les notifications en interface sont actives. Les canaux e-mail / push / WhatsApp et la diffusion planifiée nécessitent l'infrastructure correspondante (à venir).</div>
            </div>
        </div>
    @endif
</div>
