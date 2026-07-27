<?php

use App\Domains\Campaigns\Actions\ImportCampaignContacts;
use App\Domains\Campaigns\Actions\ListCampaigns;
use App\Domains\Campaigns\Enums\CampaignChannel;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\Campaign;
use App\Domains\Campaigns\Support\ContactFileParser;
use App\Domains\Schools\Models\School;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $channel = '';

    // --- Wizard ---
    public bool $wizardOpen = false;

    public int $step = 1;

    public string $name = '';

    public string $description = '';

    public ?int $school_id = null;

    public string $owner = '';

    public string $wChannel = 'whatsapp';

    public string $date = '';

    public $file;

    public ?array $preview = null;

    public ?int $createdId = null;

    #[Computed]
    public function data(): array
    {
        return app(ListCampaigns::class)();
    }

    #[Computed]
    public function rows(): Collection
    {
        $term = trim(mb_strtolower($this->search));

        return $this->data['rows']
            ->when($term !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower($r['name'].' '.$r['school']), $term)))
            ->when($this->status !== '', fn ($c) => $c->filter(fn ($r) => $r['status']->value === $this->status))
            ->when($this->channel !== '', fn ($c) => $c->filter(fn ($r) => $r['channel']->value === $this->channel))
            ->values();
    }

    public function schools(): array
    {
        return School::query()->where('is_test', false)->current()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function openWizard(): void
    {
        $this->reset(['step', 'name', 'description', 'school_id', 'owner', 'wChannel', 'date', 'file', 'preview', 'createdId']);
        $this->step = 1;
        $this->wChannel = 'whatsapp';
        $this->date = now()->toDateString();
        $this->wizardOpen = true;
    }

    public function closeWizard(): void
    {
        $this->wizardOpen = false;
    }

    public function contactBased(): bool
    {
        return CampaignChannel::from($this->wChannel)->isContactBased();
    }

    public function proceedFromInfo(): void
    {
        $this->validate(['name' => 'required|min:3', 'owner' => 'required', 'date' => 'required|date']);

        // Les actions terrain / de diffusion se mesurent au niveau de l'école : une école est requise.
        if (! $this->contactBased() && ! $this->school_id) {
            $this->addError('school_id', 'Une école est requise pour mesurer l\'impact d\'une action sans liste de contacts.');

            return;
        }

        // Les canaux à liste passent par l'import ; les autres vont droit à la validation.
        $this->step = $this->contactBased() ? 2 : 3;
    }

    public function updatedFile(): void
    {
        $this->validate(['file' => 'file|max:10240|mimes:csv,txt,xlsx,xls']);
        $this->preview = app(ContactFileParser::class)->parse($this->file->getRealPath());
    }

    public function toStep3(): void
    {
        if (! $this->preview || $this->preview['valid'] === 0) {
            $this->addError('file', 'Aucun numéro valide détecté dans le fichier.');

            return;
        }
        $this->step = 3;
    }

    public function backFromValidation(): void
    {
        $this->step = $this->contactBased() ? 2 : 1;
    }

    public function launchImport(): void
    {
        $this->step = 4;

        $campaign = Campaign::create([
            'name' => $this->name,
            'slug' => Str::slug($this->name).'-'.Str::random(5),
            'description' => $this->description ?: null,
            'school_id' => $this->school_id,
            'owner' => $this->owner,
            'channel' => $this->wChannel,
            'status' => 'completed',
            'campaign_date' => $this->date,
            'attribution_window_days' => 30,
        ]);

        // Import des contacts seulement pour les canaux à liste.
        if ($this->contactBased() && $this->file) {
            $parsed = app(ContactFileParser::class)->parse($this->file->getRealPath());
            app(ImportCampaignContacts::class)->handle($campaign, $parsed['contacts'], $parsed);
        }

        $this->createdId = $campaign->id;
        unset($this->data, $this->rows);
    }

    public function refreshData(): void
    {
        unset($this->data, $this->rows);
    }
};

?>

@php
    $fr = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $money = fn ($n) => $n >= 1_000_000 ? number_format($n / 1_000_000, 1, ',', ' ').' M F' : $fr($n).' F';
    $k = $this->data['kpis'];
    $kpiCards = [
        ['Campagnes', $fr($k['campaigns']), 'total menées', 'var(--color-brand-50)', 'var(--color-brand-600)'],
        ['Contacts importés', $fr($k['contacts']), 'numéros ciblés', 'var(--color-ink-100)', 'var(--color-ink-800)'],
        ['Nouveaux comptes', $fr($k['newAccounts']), 'créés après campagne', 'var(--color-brand-50)', 'var(--color-brand-600)'],
        ['Nouveaux adoptants', $fr($k['newActive']), 'premier paiement attribué', 'var(--color-success-soft)', 'var(--color-success)'],
        ['Taux de conversion', number_format($k['conversion'], 1, ',', ' ').' %', 'contacts → adoptants', 'var(--color-warning-soft)', 'var(--color-warning)'],
        ['Revenus générés', $money($k['revenue']), 'attribués aux campagnes', 'var(--color-success-soft)', 'var(--color-success)'],
    ];
@endphp

<div class="mx-auto max-w-[1480px]">

    {{-- Actions header --}}
    <div class="mb-5 flex flex-wrap items-center justify-end gap-2">
        <button wire:click="openWizard" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3.5 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            Nouvelle opération marketing
        </button>
        <button wire:click="refreshData" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-50">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none" wire:loading.class="animate-spin" wire:target="refreshData"><path d="M16 6a7 7 0 10.9 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M16.5 3v3.2h-3.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Actualiser
        </button>
    </div>

    {{-- KPI marketing --}}
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
        @foreach ($kpiCards as [$label, $value, $sub, $bg, $fg])
            <div class="rounded-[13px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="flex h-8 w-8 items-center justify-center rounded-[9px]" style="background: {{ $bg }}; color: {{ $fg }}">
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><rect x="2.5" y="4" width="15" height="10" rx="3" stroke="currentColor" stroke-width="1.5"/><polygon points="6,14 6,18 10,14" fill="currentColor"/></svg>
                </div>
                <div class="mt-2.5 text-[20px] font-bold tracking-tight text-ink-900">{{ $value }}</div>
                <div class="text-[12px] font-semibold text-ink-800">{{ $label }}</div>
                <div class="text-[11px] text-ink-500">{{ $sub }}</div>
            </div>
        @endforeach
    </div>

    {{-- Filtres --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="flex min-w-[220px] flex-1 items-center gap-2 rounded-lg border border-ink-300 bg-white px-3 py-2 focus-within:border-brand-600">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-500"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="M17 17l-3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            <input wire:model.live.debounce.250ms="search" placeholder="Rechercher une campagne, une école…" class="w-full border-none bg-transparent text-[13.5px] text-ink-900 outline-none placeholder:text-ink-500">
        </div>
        <flux:dropdown>
            <button class="inline-flex items-center gap-2 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold text-ink-800 hover:bg-ink-50">
                {{ $status ? CampaignStatus::from($status)->label() : 'Tous statuts' }}
                <svg width="12" height="12" viewBox="0 0 20 20" fill="none"><path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <flux:menu>
                <flux:menu.item wire:click="$set('status','')" icon="{{ $status === '' ? 'check' : '' }}">Tous statuts</flux:menu.item>
                @foreach (CampaignStatus::cases() as $st)
                    <flux:menu.item wire:click="$set('status','{{ $st->value }}')" icon="{{ $status === $st->value ? 'check' : '' }}">{{ $st->label() }}</flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
        <flux:dropdown>
            <button class="inline-flex items-center gap-2 rounded-lg border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold text-ink-800 hover:bg-ink-50">
                {{ $channel ? CampaignChannel::from($channel)->label() : 'Tous types' }}
                <svg width="12" height="12" viewBox="0 0 20 20" fill="none"><path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <flux:menu>
                <flux:menu.item wire:click="$set('channel','')" icon="{{ $channel === '' ? 'check' : '' }}">Tous types</flux:menu.item>
                @foreach (collect(CampaignChannel::cases())->groupBy(fn ($ch) => $ch->category()) as $cat => $chs)
                    <flux:menu.separator />
                    <div class="px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-ink-400">{{ $cat }}</div>
                    @foreach ($chs as $ch)
                        <flux:menu.item wire:click="$set('channel','{{ $ch->value }}')" icon="{{ $channel === $ch->value ? 'check' : '' }}">{{ $ch->label() }}</flux:menu.item>
                    @endforeach
                @endforeach
            </flux:menu>
        </flux:dropdown>
    </div>

    {{-- Tableau / états --}}
    @if ($this->data['kpis']['campaigns'] === 0)
        <div class="flex flex-col items-center gap-3 rounded-[16px] border border-dashed border-ink-300 bg-white py-16 text-center">
            <svg width="38" height="38" viewBox="0 0 20 20" fill="none" class="text-ink-300"><rect x="2.5" y="4" width="15" height="10" rx="3" stroke="currentColor" stroke-width="1.4"/><polygon points="6,14 6,18 10,14" fill="currentColor"/></svg>
            <div class="text-[15px] font-semibold text-ink-800">Aucune opération pour le moment</div>
            <div class="max-w-md text-[12.5px] text-ink-500">Enregistrez votre première opération marketing — canal digital (WhatsApp, SMS, Email) avec import de contacts, ou action terrain (portes ouvertes, réunion, formation) — pour en mesurer l'impact sur l'adoption.</div>
            <button wire:click="openWizard" class="mt-1 inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700">Créer une opération</button>
        </div>
    @elseif ($this->rows->isEmpty())
        <div class="rounded-[16px] border border-dashed border-ink-300 bg-white py-14 text-center text-[13px] text-ink-500">Aucune campagne ne correspond aux filtres.</div>
    @else
        <div class="overflow-x-auto rounded-[14px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-ink-50 text-[11px] font-bold uppercase tracking-wider text-ink-500">
                        <th class="px-5 py-3 text-left">Campagne</th>
                        <th class="px-3 py-3 text-left">École</th>
                        <th class="px-3 py-3 text-left">Date</th>
                        <th class="px-3 py-3 text-right">Contacts</th>
                        <th class="px-3 py-3 text-right">Comptes</th>
                        <th class="px-3 py-3 text-right">Adoptants</th>
                        <th class="px-3 py-3 text-right">Conversion</th>
                        <th class="px-3 py-3 text-right">Revenus</th>
                        <th class="px-3 py-3 text-left">Statut</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->rows as $r)
                        @php [$sfg, $sbg] = $r['status']->colors(); @endphp
                        <tr wire:key="camp-{{ $r['id'] }}" class="cursor-pointer border-b border-ink-150 last:border-0 hover:bg-brand-50/40" onclick="window.location='{{ route('campaigns.show', $r['id']) }}'">
                            <td class="px-5 py-3">
                                <div class="text-[13.5px] font-semibold text-ink-900">{{ $r['name'] }}</div>
                                <div class="text-[11.5px] text-ink-500">{{ $r['channel']->label() }} · {{ $r['owner'] ?: '—' }}</div>
                            </td>
                            <td class="px-3 py-3 text-[13px] text-ink-700">{{ $r['school'] ?: '—' }}</td>
                            <td class="px-3 py-3 text-[12.5px] text-ink-600">{{ $r['date'] ? \Illuminate\Support\Carbon::parse($r['date'])->locale('fr')->isoFormat('D MMM YYYY') : '—' }}</td>
                            <td class="px-3 py-3 text-right font-mono text-[13px] text-ink-700">{{ $r['channel']->isContactBased() ? $fr($r['contacts']) : '—' }}</td>
                            <td class="px-3 py-3 text-right font-mono text-[13px] text-ink-700">{{ $fr($r['newAccounts']) }}</td>
                            <td class="px-3 py-3 text-right font-mono text-[13px] font-semibold text-ink-900">{{ $fr($r['newPayments']) }}</td>
                            <td class="px-3 py-3 text-right font-mono text-[13px] font-bold text-brand-700">{{ number_format($r['conversion'], 1, ',', ' ') }} %</td>
                            <td class="px-3 py-3 text-right font-mono text-[12.5px] text-ink-700">{{ $r['revenue'] > 0 ? $money($r['revenue']) : '—' }}</td>
                            <td class="px-3 py-3"><span class="inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-[11.5px] font-semibold" style="background: {{ $sbg }}; color: {{ $sfg }}">{{ $r['status']->label() }}</span></td>
                            <td class="px-4 py-3 text-right">
                                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" class="text-ink-400"><path d="M7 4l6 6-6 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ═══════════ WIZARD ═══════════ --}}
    <div x-data="{ open: @entangle('wizardOpen') }" x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
        <div class="absolute inset-0 bg-ink-900/40" @click="$wire.closeWizard()"></div>
        <div class="relative flex max-h-[90vh] w-full max-w-[640px] flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
            {{-- Steps header --}}
            <div class="flex items-center justify-between border-b border-ink-150 px-6 py-4">
                <div class="text-[15px] font-bold text-ink-900">Nouvelle opération marketing</div>
                <button wire:click="closeWizard" class="flex h-8 w-8 items-center justify-center rounded-lg text-ink-500 hover:bg-ink-100">
                    <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                </button>
            </div>
            @php $wizSteps = $this->contactBased() ? [1 => 'Informations', 2 => 'Contacts', 3 => 'Validation', 4 => 'Terminé'] : [1 => 'Informations', 3 => 'Validation', 4 => 'Terminé']; @endphp
            <div class="flex items-center gap-1.5 px-6 pt-4">
                @foreach ($wizSteps as $num => $label)
                    <div class="flex flex-1 items-center gap-1.5">
                        <span class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full text-[11px] font-bold {{ $step > $num ? 'bg-success text-white' : ($step === $num ? 'bg-brand-600 text-white' : 'bg-ink-100 text-ink-500') }}">{{ $step > $num ? '✓' : $loop->iteration }}</span>
                        <span class="text-[11.5px] font-semibold {{ $step >= $num ? 'text-ink-800' : 'text-ink-400' }}">{{ $label }}</span>
                        @if (! $loop->last)<span class="h-px flex-1 bg-ink-150"></span>@endif
                    </div>
                @endforeach
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-5">
                {{-- Étape 1 : Informations --}}
                @if ($step === 1)
                    <div class="flex flex-col gap-3.5">
                        <div>
                            <label class="mb-1 block text-[12.5px] font-semibold text-ink-700">Nom de la campagne</label>
                            <input wire:model="name" placeholder="Ex. Relance inscription — Jean Mermoz" class="w-full rounded-lg border border-ink-300 px-3 py-2 text-[13.5px] outline-none focus:border-brand-600">
                            @error('name')<span class="text-[11.5px] text-danger">{{ $message }}</span>@enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-[12.5px] font-semibold text-ink-700">Description <span class="text-ink-400">(optionnel)</span></label>
                            <textarea wire:model="description" rows="2" class="w-full rounded-lg border border-ink-300 px-3 py-2 text-[13.5px] outline-none focus:border-brand-600"></textarea>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-[12.5px] font-semibold text-ink-700">École concernée</label>
                                <select wire:model="school_id" class="w-full rounded-lg border border-ink-300 px-3 py-2 text-[13.5px] outline-none focus:border-brand-600">
                                    <option value="">Toutes / aucune</option>
                                    @foreach ($this->schools() as $id => $sname)
                                        <option value="{{ $id }}">{{ $sname }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-[12.5px] font-semibold text-ink-700">Responsable</label>
                                <input wire:model="owner" placeholder="Nom du responsable" class="w-full rounded-lg border border-ink-300 px-3 py-2 text-[13.5px] outline-none focus:border-brand-600">
                                @error('owner')<span class="text-[11.5px] text-danger">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-[12.5px] font-semibold text-ink-700">Type d'opération</label>
                                <select wire:model.live="wChannel" class="w-full rounded-lg border border-ink-300 px-3 py-2 text-[13.5px] outline-none focus:border-brand-600">
                                    @foreach (collect(App\Domains\Campaigns\Enums\CampaignChannel::cases())->groupBy(fn ($ch) => $ch->category()) as $cat => $chs)
                                        <optgroup label="{{ $cat }}">
                                            @foreach ($chs as $ch)
                                                <option value="{{ $ch->value }}">{{ $ch->label() }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-[12.5px] font-semibold text-ink-700">Date de l'opération</label>
                                <input type="date" wire:model="date" class="w-full rounded-lg border border-ink-300 px-3 py-2 text-[13.5px] outline-none focus:border-brand-600">
                                @error('date')<span class="text-[11.5px] text-danger">{{ $message }}</span>@enderror
                            </div>
                            @error('school_id')<div class="col-span-2 text-[11.5px] text-danger">{{ $message }}</div>@enderror
                        </div>
                        @unless ($this->contactBased())
                            <div class="flex items-start gap-2 rounded-lg bg-brand-50 px-3.5 py-2.5 text-[12px] text-ink-600">
                                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" class="mt-0.5 flex-shrink-0 text-brand-600"><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"/><path d="M10 9v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="6.5" r="1" fill="currentColor"/></svg>
                                <span>Action sans liste de contacts individuels : pas d'import. L'impact sera mesuré <span class="font-semibold text-ink-800">au niveau de l'école</span> (évolution des inscriptions et paiements après l'opération).</span>
                            </div>
                        @endunless
                    </div>
                @endif

                {{-- Étape 2 : Import contacts --}}
                @if ($step === 2)
                    <div>
                        <label class="flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed border-ink-300 bg-ink-50 px-4 py-8 text-center hover:border-brand-600 hover:bg-brand-50/40">
                            <svg width="30" height="30" viewBox="0 0 20 20" fill="none" class="text-ink-400"><path d="M10 13V4m0 0L6.5 7.5M10 4l3.5 3.5M4 15h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span class="text-[13.5px] font-semibold text-ink-800">Glisser-déposer un fichier Excel ou CSV</span>
                            <span class="text-[12px] text-ink-500">ou cliquer pour parcourir · .xlsx, .xls, .csv (max 10 Mo)</span>
                            <input type="file" wire:model="file" class="hidden" accept=".csv,.xlsx,.xls,.txt">
                        </label>
                        <div wire:loading wire:target="file" class="mt-3 text-center text-[12.5px] text-ink-500">Analyse du fichier…</div>
                        @error('file')<div class="mt-2 text-[12px] text-danger">{{ $message }}</div>@enderror

                        @if ($preview)
                            <div class="mt-4 grid grid-cols-4 gap-2">
                                @foreach ([['Lignes', $preview['total'], 'text-ink-900'], ['Valides', $preview['valid'], 'text-success'], ['Invalides', $preview['invalid'], 'text-danger'], ['Doublons', $preview['duplicates'], 'text-warning']] as [$l, $v, $col])
                                    <div class="rounded-xl border border-ink-150 bg-white px-3 py-2.5 text-center">
                                        <div class="text-[19px] font-bold {{ $col }}">{{ $fr($v) }}</div>
                                        <div class="text-[11px] text-ink-500">{{ $l }}</div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-3">
                                <div class="mb-1.5 text-[11px] font-bold uppercase tracking-wide text-ink-500">Aperçu</div>
                                <div class="overflow-hidden rounded-lg border border-ink-150">
                                    <table class="w-full text-[12px]">
                                        <tbody>
                                            @foreach ($preview['preview'] as $row)
                                                @php $sc = $row['status'] === 'valide' ? 'text-success' : ($row['status'] === 'doublon' ? 'text-warning' : 'text-danger'); @endphp
                                                <tr class="border-b border-ink-150 last:border-0">
                                                    <td class="px-3 py-1.5 font-mono text-ink-700">{{ $row['phone'] }}</td>
                                                    <td class="px-3 py-1.5 text-ink-600">{{ $row['name'] ?: '—' }}</td>
                                                    <td class="px-3 py-1.5 text-right font-semibold {{ $sc }}">{{ $row['status'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Étape 3 : Validation --}}
                @if ($step === 3)
                    <div class="flex flex-col gap-3">
                        @if ($this->contactBased())
                            <div class="rounded-xl bg-brand-50 px-4 py-3 text-[13px] text-ink-700">Vérifiez le résumé avant l'import. Seuls les <span class="font-semibold text-ink-900">{{ $fr($preview['valid']) }} numéros valides et uniques</span> seront enregistrés puis rapprochés des parents EcolePay.</div>
                        @else
                            <div class="rounded-xl bg-brand-50 px-4 py-3 text-[13px] text-ink-700">Opération sans liste de contacts : son <span class="font-semibold text-ink-900">impact sera mesuré au niveau de l'école</span> sur la fenêtre suivant la date.</div>
                        @endif
                        <div class="grid grid-cols-2 gap-3 text-[13px]">
                            @if ($this->contactBased())
                                <div class="rounded-xl border border-ink-150 px-4 py-3"><div class="text-ink-500">Contacts uniques</div><div class="text-[18px] font-bold text-ink-900">{{ $fr($preview['valid']) }}</div></div>
                            @else
                                <div class="rounded-xl border border-ink-150 px-4 py-3"><div class="text-ink-500">Type</div><div class="text-[14px] font-semibold text-ink-900">{{ App\Domains\Campaigns\Enums\CampaignChannel::from($wChannel)->label() }}</div></div>
                            @endif
                            <div class="rounded-xl border border-ink-150 px-4 py-3"><div class="text-ink-500">École</div><div class="text-[14px] font-semibold text-ink-900">{{ $school_id ? ($this->schools()[$school_id] ?? '—') : 'Toutes / aucune' }}</div></div>
                            <div class="rounded-xl border border-ink-150 px-4 py-3"><div class="text-ink-500">Responsable</div><div class="text-[14px] font-semibold text-ink-900">{{ $owner }}</div></div>
                            <div class="rounded-xl border border-ink-150 px-4 py-3"><div class="text-ink-500">Date</div><div class="text-[14px] font-semibold text-ink-900">{{ \Illuminate\Support\Carbon::parse($date)->locale('fr')->isoFormat('D MMM YYYY') }}</div></div>
                        </div>
                    </div>
                @endif

                {{-- Étape 4 : Traitement --}}
                @if ($step === 4)
                    <div class="flex flex-col items-center gap-4 py-4 text-center">
                        @if ($createdId)
                            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-success-soft text-success">
                                <svg width="28" height="28" viewBox="0 0 20 20" fill="none"><path d="M5 10.5l3.5 3.5L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </div>
                            <div>
                                @if ($this->contactBased() && $preview)
                                    <div class="text-[16px] font-bold text-ink-900">Import réussi</div>
                                    <div class="mt-1 text-[13px] text-ink-600">{{ $fr($preview['valid']) }} contacts enregistrés · {{ $fr($preview['invalid'] + $preview['duplicates']) }} ignorés ({{ $fr($preview['invalid']) }} invalides, {{ $fr($preview['duplicates']) }} doublons).</div>
                                @else
                                    <div class="text-[16px] font-bold text-ink-900">Opération enregistrée</div>
                                    <div class="mt-1 text-[13px] text-ink-600">L'impact est mesuré au niveau de l'école sur la fenêtre d'attribution.</div>
                                @endif
                            </div>
                            <a href="{{ route('campaigns.show', $createdId) }}" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700">Voir l'analyse de l'opération</a>
                        @else
                            <div class="h-14 w-14 animate-spin rounded-full border-4 border-ink-150 border-t-brand-600"></div>
                            <div class="text-[14px] font-semibold text-ink-800">Enregistrement en cours…</div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Footer nav --}}
            @if ($step < 4)
                <div class="flex items-center justify-between border-t border-ink-150 px-6 py-4">
                    @if ($step === 1)
                        <span></span>
                        <button wire:click="proceedFromInfo" class="rounded-lg bg-brand-600 px-4 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700">Continuer</button>
                    @elseif ($step === 2)
                        <button wire:click="$set('step', 1)" class="rounded-lg px-3 py-2 text-[12.5px] font-semibold text-ink-600 hover:bg-ink-100">Retour</button>
                        <button wire:click="toStep3" @if (! $preview || $preview['valid'] === 0) disabled @endif class="rounded-lg bg-brand-600 px-4 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700 disabled:opacity-40">Continuer</button>
                    @elseif ($step === 3)
                        <button wire:click="backFromValidation" class="rounded-lg px-3 py-2 text-[12.5px] font-semibold text-ink-600 hover:bg-ink-100">Retour</button>
                        <button wire:click="launchImport" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700">
                            <span wire:loading.remove wire:target="launchImport">{{ $this->contactBased() ? 'Lancer l\'import' : 'Enregistrer l\'opération' }}</span>
                            <span wire:loading wire:target="launchImport">Enregistrement…</span>
                        </button>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
