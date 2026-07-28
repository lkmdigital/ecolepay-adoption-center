<?php

use App\Domains\Users\Enums\Permission as PermissionEnum;
use App\Domains\Users\Enums\Role as RoleEnum;
use App\Domains\Users\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $role = '';

    #[Url]
    public string $status = '';

    public bool $slideOpen = false;

    public ?int $editingId = null;

    public array $form = [
        'name' => '', 'email' => '', 'job_title' => '', 'department' => '', 'phone' => '', 'role' => 'support', 'is_active' => true, 'password' => '',
    ];

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(PermissionEnum::UsersView->value), 403);
    }

    #[Computed]
    public function rows(): Collection
    {
        return User::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$this->search}%")->orWhere('email', 'like', "%{$this->search}%")))
            ->when($this->role !== '', fn ($q) => $q->where('primary_role_code', $this->role))
            ->when($this->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function stats(): array
    {
        $all = User::query()->get(['is_active', 'primary_role_code']);

        return [
            'total' => $all->count(),
            'active' => $all->where('is_active', true)->count(),
            'inactive' => $all->where('is_active', false)->count(),
            'admins' => $all->where('primary_role_code', RoleEnum::SuperAdmin->value)->count(),
        ];
    }

    public function openCreate(): void
    {
        $this->authorizeWrite(PermissionEnum::UsersCreate);
        $this->reset('editingId');
        $this->form = ['name' => '', 'email' => '', 'job_title' => '', 'department' => '', 'phone' => '', 'role' => 'support', 'is_active' => true, 'password' => ''];
        $this->resetValidation();
        $this->slideOpen = true;
    }

    public function openEdit(int $id): void
    {
        $this->authorizeWrite(PermissionEnum::UsersUpdate);
        $u = User::findOrFail($id);
        $this->editingId = $id;
        $this->form = [
            'name' => $u->name, 'email' => $u->email, 'job_title' => $u->job_title ?? '', 'department' => $u->department ?? '',
            'phone' => $u->phone ?? '', 'role' => $u->primary_role_code ?? 'support', 'is_active' => (bool) $u->is_active, 'password' => '',
        ];
        $this->resetValidation();
        $this->slideOpen = true;
    }

    public function save(): void
    {
        $this->authorizeWrite($this->editingId ? PermissionEnum::UsersUpdate : PermissionEnum::UsersCreate);

        $rules = [
            'form.name' => 'required|string|max:80',
            'form.email' => ['required', 'email', 'max:120', Rule::unique('users', 'email')->ignore($this->editingId)],
            'form.job_title' => 'nullable|string|max:60',
            'form.department' => 'nullable|string|max:60',
            'form.phone' => 'nullable|string|max:30',
            'form.role' => ['required', Rule::in(RoleEnum::values())],
            'form.password' => $this->editingId ? 'nullable|string|min:8' : 'required|string|min:8',
        ];
        $this->validate($rules);

        // Champs assignables en masse (voir #[Fillable] du modèle).
        $fill = [
            'name' => $this->form['name'],
            'email' => $this->form['email'],
            'job_title' => $this->form['job_title'] ?: null,
            'department' => $this->form['department'] ?: null,
            'phone' => $this->form['phone'] ?: null,
        ];
        if ($this->form['password'] !== '') {
            $fill['password'] = Hash::make($this->form['password']);
        }
        // Champs volontairement hors mass-assignment : renseignés explicitement.
        $force = [
            'primary_role_code' => $this->form['role'],
            'is_active' => (bool) $this->form['is_active'],
        ];

        $user = $this->editingId ? User::findOrFail($this->editingId) : new User;
        $user->fill($fill)->forceFill($force)->save();
        $user->syncRoles([$this->form['role']]);

        $this->slideOpen = false;
        unset($this->rows, $this->stats);
        $this->dispatch('users-flash', message: $this->editingId ? 'Utilisateur mis à jour.' : 'Utilisateur créé.');
    }

    public function toggleActive(int $id): void
    {
        $this->authorizeWrite(PermissionEnum::UsersUpdate);
        $u = User::findOrFail($id);

        // Ne pas se désactiver soi-même, ni retirer le dernier super-admin actif.
        if ($u->id === auth()->id()) {
            $this->dispatch('users-flash', message: 'Vous ne pouvez pas désactiver votre propre compte.', kind: 'info');

            return;
        }

        if ($u->is_active) {
            $u->deactivate();
        } else {
            $u->forceFill(['is_active' => true, 'deactivated_at' => null])->save();
        }

        unset($this->rows, $this->stats);
        $this->dispatch('users-flash', message: $u->is_active ? 'Compte réactivé.' : 'Compte désactivé.');
    }

    #[Computed]
    public function roleCatalog(): array
    {
        return array_map(fn (RoleEnum $r) => [
            'code' => $r->value,
            'label' => $r->label(),
            'description' => $r->description(),
            'count' => count($r->permissions()),
            'permissions' => array_map(fn (PermissionEnum $p) => $p->label(), $r->permissions()),
        ], RoleEnum::cases());
    }

    private function authorizeWrite(PermissionEnum $permission): void
    {
        abort_unless(auth()->user()?->can($permission->value), 403);
    }
};

?>

@php
    $roleMeta = [
        'super-admin' => ['Super Admin', '#8A1C6B', '#FBEAF5'],
        'developer' => ['Développeur', '#1D3F9C', '#EEF3FE'],
        'direction' => ['Direction', '#2554C7', '#EEF3FE'],
        'marketing' => ['Marketing', '#7C3AED', '#F1EAFE'],
        'commercial' => ['Commercial', '#0F7A44', '#E7F6EE'],
        'support' => ['Support', '#B45F04', '#FEF3E2'],
        'analyst' => ['Analyste', '#5B6472', '#F2F3F5'],
    ];
    $canCreate = (bool) auth()->user()?->can('users.create');
    $canUpdate = (bool) auth()->user()?->can('users.update');
    $s = $this->stats;
    $fr = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $initials = fn ($name) => \Illuminate\Support\Str::of($name)->explode(' ')->map(fn ($p) => \Illuminate\Support\Str::substr($p, 0, 1))->take(2)->implode('');
@endphp

<div class="mx-auto max-w-[1320px]"
     x-data="{ toast: false, msg: '', kind: 'success' }"
     @users-flash.window="msg = $event.detail.message; kind = $event.detail.kind || 'success'; toast = true; clearTimeout(window._ut); window._ut = setTimeout(() => toast = false, 3200)">

    {{-- Toast --}}
    <div x-show="toast" x-cloak x-transition
         class="fixed bottom-6 right-6 z-50 flex items-center gap-2.5 rounded-xl px-4 py-3 text-[13px] font-semibold text-white shadow-lg"
         :class="kind === 'info' ? 'bg-ink-800' : 'bg-[#189B57]'">
        <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M4 10l4 4 8-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span x-text="msg"></span>
    </div>

    {{-- KPIs --}}
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([['Utilisateurs', $s['total'], '#2554C7'], ['Actifs', $s['active'], '#189B57'], ['Désactivés', $s['inactive'], '#5B6472'], ['Administrateurs', $s['admins'], '#8A1C6B']] as [$label, $val, $color])
            <div class="rounded-[13px] border border-ink-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="text-[12px] font-semibold text-ink-500">{{ $label }}</div>
                <div class="mt-1.5 text-[22px] font-bold tracking-tight" style="color: {{ $color }}">{{ $fr($val) }}</div>
            </div>
        @endforeach
    </div>

    {{-- Barre d'actions --}}
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-1 flex-wrap items-center gap-2">
            <div class="flex min-w-[200px] flex-1 items-center gap-2 rounded-[10px] border border-ink-300 bg-white px-3 py-2 focus-within:border-brand-600">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-500"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="M17 17l-3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Rechercher un nom, un e-mail…" class="w-full border-none bg-transparent text-[13.5px] outline-none placeholder:text-ink-400">
            </div>
            <select wire:model.live="role" class="eac-input sm:w-44">
                <option value="">Tous les rôles</option>
                @foreach ($this->roleCatalog as $r)
                    <option value="{{ $r['code'] }}">{{ $r['label'] }}</option>
                @endforeach
            </select>
            <select wire:model.live="status" class="eac-input sm:w-40">
                <option value="">Tous les statuts</option>
                <option value="active">Actifs</option>
                <option value="inactive">Désactivés</option>
            </select>
        </div>
        @if ($canCreate)
            <button wire:click="openCreate" class="inline-flex items-center justify-center gap-2 rounded-[10px] bg-brand-600 px-4 py-2.5 text-[13px] font-semibold text-white shadow-sm hover:bg-brand-700">
                <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                Nouvel utilisateur
            </button>
        @endif
    </div>

    {{-- Tableau --}}
    <div class="mb-8 overflow-hidden rounded-[14px] border border-ink-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-ink-150 text-[11.5px] font-semibold uppercase tracking-wide text-ink-500">
                        <th class="px-4 py-3">Utilisateur</th>
                        <th class="px-4 py-3">Rôle</th>
                        <th class="hidden px-4 py-3 md:table-cell">Département</th>
                        <th class="hidden px-4 py-3 lg:table-cell">Dernière connexion</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @forelse ($this->rows as $u)
                        @php [$rlabel, $rfg, $rbg] = $roleMeta[$u->primary_role_code] ?? ['—', '#5B6472', '#F2F3F5']; @endphp
                        <tr wire:key="user-{{ $u->id }}" class="text-[13.5px] hover:bg-ink-50/60">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-brand-50 text-[12px] font-bold text-brand-700">{{ $initials($u->name) }}</span>
                                    <div class="min-w-0">
                                        <div class="truncate font-semibold text-ink-900">{{ $u->name }}</div>
                                        <div class="truncate text-[12px] text-ink-500">{{ $u->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-[11.5px] font-bold" style="background: {{ $rbg }}; color: {{ $rfg }}">{{ $rlabel }}</span></td>
                            <td class="hidden px-4 py-3 text-ink-600 md:table-cell">{{ $u->department ?? '—' }}</td>
                            <td class="hidden px-4 py-3 text-ink-500 lg:table-cell">{{ $u->last_login_at ? $u->last_login_at->translatedFormat('d M Y · H:i') : 'Jamais' }}</td>
                            <td class="px-4 py-3">
                                @if ($u->is_active)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#E7F6EE] px-2.5 py-1 text-[11.5px] font-bold text-[#0F7A44]"><span class="h-1.5 w-1.5 rounded-full bg-[#189B57]"></span>Actif</span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-ink-100 px-2.5 py-1 text-[11.5px] font-bold text-ink-500"><span class="h-1.5 w-1.5 rounded-full bg-ink-400"></span>Désactivé</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    @if ($canUpdate)
                                        <button wire:click="openEdit({{ $u->id }})" class="flex h-8 w-8 items-center justify-center rounded-[8px] text-ink-500 hover:bg-ink-100 hover:text-ink-900" title="Modifier">
                                            <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M4 13.5V16h2.5l7-7L11 6.5zM12.5 5l2.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </button>
                                        <button wire:click="toggleActive({{ $u->id }})" class="flex h-8 w-8 items-center justify-center rounded-[8px] text-ink-500 hover:bg-ink-100 hover:text-ink-900" title="{{ $u->is_active ? 'Désactiver' : 'Réactiver' }}">
                                            @if ($u->is_active)
                                                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M10 3v7M6 5.5a6 6 0 108 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                            @else
                                                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M4 10l4 4 8-9" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            @endif
                                        </button>
                                    @else
                                        <span class="text-[12px] text-ink-400">—</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-12 text-center text-[13px] text-ink-500">Aucun utilisateur ne correspond à ces filtres.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Catalogue des rôles --}}
    <div class="mb-3 flex items-center gap-2">
        <h2 class="text-[15px] font-bold text-ink-900">Rôles &amp; permissions</h2>
        <span class="rounded-full bg-ink-100 px-2.5 py-0.5 text-[11px] font-bold text-ink-600">{{ count($this->roleCatalog) }} rôles</span>
    </div>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" x-data="{ open: null }">
        @foreach ($this->roleCatalog as $i => $r)
            @php [$rlabel, $rfg, $rbg] = $roleMeta[$r['code']] ?? ['—', '#5B6472', '#F2F3F5']; @endphp
            <div class="flex flex-col rounded-[13px] border border-ink-200 bg-white p-4">
                <div class="flex items-center justify-between gap-2">
                    <span class="rounded-full px-2.5 py-1 text-[12px] font-bold" style="background: {{ $rbg }}; color: {{ $rfg }}">{{ $rlabel }}</span>
                    <span class="text-[11.5px] font-semibold text-ink-500">{{ $r['count'] }} permissions</span>
                </div>
                <p class="mt-2.5 flex-1 text-[12.5px] leading-relaxed text-ink-600">{{ $r['description'] }}</p>
                <button @click="open = open === {{ $i }} ? null : {{ $i }}" class="mt-3 flex items-center gap-1.5 text-[12px] font-semibold text-brand-600 hover:underline">
                    <span x-text="open === {{ $i }} ? 'Masquer les permissions' : 'Voir les permissions'"></span>
                    <svg width="13" height="13" viewBox="0 0 20 20" fill="none" class="transition-transform" :class="{ 'rotate-180': open === {{ $i }} }"><path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <div x-show="open === {{ $i }}" x-collapse x-cloak>
                    <ul class="mt-2 flex flex-col gap-1 border-t border-ink-100 pt-2">
                        @foreach ($r['permissions'] as $perm)
                            <li class="flex items-center gap-2 text-[12px] text-ink-600"><svg width="12" height="12" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-[#189B57]"><path d="M4 10l4 4 8-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>{{ $perm }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endforeach
    </div>
    <p class="mt-3 text-[12px] text-ink-500">La matrice rôle → permissions est définie dans le code (source unique) et reportée en base. L'édition fine des permissions par rôle se fait à ce niveau.</p>

    {{-- ============================ VOLET CRÉATION/ÉDITION ============================ --}}
    <div x-data="{ open: @entangle('slideOpen') }" x-cloak>
        <div x-show="open" x-transition.opacity @click="open = false" class="fixed inset-0 z-40 bg-ink-900/40"></div>
        <div x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
             class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-ink-150 px-5 py-4">
                <div class="text-[15px] font-bold text-ink-900">{{ $editingId ? "Modifier l'utilisateur" : 'Nouvel utilisateur' }}</div>
                <button @click="open = false" class="flex h-8 w-8 items-center justify-center rounded-lg text-ink-500 hover:bg-ink-100"><svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button>
            </div>
            <div class="flex-1 overflow-y-auto p-5">
                <div class="flex flex-col gap-4">
                    <div>
                        <label class="mb-1.5 block text-[12.5px] font-semibold text-ink-800">Nom complet</label>
                        <input wire:model="form.name" type="text" @class(['eac-input', 'border-danger' => $errors->has('form.name')])>
                        @error('form.name') <p class="eac-err">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-[12.5px] font-semibold text-ink-800">E-mail</label>
                        <input wire:model="form.email" type="email" @class(['eac-input', 'border-danger' => $errors->has('form.email')])>
                        @error('form.email') <p class="eac-err">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1.5 block text-[12.5px] font-semibold text-ink-800">Fonction</label>
                            <input wire:model="form.job_title" type="text" class="eac-input">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-[12.5px] font-semibold text-ink-800">Département</label>
                            <input wire:model="form.department" type="text" class="eac-input">
                        </div>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-[12.5px] font-semibold text-ink-800">Téléphone</label>
                        <input wire:model="form.phone" type="text" class="eac-input" placeholder="+225…">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-[12.5px] font-semibold text-ink-800">Rôle</label>
                        <select wire:model="form.role" class="eac-input">
                            @foreach ($this->roleCatalog as $r)
                                <option value="{{ $r['code'] }}">{{ $r['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-[12.5px] font-semibold text-ink-800">{{ $editingId ? 'Nouveau mot de passe' : 'Mot de passe initial' }}</label>
                        <input wire:model="form.password" type="password" @class(['eac-input', 'border-danger' => $errors->has('form.password')]) placeholder="{{ $editingId ? 'Laisser vide pour ne pas changer' : '8 caractères minimum' }}">
                        @error('form.password') <p class="eac-err">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-[11.5px] text-ink-500">{{ $editingId ? 'Renseignez ce champ uniquement pour réinitialiser le mot de passe.' : "Communiquez ce mot de passe à l'utilisateur — l'envoi d'invitation par e-mail arrivera avec un connecteur de messagerie." }}</p>
                    </div>
                    <label class="flex cursor-pointer items-center justify-between gap-4 rounded-[11px] border border-ink-200 p-3">
                        <span><span class="block text-[13px] font-semibold text-ink-900">Compte actif</span><span class="text-[11.5px] text-ink-500">Un compte désactivé ne peut pas se connecter.</span></span>
                        <span class="relative flex-shrink-0">
                            <input type="checkbox" wire:model="form.is_active" class="peer sr-only">
                            <span class="block h-6 w-11 rounded-full bg-ink-300 transition-colors peer-checked:bg-brand-600"></span>
                            <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                        </span>
                    </label>
                </div>
            </div>
            <div class="flex items-center justify-end gap-2 border-t border-ink-150 px-5 py-4">
                <button @click="open = false" class="rounded-[9px] px-4 py-2.5 text-[13px] font-semibold text-ink-600 hover:bg-ink-100">Annuler</button>
                <button wire:click="save" wire:loading.attr="disabled" wire:target="save" class="inline-flex items-center gap-2 rounded-[10px] bg-brand-600 px-4 py-2.5 text-[13px] font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
                    <svg wire:loading wire:target="save" width="15" height="15" viewBox="0 0 20 20" fill="none" class="animate-spin"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" stroke-opacity="0.3"/><path d="M17 10a7 7 0 00-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    {{ $editingId ? 'Enregistrer' : 'Créer le compte' }}
                </button>
            </div>
        </div>
    </div>
</div>
