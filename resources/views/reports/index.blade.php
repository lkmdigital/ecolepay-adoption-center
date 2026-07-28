<?php

use App\Domains\Reports\Actions\BuildReport;
use App\Domains\Reports\Models\Report;
use App\Domains\Schools\Models\School;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $wizardOpen = false;

    public int $wStep = 1;

    public string $wType = 'executif';

    public string $wName = '';

    public string $wPeriod = 'school_year';

    public ?int $wSchool = null;

    public string $wModel = '';

    public array $wIndicators = ['adopt', 'reg', 'act', 'eng'];

    public ?int $scheduleFor = null;

    public string $sFreq = 'weekly';

    public array $sRecipients = ['Direction'];

    public array $sFormats = ['PDF'];

    #[Computed]
    public function reports(): Collection
    {
        return Report::query()->orderByDesc('is_favorite')->orderByDesc('updated_at')->get();
    }

    #[Computed]
    public function counts(): array
    {
        return [
            'total' => Report::count(),
            'scheduled' => Report::whereNotNull('schedule')->count(),
            'thisMonth' => Report::where('created_at', '>=', now()->startOfMonth())->count(),
            'favorites' => Report::where('is_favorite', true)->count(),
        ];
    }

    public function schools(): array
    {
        return School::query()->where('is_test', false)->current()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function generateExec()
    {
        $report = Report::create(['name' => 'Rapport Exécutif — '.now()->locale('fr')->isoFormat('D MMM YYYY'), 'type' => 'executif', 'period' => 'school_year', 'last_generated_at' => now()]);

        return $this->redirect(route('reports.show', $report), navigate: true);
    }

    public function openWizard(?string $type = null): void
    {
        $this->reset(['wStep', 'wName', 'wPeriod', 'wSchool', 'wModel', 'wIndicators']);
        $this->wIndicators = ['adopt', 'reg', 'act', 'eng'];
        $this->wPeriod = 'school_year';
        if ($type) {
            $this->wType = $type;
            $this->wStep = 2;
        } else {
            $this->wStep = 1;
        }
        $this->wizardOpen = true;
    }

    public function pickTemplate(string $type): void
    {
        if (! (BuildReport::TEMPLATES[$type]['available'] ?? false)) {
            return;
        }
        $this->wType = $type;
        $this->wStep = 2;
    }

    public function createReport()
    {
        $this->validate(['wName' => 'required|min:3']);
        if ($this->wType === 'ecole' && ! $this->wSchool) {
            $this->addError('wSchool', 'Sélectionnez une école.');

            return;
        }
        $report = Report::create([
            'name' => $this->wName, 'type' => $this->wType, 'period' => $this->wPeriod,
            'school_id' => $this->wSchool, 'subscription_model' => $this->wModel ?: null,
            'indicators' => $this->wType === 'personnalise' ? array_values($this->wIndicators) : null,
            'last_generated_at' => now(),
        ]);

        return $this->redirect(route('reports.show', $report), navigate: true);
    }

    public function toggleFavorite(int $id): void
    {
        $r = Report::find($id);
        $r?->update(['is_favorite' => ! $r->is_favorite]);
        unset($this->reports, $this->counts);
    }

    public function duplicate(int $id): void
    {
        $r = Report::find($id);
        if ($r) {
            $copy = $r->replicate();
            $copy->name = $r->name.' (copie)';
            $copy->is_favorite = false;
            $copy->save();
        }
        unset($this->reports, $this->counts);
    }

    public function deleteReport(int $id): void
    {
        Report::whereKey($id)->delete();
        unset($this->reports, $this->counts);
    }

    public function openSchedule(int $id): void
    {
        $r = Report::find($id);
        $s = $r?->schedule ?? [];
        $this->scheduleFor = $id;
        $this->sFreq = $s['frequency'] ?? 'weekly';
        $this->sRecipients = $s['recipients'] ?? ['Direction'];
        $this->sFormats = $s['formats'] ?? ['PDF'];
    }

    public function saveSchedule(): void
    {
        Report::whereKey($this->scheduleFor)->update(['schedule' => ['frequency' => $this->sFreq, 'recipients' => $this->sRecipients, 'formats' => $this->sFormats]]);
        $this->scheduleFor = null;
        unset($this->reports, $this->counts);
    }

    public function clearSchedule(int $id): void
    {
        Report::whereKey($id)->update(['schedule' => null]);
        $this->scheduleFor = null;
        unset($this->reports, $this->counts);
    }
};

?>

@php
    $fr = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $dateFr = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->locale('fr')->isoFormat('D MMM YYYY') : '—';
    $TPL = App\Domains\Reports\Actions\BuildReport::TEMPLATES;
    $PERIODS = App\Domains\Reports\Actions\BuildReport::PERIODS;
    $tplIcons = [
        'star' => '<path d="M10 2l2.2 5.3L18 8l-4 3.9.9 5.6L10 15l-4.9 2.5.9-5.6L2 8l5.8-.7z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>',
        'target' => '<circle cx="6" cy="6" r="2.3" stroke="currentColor" stroke-width="1.6"/><circle cx="14" cy="14" r="2.3" stroke="currentColor" stroke-width="1.6"/><line x1="15" y1="5" x2="5" y2="15" stroke="currentColor" stroke-width="1.6"/>',
        'school' => '<polygon points="10,2 17,7 3,7" fill="currentColor"/><rect x="4" y="7.5" width="12" height="9.5" rx="1" stroke="currentColor" stroke-width="1.6" fill="none"/>',
        'megaphone' => '<rect x="2.5" y="4" width="15" height="10" rx="3" stroke="currentColor" stroke-width="1.6"/><polygon points="6,14 6,18 10,14" fill="currentColor"/>',
        'money' => '<circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.6"/><path d="M10 6.5v7M12.2 8.2c0-.9-1-1.5-2.2-1.5s-2.2.7-2.2 1.6c0 2.2 4.4 1 4.4 3.2 0 .9-1 1.6-2.2 1.6s-2.2-.6-2.2-1.5" stroke="currentColor" stroke-width="1.3"/>',
        'sliders' => '<path d="M4 6h8M15 6h1M4 10h1M8 10h8M4 14h5M12 14h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="13" cy="6" r="1.6" fill="currentColor"/><circle cx="6.5" cy="10" r="1.6" fill="currentColor"/><circle cx="10.5" cy="14" r="1.6" fill="currentColor"/>',
        'users' => '<circle cx="7.2" cy="6.5" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M2.5 17c0-3 2.1-5 4.7-5s4.7 2 4.7 5" stroke="currentColor" stroke-width="1.6"/>',
        'map' => '<path d="M7 3L2.5 5v12L7 15l6 2 4.5-2V3L13 5 7 3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M7 3v12M13 5v12" stroke="currentColor" stroke-width="1.3"/>',
    ];
    $indicatorLabels = ['connus' => 'Parents connus', 'inscrits' => 'Parents inscrits', 'adoptants' => 'Parents adoptants', 'eng' => 'Parents engagés', 'reg' => "Taux d'inscription", 'act' => "Taux d'activation", 'adopt' => "Taux d'adoption", 'arpa' => 'Revenu / adoptant', 'pot' => 'Potentiel restant'];
    $freqLabels = ['daily' => 'Quotidienne', 'weekly' => 'Hebdomadaire', 'monthly' => 'Mensuelle'];
    $c = $this->counts;
@endphp

<div class="mx-auto max-w-[1480px]">

    <div class="mb-5 flex flex-wrap items-center justify-end gap-2">
        <button wire:click="openWizard" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3.5 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            Nouveau rapport
        </button>
        <button wire:click="$refresh" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">Actualiser</button>
    </div>

    {{-- Cartes de synthèse --}}
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([['Rapports générés', $fr($c['total']), '#2554C7', '#EEF3FE'], ['Rapports planifiés', $fr($c['scheduled']), '#B45F04', '#FEF3E2'], ['Générés ce mois', $fr($c['thisMonth']), '#0F7A44', '#E9F8EF'], ['Favoris', $fr($c['favorites']), '#B45309', '#FEF9E7']] as [$label, $val, $fg, $bg])
            <div class="rounded-[13px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="flex h-8 w-8 items-center justify-center rounded-[9px]" style="background: {{ $bg }}; color: {{ $fg }}"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><rect x="4" y="2.5" width="12" height="15" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M7 7h6M7 10h6M7 13h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></div>
                <div class="mt-2.5 text-[22px] font-bold tracking-tight text-ink-900">{{ $val }}</div>
                <div class="text-[12px] font-semibold text-ink-800">{{ $label }}</div>
            </div>
        @endforeach
    </div>

    {{-- Rapport Exécutif Intelligent (one-click) --}}
    <div class="mb-6 overflow-hidden rounded-[20px] text-white shadow-[0_12px_40px_rgba(23,60,130,0.28)]" style="background: radial-gradient(120% 140% at 0% 0%, #2E5BD0 0%, #173C82 55%, #102C61 100%)">
        <div class="flex flex-wrap items-center justify-between gap-4 px-8 py-6">
            <div class="flex items-start gap-4">
                <span class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-2xl bg-white/15"><svg width="24" height="24" viewBox="0 0 20 20" fill="none" class="text-white"><path d="M10 2l2.2 5.3L18 8l-4 3.9.9 5.6L10 15l-4.9 2.5.9-5.6L2 8l5.8-.7z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg></span>
                <div>
                    <div class="text-[18px] font-bold">Rapport Exécutif Intelligent</div>
                    <div class="mt-1 max-w-xl text-[13px] text-white/75">En un clic : synthèse des KPI, évolutions, meilleures/moins bonnes écoles, campagnes efficaces, opportunités de revenus, anomalies et recommandations prioritaires — pour comprendre la situation en quelques pages.</div>
                </div>
            </div>
            <button wire:click="generateExec" wire:loading.attr="disabled" class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-[13px] font-bold text-[#173C82] transition-transform hover:scale-[1.02]">
                <span wire:loading.remove wire:target="generateExec">Générer maintenant</span>
                <span wire:loading wire:target="generateExec">Génération…</span>
                <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M7 4l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
        </div>
    </div>

    {{-- Bibliothèque de modèles --}}
    <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Bibliothèque de modèles</div>
    <div class="mb-8 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        @foreach ($TPL as $key => $t)
            <div class="flex flex-col rounded-[14px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)] {{ $t['available'] ? '' : 'opacity-60' }}">
                <div class="flex h-9 w-9 items-center justify-center rounded-[10px] bg-brand-50 text-brand-600"><svg width="18" height="18" viewBox="0 0 20 20" fill="none">{!! $tplIcons[$t['icon']] !!}</svg></div>
                <div class="mt-2.5 text-[14px] font-bold text-ink-900">{{ $t['label'] }}</div>
                <div class="mt-0.5 flex-1 text-[12px] text-ink-500">{{ $t['desc'] }}</div>
                @if ($t['available'])
                    <button wire:click="openWizard('{{ $key }}')" class="mt-3 inline-flex items-center justify-center gap-1 rounded-lg border border-ink-200 px-3 py-1.5 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">Utiliser</button>
                @else
                    <div class="mt-3 rounded-lg border border-dashed border-ink-200 bg-ink-50 px-3 py-1.5 text-center text-[11.5px] text-ink-400" title="{{ $t['note'] ?? '' }}">{{ $t['note'] ?? 'À venir' }}</div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Historique --}}
    <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.08em] text-ink-500">Historique des rapports</div>
    @if ($this->reports->isEmpty())
        <div class="rounded-[16px] border border-dashed border-ink-300 bg-white py-14 text-center">
            <div class="text-[14px] font-semibold text-ink-800">Aucun rapport pour le moment</div>
            <div class="mt-1 text-[12.5px] text-ink-500">Générez le Rapport Exécutif ou partez d'un modèle.</div>
        </div>
    @else
        <div class="overflow-x-auto rounded-[14px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-ink-50 text-[11px] font-bold uppercase tracking-wider text-ink-500">
                        <th class="px-5 py-3 text-left">Nom</th><th class="px-3 py-3 text-left">Type</th><th class="px-3 py-3 text-left">Période</th>
                        <th class="px-3 py-3 text-left">Générés</th><th class="px-3 py-3 text-left">Planification</th><th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->reports as $r)
                        <tr wire:key="rep-{{ $r->id }}" class="border-b border-ink-150 last:border-0 hover:bg-ink-50">
                            <td class="px-5 py-3">
                                <a href="{{ route('reports.show', $r) }}" wire:navigate class="flex items-center gap-2 text-[13.5px] font-semibold text-ink-900 hover:text-brand-700">
                                    @if ($r->is_favorite)<svg width="14" height="14" viewBox="0 0 20 20" fill="#B45309"><path d="M10 2l2.2 5.3L18 8l-4 3.9.9 5.6L10 15l-4.9 2.5.9-5.6L2 8l5.8-.7z"/></svg>@endif
                                    {{ $r->name }}
                                </a>
                            </td>
                            <td class="px-3 py-3 text-[12.5px] text-ink-600">{{ $TPL[$r->type]['label'] ?? $r->type }}</td>
                            <td class="px-3 py-3 text-[12.5px] text-ink-600">{{ $PERIODS[$r->period] ?? '—' }}</td>
                            <td class="px-3 py-3 text-[12px] text-ink-500">{{ $dateFr($r->last_generated_at) }}</td>
                            <td class="px-3 py-3 text-[12px]">
                                @if ($r->schedule)<span class="rounded-full bg-warning-soft px-2 py-0.5 font-semibold text-warning">{{ $freqLabels[$r->schedule['frequency']] ?? 'Planifié' }}</span>@else<span class="text-ink-400">—</span>@endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <flux:dropdown>
                                    <button class="flex h-7 w-7 items-center justify-center rounded-md text-ink-500 hover:bg-ink-100"><svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><circle cx="10" cy="4.5" r="1.5"/><circle cx="10" cy="10" r="1.5"/><circle cx="10" cy="15.5" r="1.5"/></svg></button>
                                    <flux:menu>
                                        <flux:menu.item icon="eye" href="{{ route('reports.show', $r) }}" wire:navigate>Ouvrir</flux:menu.item>
                                        <flux:menu.item icon="star" wire:click="toggleFavorite({{ $r->id }})">{{ $r->is_favorite ? 'Retirer des favoris' : 'Ajouter aux favoris' }}</flux:menu.item>
                                        <flux:menu.item icon="document-duplicate" wire:click="duplicate({{ $r->id }})">Dupliquer</flux:menu.item>
                                        <flux:menu.item icon="calendar" wire:click="openSchedule({{ $r->id }})">Planifier l'envoi</flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger" wire:click="deleteReport({{ $r->id }})">Supprimer</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ═══════ Wizard création ═══════ --}}
    <div x-data="{ open: @entangle('wizardOpen') }" x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
        <div class="absolute inset-0 bg-ink-900/40" @click="$wire.set('wizardOpen', false)"></div>
        <div class="relative flex max-h-[90vh] w-full max-w-[620px] flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-ink-150 px-6 py-4">
                <div class="text-[15px] font-bold text-ink-900">Nouveau rapport</div>
                <button wire:click="$set('wizardOpen', false)" class="flex h-8 w-8 items-center justify-center rounded-lg text-ink-500 hover:bg-ink-100"><svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button>
            </div>
            <div class="flex-1 overflow-y-auto px-6 py-5">
                @if ($wStep === 1)
                    <div class="mb-3 text-[12.5px] font-semibold text-ink-700">Choisissez un modèle</div>
                    <div class="grid grid-cols-2 gap-2.5">
                        @foreach ($TPL as $key => $t)
                            @if ($t['available'])
                                <button wire:click="pickTemplate('{{ $key }}')" class="flex items-start gap-2.5 rounded-xl border border-ink-200 p-3 text-left hover:border-brand-600 hover:bg-brand-50/40">
                                    <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><svg width="16" height="16" viewBox="0 0 20 20" fill="none">{!! $tplIcons[$t['icon']] !!}</svg></span>
                                    <span><span class="block text-[13px] font-semibold text-ink-900">{{ $t['label'] }}</span><span class="block text-[11.5px] text-ink-500">{{ $t['desc'] }}</span></span>
                                </button>
                            @endif
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-col gap-3.5">
                        <div class="rounded-xl bg-brand-50 px-3.5 py-2.5 text-[12.5px] text-ink-700">Modèle : <span class="font-semibold text-ink-900">{{ $TPL[$wType]['label'] }}</span></div>
                        <div>
                            <label class="mb-1 block text-[12.5px] font-semibold text-ink-700">Nom du rapport</label>
                            <input wire:model="wName" placeholder="Ex. Adoption — Juillet 2026" class="w-full rounded-lg border border-ink-300 px-3 py-2 text-[13.5px] outline-none focus:border-brand-600">
                            @error('wName')<span class="text-[11.5px] text-danger">{{ $message }}</span>@enderror
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-[12.5px] font-semibold text-ink-700">Période</label>
                                <select wire:model="wPeriod" class="w-full rounded-lg border border-ink-300 px-3 py-2 text-[13.5px] outline-none focus:border-brand-600">
                                    @foreach ($PERIODS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-[12.5px] font-semibold text-ink-700">Mode d'abonnement</label>
                                <select wire:model="wModel" class="w-full rounded-lg border border-ink-300 px-3 py-2 text-[13.5px] outline-none focus:border-brand-600">
                                    <option value="">Tous</option>
                                    <option value="parent_paid">Payé par les parents</option>
                                    <option value="bundled">Intégré à la scolarité</option>
                                </select>
                            </div>
                            <div class="col-span-2">
                                <label class="mb-1 block text-[12.5px] font-semibold text-ink-700">École @if ($wType === 'ecole')<span class="text-danger">*</span>@else<span class="text-ink-400">(optionnel)</span>@endif</label>
                                <select wire:model="wSchool" class="w-full rounded-lg border border-ink-300 px-3 py-2 text-[13.5px] outline-none focus:border-brand-600">
                                    <option value="">Toutes les écoles</option>
                                    @foreach ($this->schools() as $id => $sname)<option value="{{ $id }}">{{ $sname }}</option>@endforeach
                                </select>
                                @error('wSchool')<span class="text-[11.5px] text-danger">{{ $message }}</span>@enderror
                            </div>
                        </div>
                        @if ($wType === 'personnalise')
                            <div>
                                <label class="mb-1 block text-[12.5px] font-semibold text-ink-700">Indicateurs</label>
                                <div class="grid grid-cols-2 gap-1.5">
                                    @foreach ($indicatorLabels as $key => $label)
                                        <button wire:click="$toggle('wIndicators.{{ $key }}')" type="button" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-left text-[12.5px] {{ in_array($key, $wIndicators) ? 'text-ink-900' : 'text-ink-500 hover:bg-ink-100' }}">
                                            <span class="flex h-4 w-4 items-center justify-center rounded border {{ in_array($key, $wIndicators) ? 'border-brand-600 bg-brand-600 text-white' : 'border-ink-300' }}">@if (in_array($key, $wIndicators))<svg width="11" height="11" viewBox="0 0 20 20" fill="none"><path d="M5 10.5l3.5 3.5L15 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>@endif</span>
                                            {{ $label }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
            @if ($wStep === 2)
                <div class="flex items-center justify-between border-t border-ink-150 px-6 py-4">
                    <button wire:click="$set('wStep', 1)" class="rounded-lg px-3 py-2 text-[12.5px] font-semibold text-ink-600 hover:bg-ink-100">Retour</button>
                    <button wire:click="createReport" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700">Générer le rapport</button>
                </div>
            @endif
        </div>
    </div>

    {{-- ═══════ Planification ═══════ --}}
    @if ($scheduleFor)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink-900/40" wire:click="$set('scheduleFor', null)"></div>
            <div class="relative w-full max-w-[460px] rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-1 text-[15px] font-bold text-ink-900">Planifier l'envoi</div>
                <div class="mb-4 rounded-lg bg-warning-soft/70 px-3 py-2 text-[11.5px] text-warning">La configuration est enregistrée, mais la diffusion automatique par e-mail nécessite l'infrastructure mail (à venir).</div>
                <div class="mb-3">
                    <label class="mb-1 block text-[12px] font-semibold text-ink-700">Fréquence</label>
                    <div class="flex gap-1.5">
                        @foreach (['daily' => 'Quotidienne', 'weekly' => 'Hebdomadaire', 'monthly' => 'Mensuelle'] as $k => $l)
                            <button wire:click="$set('sFreq','{{ $k }}')" class="flex-1 rounded-lg border px-2 py-1.5 text-[12px] font-semibold {{ $sFreq === $k ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-600 hover:bg-ink-50' }}">{{ $l }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="mb-3">
                    <label class="mb-1 block text-[12px] font-semibold text-ink-700">Destinataires</label>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach (['Direction', 'Commercial', 'Marketing', 'Comptabilité'] as $rec)
                            <button wire:click="$toggle('sRecipients.{{ $loop->index }}')" type="button" class="rounded-lg border px-2.5 py-1 text-[12px] font-medium {{ in_array($rec, $sRecipients) ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-600' }}" wire:key="rec-{{ $rec }}">{{ $rec }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="mb-5">
                    <label class="mb-1 block text-[12px] font-semibold text-ink-700">Formats</label>
                    <div class="flex gap-1.5">
                        @foreach (['PDF', 'Excel', 'CSV'] as $fmt)
                            <button wire:click="$toggle('sFormats.{{ $loop->index }}')" type="button" class="rounded-lg border px-3 py-1 text-[12px] font-medium {{ in_array($fmt, $sFormats) ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-600' }}" wire:key="fmt-{{ $fmt }}">{{ $fmt }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center justify-between">
                    <button wire:click="clearSchedule({{ $scheduleFor }})" class="text-[12.5px] font-semibold text-danger hover:underline">Retirer la planification</button>
                    <div class="flex gap-2">
                        <button wire:click="$set('scheduleFor', null)" class="rounded-lg px-3 py-2 text-[12.5px] font-semibold text-ink-600 hover:bg-ink-100">Annuler</button>
                        <button wire:click="saveSchedule" class="rounded-lg bg-brand-600 px-4 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700">Enregistrer</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
