<?php

use App\Domains\Activity\Models\ActivityLog;
use App\Domains\Users\Models\User;
use App\Domains\Users\Models\UserFavorite;
use App\Domains\Users\Support\CurrentUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $tab = 'profil';

    public int $userId;

    /** Champs d'identité éditables. */
    public array $form = [
        'prenom' => '', 'nom' => '', 'phone' => '', 'email' => '', 'job_title' => '', 'department' => '',
    ];

    /** Préférences personnelles. */
    public array $prefs = [];

    // Sélecteur d'épinglage.
    public string $favType = 'school';

    public string $favRef = '';

    public string $flash = '';

    public string $flashKind = 'success';

    /** Changement de mot de passe. */
    public array $pw = ['current' => '', 'new' => '', 'confirm' => ''];

    /** Confirmation par mot de passe pour la désactivation du compte. */
    public string $deletePassword = '';

    public function mount(): void
    {
        $user = CurrentUser::resolve();
        $this->userId = $user->id;

        $parts = preg_split('/\s+/', trim((string) $user->name), 2);
        $this->form = [
            'prenom' => $parts[0] ?? '',
            'nom' => $parts[1] ?? '',
            'phone' => $user->phone ?? '',
            'email' => $user->email ?? '',
            'job_title' => $user->job_title ?? '',
            'department' => $user->department ?? '',
        ];
        $this->prefs = CurrentUser::preferences($user);
    }

    #[Computed]
    public function user(): User
    {
        return User::findOrFail($this->userId);
    }

    protected function rules(): array
    {
        return [
            'form.prenom' => 'required|string|max:40',
            'form.nom' => 'nullable|string|max:40',
            'form.email' => ['required', 'email', 'max:120', Rule::unique('users', 'email')->ignore($this->userId)],
            'form.phone' => 'nullable|string|max:30',
            'form.job_title' => 'nullable|string|max:60',
            'form.department' => 'nullable|string|max:60',
        ];
    }

    // Validation instantanée champ par champ.
    public function updatedForm($value, $key): void
    {
        $this->validateOnly("form.$key");
    }

    public function save(): void
    {
        $this->validate();

        $this->user->update([
            'name' => trim($this->form['prenom'].' '.$this->form['nom']),
            'email' => $this->form['email'],
            'phone' => $this->form['phone'] ?: null,
            'job_title' => $this->form['job_title'] ?: null,
            'department' => $this->form['department'] ?: null,
        ]);
        unset($this->user);

        $this->flashMessage('Profil enregistré.');
    }

    public function savePrefs(): void
    {
        $clean = array_merge(CurrentUser::PREFERENCE_DEFAULTS, $this->prefs);
        $this->user->update(['preferences' => $clean, 'locale' => $clean['language']]);
        unset($this->user);

        $this->flashMessage('Préférences enregistrées.');
    }

    // --- Favoris ---------------------------------------------------------------

    #[Computed]
    public function favorites(): Collection
    {
        return UserFavorite::query()->where('user_id', $this->userId)->latest()->get();
    }

    #[Computed]
    public function pickable(): array
    {
        return [
            'school' => DB::table('dim_schools')->where('is_test', false)->where('is_current', true)->orderBy('name')->pluck('name', 'id')->all(),
            'report' => DB::table('reports')->orderByDesc('id')->pluck('name', 'id')->all(),
        ];
    }

    public function addFavorite(): void
    {
        if ($this->favRef === '') {
            return;
        }

        $labels = $this->pickable[$this->favType] ?? [];
        $label = $labels[$this->favRef] ?? $this->favRef;
        $route = $this->favType === 'school' ? 'schools.show' : 'reports.show';

        UserFavorite::query()->updateOrCreate(
            ['user_id' => $this->userId, 'type' => $this->favType, 'ref_id' => (string) $this->favRef],
            ['label' => $label, 'link_route' => $route],
        );

        $this->favRef = '';
        unset($this->favorites);
        $this->flashMessage('Ajouté à vos favoris.');
    }

    public function removeFavorite(int $id): void
    {
        UserFavorite::query()->where('user_id', $this->userId)->whereKey($id)->delete();
        unset($this->favorites);
        $this->flashMessage('Favori retiré.', 'info');
    }

    // --- Activité --------------------------------------------------------------

    #[Computed]
    public function activity(): Collection
    {
        return ActivityLog::query()->orderByDesc('occurred_at')->take(15)->get();
    }

    // --- Confidentialité (exports réels) --------------------------------------

    public function downloadData()
    {
        $user = $this->user;
        $payload = [
            'compte' => $user->only(['id', 'name', 'email', 'phone', 'job_title', 'department', 'locale', 'timezone', 'created_at', 'last_login_at']),
            'preferences' => CurrentUser::preferences($user),
            'favoris' => $this->favorites->map->only(['type', 'ref_id', 'label'])->all(),
            'export_le' => now()->toIso8601String(),
        ];

        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'mes-donnees-eac.json');
    }

    public function exportActivity()
    {
        $rows = ActivityLog::query()->orderByDesc('occurred_at')->take(500)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Catégorie', 'Module', 'Action', 'Titre', 'Description']);
            foreach ($rows as $r) {
                fputcsv($out, [optional($r->occurred_at)->format('Y-m-d H:i'), $r->category, $r->module, $r->action, $r->title, $r->description]);
            }
            fclose($out);
        }, 'mon-activite-eac.csv');
    }

    // --- Sécurité (état réel) --------------------------------------------------

    #[Computed]
    public function security(): array
    {
        $u = $this->user;
        $checks = [
            ['Mot de passe défini', (bool) $u->password, 'Actif'],
            ['Sessions surveillées', true, 'Actif'],
            ['Double authentification (2FA)', false, 'Prévue'],
        ];
        $done = collect($checks)->filter(fn ($c) => $c[1])->count();
        $score = (int) round($done / count($checks) * 100);

        return ['checks' => $checks, 'score' => $score, 'level' => $score >= 66 ? 'Bon' : ($score >= 33 ? 'Moyen' : 'À configurer')];
    }

    public function changePassword(): void
    {
        $this->validate([
            'pw.current' => 'required',
            'pw.new' => 'required|string|min:8|confirmed:pw.confirm',
        ], [], ['pw.current' => 'mot de passe actuel', 'pw.new' => 'nouveau mot de passe']);

        $user = $this->user;
        if ($user->password && ! Hash::check($this->pw['current'], $user->password)) {
            $this->addError('pw.current', 'Le mot de passe actuel est incorrect.');

            return;
        }

        $user->forceFill(['password' => Hash::make($this->pw['new'])])->save();
        $this->pw = ['current' => '', 'new' => '', 'confirm' => ''];
        $this->flashMessage('Mot de passe mis à jour.');
    }

    // --- Sessions actives (table `sessions`, driver base de données) -----------

    #[Computed]
    public function sessions(): Collection
    {
        if (! DB::getSchemaBuilder()->hasTable('sessions')) {
            return collect();
        }

        $currentId = session()->getId();

        return DB::table('sessions')->where('user_id', $this->userId)
            ->orderByDesc('last_activity')->get()
            ->map(function ($s) use ($currentId) {
                [$device, $browser] = $this->parseAgent($s->user_agent ?? '');

                return [
                    'id' => $s->id,
                    'current' => $s->id === $currentId,
                    'ip' => $s->ip_address ?: '—',
                    'device' => $device,
                    'browser' => $browser,
                    'last' => $s->last_activity ? \Illuminate\Support\Carbon::createFromTimestamp($s->last_activity) : null,
                ];
            });
    }

    public function revokeSession(string $id): void
    {
        if ($id === session()->getId()) {
            return; // on ne coupe pas la session courante ici
        }
        DB::table('sessions')->where('user_id', $this->userId)->where('id', $id)->delete();
        unset($this->sessions);
        $this->flashMessage('Session déconnectée.', 'info');
    }

    public function logoutOthers(): void
    {
        DB::table('sessions')->where('user_id', $this->userId)
            ->where('id', '!=', session()->getId())->delete();
        unset($this->sessions);
        $this->flashMessage('Toutes les autres sessions ont été déconnectées.');
    }

    private function parseAgent(string $ua): array
    {
        $os = str_contains($ua, 'Windows') ? 'Windows'
            : (str_contains($ua, 'iPhone') ? 'iPhone' : (str_contains($ua, 'iPad') ? 'iPad'
            : (str_contains($ua, 'Android') ? 'Android' : (str_contains($ua, 'Mac OS') ? 'macOS'
            : (str_contains($ua, 'Linux') ? 'Linux' : 'Appareil')))));
        $browser = str_contains($ua, 'Edg') ? 'Edge'
            : (str_contains($ua, 'Chrome') ? 'Chrome' : (str_contains($ua, 'Firefox') ? 'Firefox'
            : (str_contains($ua, 'Safari') ? 'Safari' : 'Navigateur')));

        return [$os, $browser];
    }

    // --- Désactivation du compte ----------------------------------------------

    public function deleteAccount()
    {
        $this->validate(['deletePassword' => 'required'], [], ['deletePassword' => 'mot de passe']);

        $user = $this->user;
        if ($user->password && ! Hash::check($this->deletePassword, $user->password)) {
            $this->addError('deletePassword', 'Mot de passe incorrect.');

            return;
        }

        // Désactivation (jamais un effacement dur : la paternité des campagnes et
        // diagnostics doit être préservée pour l'audit). Puis déconnexion.
        $user->deactivate();
        DB::table('sessions')->where('user_id', $this->userId)->delete();
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        return $this->redirect(route('login'));
    }

    private function flashMessage(string $msg, string $kind = 'success'): void
    {
        $this->flash = $msg;
        $this->flashKind = $kind;
        $this->dispatch('profile-flash');
    }
};

?>

@php
    use Illuminate\Support\Facades\Route as RouteFacade;
    use Illuminate\Support\Str;

    $u = $this->user;
    $initials = Str::of($u->name ?: 'EAC')->explode(' ')->map(fn ($p) => Str::substr($p, 0, 1))->take(2)->implode('');

    $tabs = [
        'profil' => ['Profil', 'M10 10a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM4 17c0-3.3 2.7-5 6-5s6 1.7 6 5'],
        'securite' => ['Sécurité', 'M10 3l6 2v5c0 4-3 6-6 7-3-1-6-3-6-7V5z'],
        'sessions' => ['Sessions', 'M4 5h12v7H4zM7 16h6M9 12v4'],
        'preferences' => ['Préférences', 'M4 7h9M15 7h1M4 13h1M8 13h8M13 5.5v3M7 11.5v3'],
        'notifications' => ['Notifications', 'M6 8a4 4 0 018 0c0 4 1.5 5 1.5 5h-11S6 12 6 8zM8.5 16a1.5 1.5 0 003 0'],
        'activite' => ['Activité', 'M4 10h3l2-5 2 10 2-5h3'],
        'favoris' => ['Favoris', 'M10 3l2 4.2 4.6.5-3.4 3.1 1 4.5L10 13l-4.2 2.4 1-4.5L3.4 7.7 8 7.2z'],
        'confidentialite' => ['Confidentialité', 'M10 3l6 2v5c0 4-3 6-6 7-3-1-6-3-6-7V5zM8 10l1.4 1.4L13 8'],
    ];
    $t = $this->tab;

    $fr = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $lvlColor = ['À configurer' => ['#B45F04', '#FEF3E2'], 'Moyen' => ['#B45F04', '#FEF3E2'], 'Bon' => ['#0F7A44', '#E7F6EE']];

    $activityIcon = [
        'metier' => '#2554C7', 'technique' => '#5B6472',
    ];
@endphp

<div class="mx-auto max-w-[1180px]"
     x-data="{ toast: false, msg: '', kind: 'success' }"
     @profile-flash.window="msg = $wire.flash; kind = $wire.flashKind; toast = true; clearTimeout(window._pt); window._pt = setTimeout(() => toast = false, 3200)">

    {{-- Toast --}}
    <div x-show="toast" x-cloak x-transition
         class="fixed bottom-6 right-6 z-50 flex items-center gap-2.5 rounded-xl px-4 py-3 text-[13px] font-semibold text-white shadow-lg"
         :class="kind === 'info' ? 'bg-ink-800' : 'bg-[#189B57]'">
        <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M4 10l4 4 8-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span x-text="msg"></span>
    </div>

    {{-- Carte identité (bandeau) --}}
    <div class="mb-6 flex flex-col gap-4 rounded-[16px] border border-ink-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.03)] sm:flex-row sm:items-center sm:p-6">
        <div class="relative flex-shrink-0">
            <span class="flex h-16 w-16 items-center justify-center rounded-full bg-brand-50 text-[22px] font-bold text-brand-700">{{ $initials }}</span>
            <button type="button"
                    x-on:click="msg='L’import de photo sera disponible avec le stockage de fichiers.'; kind='info'; toast=true; clearTimeout(window._pt); window._pt=setTimeout(()=>toast=false,3200)"
                    class="absolute -bottom-1 -right-1 flex h-7 w-7 items-center justify-center rounded-full border-2 border-white bg-ink-800 text-white hover:bg-ink-900" title="Changer la photo">
                <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M4 7h2l1-2h6l1 2h2v8H4z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="10" cy="10.5" r="2.3" stroke="currentColor" stroke-width="1.5"/></svg>
            </button>
        </div>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-[19px] font-bold tracking-tight text-ink-900">{{ $u->name }}</h1>
                <span class="rounded-full bg-brand-50 px-2.5 py-0.5 text-[11.5px] font-bold text-brand-700">{{ $u->job_title ?? 'Direction' }}</span>
            </div>
            <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-ink-500">
                <span class="inline-flex items-center gap-1.5"><svg width="13" height="13" viewBox="0 0 20 20" fill="none"><rect x="3" y="5" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M4 6l6 4 6-4" stroke="currentColor" stroke-width="1.5"/></svg>{{ $u->email }}</span>
                @if ($u->department)<span class="inline-flex items-center gap-1.5"><svg width="13" height="13" viewBox="0 0 20 20" fill="none"><rect x="4" y="4" width="12" height="12" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M8 8h4M8 11h4" stroke="currentColor" stroke-width="1.5"/></svg>{{ $u->department }}</span>@endif
                @if ($u->phone)<span class="inline-flex items-center gap-1.5"><svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M5 4h3l1 4-2 1a8 8 0 004 4l1-2 4 1v3c0 1-1 2-2 2A13 13 0 013 6c0-1 1-2 2-2z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>{{ $u->phone }}</span>@endif
            </div>
        </div>
        <div class="flex flex-shrink-0 flex-col items-start gap-1 text-[12px] text-ink-500 sm:items-end">
            <span>Arrivée : <span class="font-semibold text-ink-700">{{ optional($u->created_at)->translatedFormat('d M Y') ?? '—' }}</span></span>
            <span>Dernière connexion : <span class="font-semibold text-ink-700">{{ optional($u->last_login_at)->translatedFormat('d M Y') ?? '—' }}</span></span>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[220px_1fr]">

        {{-- Rail des onglets --}}
        <aside class="lg:sticky lg:top-0 lg:self-start">
            <nav class="flex gap-1 overflow-x-auto rounded-[14px] border border-ink-200 bg-white p-2 shadow-[0_1px_2px_rgba(15,23,42,0.03)] lg:flex-col lg:overflow-visible">
                @foreach ($tabs as $key => [$label, $icon])
                    <button wire:click="$set('tab', '{{ $key }}')"
                            class="flex flex-shrink-0 items-center gap-2.5 rounded-[10px] px-3 py-2.5 text-left text-[13.5px] font-semibold transition-colors
                                   {{ $t === $key ? 'bg-brand-50 text-brand-700' : 'text-ink-800 hover:bg-ink-100' }}">
                        <svg width="17" height="17" viewBox="0 0 20 20" fill="none" class="flex-shrink-0"><path d="{{ $icon }}" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span>{{ $label }}</span>
                    </button>
                @endforeach
            </nav>
        </aside>

        <div class="min-w-0">

            {{-- ============================== PROFIL ============================== --}}
            @if ($t === 'profil')
                <x-settings.card title="Informations personnelles" subtitle="Vos coordonnées au sein de l'Adoption Center.">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-settings.field label="Prénom">
                            <input type="text" wire:model.blur="form.prenom" @class(['eac-input', 'border-danger' => $errors->has('form.prenom')])>
                            @error('form.prenom') <p class="eac-err">{{ $message }}</p> @enderror
                        </x-settings.field>
                        <x-settings.field label="Nom">
                            <input type="text" wire:model.blur="form.nom" class="eac-input">
                        </x-settings.field>
                        <x-settings.field label="E-mail">
                            <input type="email" wire:model.blur="form.email" @class(['eac-input', 'border-danger' => $errors->has('form.email')])>
                            @error('form.email') <p class="eac-err">{{ $message }}</p> @enderror
                        </x-settings.field>
                        <x-settings.field label="Téléphone">
                            <input type="text" wire:model.blur="form.phone" class="eac-input" placeholder="+225…">
                        </x-settings.field>
                        <x-settings.field label="Fonction">
                            <input type="text" wire:model.blur="form.job_title" class="eac-input">
                        </x-settings.field>
                        <x-settings.field label="Département">
                            <input type="text" wire:model.blur="form.department" class="eac-input">
                        </x-settings.field>
                    </div>
                    <div class="mt-6 flex justify-end">
                        <button wire:click="save" wire:loading.attr="disabled" wire:target="save"
                                class="inline-flex items-center gap-2 rounded-[10px] bg-brand-600 px-4 py-2.5 text-[13px] font-semibold text-white shadow-sm hover:bg-brand-700 disabled:opacity-60">
                            <svg wire:loading.remove wire:target="save" width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M4 10l4 4 8-9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <svg wire:loading wire:target="save" width="16" height="16" viewBox="0 0 20 20" fill="none" class="animate-spin"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" stroke-opacity="0.3"/><path d="M17 10a7 7 0 00-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            Enregistrer les modifications
                        </button>
                    </div>
                </x-settings.card>

            {{-- ============================= SÉCURITÉ ============================= --}}
            @elseif ($t === 'securite')
                @php [$lf, $lb] = $lvlColor[$this->security['level']] ?? ['#5B6472', '#F2F3F5']; @endphp
                <x-settings.card title="Sécurité du compte" subtitle="Renforcez la protection de votre accès.">
                    <div class="mb-5 flex items-center gap-4 rounded-[12px] border border-ink-200 p-4">
                        <div class="relative flex h-14 w-14 flex-shrink-0 items-center justify-center">
                            <svg width="56" height="56" viewBox="0 0 56 56" class="-rotate-90">
                                <circle cx="28" cy="28" r="24" fill="none" stroke="#EEF1F5" stroke-width="6"/>
                                <circle cx="28" cy="28" r="24" fill="none" stroke="{{ $lf }}" stroke-width="6" stroke-linecap="round"
                                        stroke-dasharray="{{ 2 * 3.14159 * 24 }}" stroke-dashoffset="{{ 2 * 3.14159 * 24 * (1 - $this->security['score'] / 100) }}"/>
                            </svg>
                            <span class="absolute text-[13px] font-bold text-ink-900">{{ $this->security['score'] }}%</span>
                        </div>
                        <div>
                            <div class="text-[13.5px] font-bold text-ink-900">Niveau de sécurité : <span style="color: {{ $lf }}">{{ $this->security['level'] }}</span></div>
                            <p class="mt-0.5 text-[12px] text-ink-500">La double authentification renforcera encore votre compte (bientôt disponible).</p>
                        </div>
                    </div>

                    <div class="flex flex-col divide-y divide-ink-150">
                        @foreach ($this->security['checks'] as [$name, $ok, $note])
                            <div class="flex items-center justify-between gap-4 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-5 w-5 items-center justify-center rounded-full {{ $ok ? 'bg-[#E7F6EE] text-[#0F7A44]' : 'bg-ink-100 text-ink-400' }}">
                                        <svg width="12" height="12" viewBox="0 0 20 20" fill="none"><path d="{{ $ok ? 'M4 10l4 4 8-9' : 'M10 4v8M10 15h.01' }}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </span>
                                    <span class="text-[13.5px] font-semibold text-ink-800">{{ $name }}</span>
                                </div>
                                <span class="rounded-full {{ $ok ? 'bg-[#E7F6EE] text-[#0F7A44]' : 'bg-ink-100 text-ink-600' }} px-2.5 py-1 text-[11px] font-bold">{{ $note }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-settings.card>

                <x-settings.card title="Changer le mot de passe" subtitle="Choisissez un mot de passe d'au moins 8 caractères.">
                    <div class="grid gap-5 sm:max-w-md">
                        <x-settings.field label="Mot de passe actuel">
                            <input type="password" wire:model="pw.current" autocomplete="current-password" @class(['eac-input', 'border-danger' => $errors->has('pw.current')])>
                            @error('pw.current') <p class="eac-err">{{ $message }}</p> @enderror
                        </x-settings.field>
                        <x-settings.field label="Nouveau mot de passe">
                            <input type="password" wire:model="pw.new" autocomplete="new-password" @class(['eac-input', 'border-danger' => $errors->has('pw.new')])>
                            @error('pw.new') <p class="eac-err">{{ $message }}</p> @enderror
                        </x-settings.field>
                        <x-settings.field label="Confirmer le nouveau mot de passe">
                            <input type="password" wire:model="pw.confirm" autocomplete="new-password" class="eac-input">
                        </x-settings.field>
                    </div>
                    <div class="mt-5 flex justify-end">
                        <button wire:click="changePassword" wire:loading.attr="disabled" wire:target="changePassword"
                                class="inline-flex items-center gap-2 rounded-[10px] bg-brand-600 px-4 py-2.5 text-[13px] font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
                            <svg wire:loading.remove wire:target="changePassword" width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M6 9V6.5a4 4 0 018 0V9M5 9h10v7H5z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                            <svg wire:loading wire:target="changePassword" width="16" height="16" viewBox="0 0 20 20" fill="none" class="animate-spin"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" stroke-opacity="0.3"/><path d="M17 10a7 7 0 00-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            Mettre à jour
                        </button>
                    </div>
                </x-settings.card>

                <x-settings.card title="Double authentification (2FA)" subtitle="Un second facteur à la connexion.">
                    <div class="flex items-center justify-between gap-4 rounded-[12px] border border-dashed border-ink-300 p-4">
                        <div class="text-[13px] text-ink-600">Protégez votre compte avec une application d'authentification (TOTP).</div>
                        <span class="flex-shrink-0 rounded-full bg-ink-100 px-2.5 py-1 text-[11px] font-bold text-ink-600">Bientôt</span>
                    </div>
                </x-settings.card>

            {{-- ============================= SESSIONS ============================= --}}
            @elseif ($t === 'sessions')
                @php $sessions = $this->sessions; $others = $sessions->where('current', false)->count(); @endphp
                <x-settings.card title="Sessions actives" subtitle="Appareils connectés à votre compte.">
                    <div class="flex flex-col divide-y divide-ink-100">
                        @forelse ($sessions as $s)
                            <div wire:key="sess-{{ $s['id'] }}" class="flex items-center justify-between gap-4 py-3.5">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-[10px] bg-ink-100 text-ink-600">
                                        <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="9" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M7 16h6M9 13v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                    </span>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="text-[13.5px] font-semibold text-ink-900">{{ $s['device'] }} · {{ $s['browser'] }}</span>
                                            @if ($s['current'])<span class="rounded-full bg-[#E7F6EE] px-2 py-0.5 text-[10.5px] font-bold text-[#0F7A44]">Session actuelle</span>@endif
                                        </div>
                                        <div class="mt-0.5 text-[12px] text-ink-500">
                                            IP {{ $s['ip'] }}@if ($s['last']) · {{ $s['last']->diffForHumans() }}@endif
                                        </div>
                                    </div>
                                </div>
                                @unless ($s['current'])
                                    <button wire:click="revokeSession('{{ $s['id'] }}')" class="flex-shrink-0 rounded-[9px] border border-ink-300 px-3 py-1.5 text-[12px] font-semibold text-ink-700 hover:border-danger hover:bg-[#FDECEC] hover:text-danger">Déconnecter</button>
                                @endunless
                            </div>
                        @empty
                            <div class="py-10 text-center text-[13px] text-ink-500">Aucune session active enregistrée.</div>
                        @endforelse
                    </div>

                    @if ($others > 0)
                        <div class="mt-5 flex items-center justify-between gap-3 rounded-[12px] bg-ink-50 px-4 py-3">
                            <span class="text-[12.5px] text-ink-600">{{ $others }} autre{{ $others > 1 ? 's' : '' }} session{{ $others > 1 ? 's' : '' }} ouverte{{ $others > 1 ? 's' : '' }}.</span>
                            <button wire:click="logoutOthers" wire:confirm="Déconnecter toutes les autres sessions ?"
                                    class="rounded-[9px] bg-brand-600 px-3.5 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-700">Déconnecter les autres</button>
                        </div>
                    @endif
                    <x-settings.note>Le suivi s'appuie sur les sessions serveur (appareil, navigateur, IP, dernière activité). La localisation approximative par IP sera ajoutée ultérieurement.</x-settings.note>
                </x-settings.card>

            {{-- =========================== PRÉFÉRENCES =========================== --}}
            @elseif ($t === 'preferences')
                <x-settings.card title="Préférences d'affichage" subtitle="Personnalisez votre expérience de l'Adoption Center.">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-settings.field label="Langue">
                            <select wire:model="prefs.language" class="eac-input">
                                <option value="fr">Français</option>
                                <option value="en">Anglais</option>
                            </select>
                        </x-settings.field>
                        <x-settings.field label="Thème">
                            <select wire:model="prefs.theme" class="eac-input">
                                <option value="light">Clair</option>
                                <option value="dark">Sombre</option>
                                <option value="system">Système</option>
                            </select>
                        </x-settings.field>
                        <x-settings.field label="Densité d'affichage">
                            <select wire:model="prefs.density" class="eac-input">
                                <option value="compacte">Compacte</option>
                                <option value="normale">Normale</option>
                                <option value="confortable">Confortable</option>
                            </select>
                        </x-settings.field>
                        <x-settings.field label="Tableau de bord par défaut" hint="Page affichée juste après la connexion.">
                            <select wire:model="prefs.default_dashboard" class="eac-input">
                                <option value="dashboard">Dashboard</option>
                                <option value="schools">Écoles</option>
                                <option value="analytics">Analytics</option>
                                <option value="reports">Rapports</option>
                            </select>
                        </x-settings.field>
                    </div>
                    <x-settings.note>Le thème sombre est en cours de déploiement. La page d'accueil s'appliquera à la connexion une fois l'authentification active.</x-settings.note>
                </x-settings.card>

                <x-settings.card title="Mon espace" subtitle="Votre environnement personnalisé à la connexion.">
                    <x-settings.toggle model="prefs.ai_briefing" label="Briefing IA quotidien" desc="Recevoir chaque jour une synthèse des priorités générée par les règles métier (v1)." />
                    <x-settings.note>Vos écoles et rapports épinglés (onglet Favoris) constituent votre accès rapide personnel.</x-settings.note>
                </x-settings.card>

                <div class="flex justify-end">
                    <button wire:click="savePrefs" wire:loading.attr="disabled" wire:target="savePrefs"
                            class="inline-flex items-center gap-2 rounded-[10px] bg-brand-600 px-4 py-2.5 text-[13px] font-semibold text-white shadow-sm hover:bg-brand-700 disabled:opacity-60">
                        <svg wire:loading.remove wire:target="savePrefs" width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M4 10l4 4 8-9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <svg wire:loading wire:target="savePrefs" width="16" height="16" viewBox="0 0 20 20" fill="none" class="animate-spin"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" stroke-opacity="0.3"/><path d="M17 10a7 7 0 00-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        Enregistrer les préférences
                    </button>
                </div>

            {{-- ========================== NOTIFICATIONS ========================== --}}
            @elseif ($t === 'notifications')
                <x-settings.card title="Notifications personnelles" subtitle="Ce dont vous souhaitez être informé, et comment.">
                    <div class="mb-2 text-[12.5px] font-semibold text-ink-700">Types</div>
                    <div class="mb-5 flex flex-wrap gap-2">
                        @foreach (['alertes' => 'Alertes', 'rapports' => 'Rapports', 'campagnes' => 'Campagnes', 'adoption' => 'Adoption', 'revenus' => 'Revenus', 'ia' => 'IA'] as $val => $lbl)
                            <label class="cursor-pointer">
                                <input type="checkbox" value="{{ $val }}" wire:model="prefs.notif_types" class="peer sr-only">
                                <span class="inline-flex items-center gap-1.5 rounded-full border border-ink-300 px-3 py-1.5 text-[12.5px] font-semibold text-ink-600 peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:text-brand-700">{{ $lbl }}</span>
                            </label>
                        @endforeach
                    </div>

                    <div class="mb-2 text-[12.5px] font-semibold text-ink-700">Canaux</div>
                    <div class="mb-5 flex flex-wrap gap-2">
                        @foreach (['interface' => 'Interface', 'email' => 'E-mail', 'push' => 'Push (prévu)'] as $val => $lbl)
                            <label class="cursor-pointer {{ $val === 'push' ? 'opacity-50' : '' }}">
                                <input type="checkbox" value="{{ $val }}" wire:model="prefs.notif_channels" @disabled($val === 'push') class="peer sr-only">
                                <span class="inline-flex items-center gap-1.5 rounded-full border border-ink-300 px-3 py-1.5 text-[12.5px] font-semibold text-ink-600 peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:text-brand-700">{{ $lbl }}</span>
                            </label>
                        @endforeach
                    </div>

                    <x-settings.field label="Fréquence">
                        <select wire:model="prefs.notif_frequency" class="eac-input sm:max-w-xs">
                            <option value="immediate">Immédiate</option>
                            <option value="daily">Quotidienne</option>
                            <option value="weekly">Hebdomadaire</option>
                        </select>
                    </x-settings.field>

                    <x-settings.note>L'envoi par e-mail et push nécessite un connecteur de messagerie (à venir). Les notifications « Interface » sont visibles dans le module Notifications.</x-settings.note>

                    <div class="mt-5 flex justify-end">
                        <button wire:click="savePrefs" wire:loading.attr="disabled" wire:target="savePrefs"
                                class="inline-flex items-center gap-2 rounded-[10px] bg-brand-600 px-4 py-2.5 text-[13px] font-semibold text-white shadow-sm hover:bg-brand-700 disabled:opacity-60">
                            <svg wire:loading.remove wire:target="savePrefs" width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M4 10l4 4 8-9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <svg wire:loading wire:target="savePrefs" width="16" height="16" viewBox="0 0 20 20" fill="none" class="animate-spin"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" stroke-opacity="0.3"/><path d="M17 10a7 7 0 00-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            Enregistrer
                        </button>
                    </div>
                </x-settings.card>

            {{-- ============================= ACTIVITÉ ============================= --}}
            @elseif ($t === 'activite')
                <x-settings.card title="Activité récente" subtitle="Les derniers événements de la plateforme.">
                    <ol class="relative ml-2 border-l border-ink-200">
                        @forelse ($this->activity as $a)
                            @php $dot = $activityIcon[$a->category] ?? '#5B6472'; @endphp
                            <li class="relative mb-5 pl-6 last:mb-0">
                                <span class="absolute -left-[7px] top-1 h-3 w-3 rounded-full border-2 border-white" style="background: {{ $dot }}"></span>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-[13.5px] font-semibold text-ink-900">{{ $a->title }}</span>
                                    <span class="rounded-full bg-ink-100 px-2 py-0.5 text-[10.5px] font-bold uppercase text-ink-500">{{ $a->category }}</span>
                                    @if ($a->link_route && RouteFacade::has($a->link_route))
                                        <a href="{{ route($a->link_route, $a->resource_id ? [$a->resource_id] : []) }}" class="text-[11.5px] font-semibold text-brand-600 hover:underline">Ouvrir</a>
                                    @endif
                                </div>
                                @if ($a->description)<p class="mt-0.5 text-[12px] text-ink-500">{{ $a->description }}</p>@endif
                                <span class="text-[11px] text-ink-400">{{ optional($a->occurred_at)->translatedFormat('d M Y · H:i') }}</span>
                            </li>
                        @empty
                            <li class="pl-6 text-[13px] text-ink-500">Aucune activité enregistrée.</li>
                        @endforelse
                    </ol>
                    <x-settings.note>L'attribution des actions à chaque utilisateur (« qui a fait quoi ») arrivera avec l'authentification. Cette liste reflète l'activité globale de la plateforme.</x-settings.note>
                </x-settings.card>

            {{-- ============================= FAVORIS ============================= --}}
            @elseif ($t === 'favoris')
                <x-settings.card title="Épingler un favori" subtitle="Accédez d'un clic à vos écoles et rapports les plus utilisés.">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="sm:w-40">
                            <x-settings.field label="Type">
                                <select wire:model.live="favType" class="eac-input">
                                    <option value="school">École</option>
                                    <option value="report">Rapport</option>
                                </select>
                            </x-settings.field>
                        </div>
                        <div class="flex-1">
                            <x-settings.field label="Élément">
                                <select wire:model="favRef" class="eac-input">
                                    <option value="">— Choisir —</option>
                                    @foreach ($this->pickable[$favType] ?? [] as $ref => $label)
                                        <option value="{{ $ref }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </x-settings.field>
                        </div>
                        <button wire:click="addFavorite" class="rounded-[10px] bg-brand-600 px-4 py-2.5 text-[13px] font-semibold text-white shadow-sm hover:bg-brand-700">Épingler</button>
                    </div>
                </x-settings.card>

                <x-settings.card title="Mes favoris" subtitle="{{ $this->favorites->count() }} élément(s) épinglé(s).">
                    @forelse ($this->favorites as $f)
                        <div wire:key="fav-{{ $f->id }}" class="flex items-center justify-between gap-3 border-b border-ink-100 py-3 last:border-0">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-[9px] bg-brand-50 text-brand-700">
                                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="{{ $f->type === 'school' ? 'M10 3l7 4H3zM4 8h12v9H4zM9 12h2v5H9z' : 'M6 3h8v14H6zM8 7h4M8 10h4' }}" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                                </span>
                                <div class="min-w-0">
                                    <div class="truncate text-[13.5px] font-semibold text-ink-900">{{ $f->label }}</div>
                                    <div class="text-[11.5px] text-ink-500">{{ $f->type === 'school' ? 'École' : 'Rapport' }}</div>
                                </div>
                            </div>
                            <div class="flex flex-shrink-0 items-center gap-1">
                                @if ($f->link_route && RouteFacade::has($f->link_route))
                                    <a href="{{ route($f->link_route, [$f->ref_id]) }}" class="rounded-[8px] px-2.5 py-1.5 text-[12px] font-semibold text-brand-600 hover:bg-brand-50">Ouvrir</a>
                                @endif
                                <button wire:click="removeFavorite({{ $f->id }})" class="flex h-8 w-8 items-center justify-center rounded-[8px] text-ink-400 hover:bg-ink-100 hover:text-danger" title="Retirer">
                                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M5 6h10M8 6V4h4v2M6 6l1 10h6l1-10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-col items-center gap-2 py-10 text-center">
                            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-ink-100 text-ink-400"><svg width="22" height="22" viewBox="0 0 20 20" fill="none"><path d="M10 3l2 4.2 4.6.5-3.4 3.1 1 4.5L10 13l-4.2 2.4 1-4.5L3.4 7.7 8 7.2z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg></span>
                            <div class="text-[13.5px] font-semibold text-ink-800">Aucun favori épinglé</div>
                            <p class="max-w-sm text-[12px] text-ink-500">Épinglez une école ou un rapport ci-dessus pour le retrouver instantanément.</p>
                        </div>
                    @endforelse
                </x-settings.card>

            {{-- ========================= CONFIDENTIALITÉ ========================= --}}
            @elseif ($t === 'confidentialite')
                <x-settings.card title="Mes données" subtitle="Exportez ce qui vous concerne à tout moment.">
                    <div class="flex flex-col gap-3">
                        <div class="flex items-center justify-between gap-4 rounded-[12px] border border-ink-200 p-4">
                            <div><div class="text-[13.5px] font-semibold text-ink-900">Télécharger mes données</div><div class="mt-0.5 text-[12px] text-ink-500">Compte, préférences et favoris au format JSON.</div></div>
                            <button wire:click="downloadData" class="flex-shrink-0 rounded-[9px] border border-ink-300 bg-white px-3.5 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-100">Télécharger</button>
                        </div>
                        <div class="flex items-center justify-between gap-4 rounded-[12px] border border-ink-200 p-4">
                            <div><div class="text-[13.5px] font-semibold text-ink-900">Exporter mon activité</div><div class="mt-0.5 text-[12px] text-ink-500">Journal des événements récents au format CSV.</div></div>
                            <button wire:click="exportActivity" class="flex-shrink-0 rounded-[9px] border border-ink-300 bg-white px-3.5 py-2 text-[12.5px] font-semibold text-ink-800 hover:bg-ink-100">Exporter</button>
                        </div>
                    </div>
                </x-settings.card>

                <x-settings.card title="Supprimer mon compte" subtitle="Action définitive et encadrée.">
                    <div class="rounded-[12px] border border-danger/25 bg-[#FDF2F2] p-4" x-data="{ confirm: false }">
                        <div class="flex items-start gap-3">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" class="mt-0.5 flex-shrink-0 text-danger"><path d="M10 3l7 12H3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 8v3.5M10 13.5h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                            <div class="min-w-0 flex-1">
                                <div class="text-[13.5px] font-bold text-[#8A1C1C]">Désactiver et fermer mon compte</div>
                                <p class="mt-1 text-[12.5px] leading-relaxed text-[#8A1C1C]/85">
                                    Pour préserver la traçabilité (paternité des campagnes et diagnostics), la fermeture se traduit par une <strong>désactivation</strong>, pas un effacement dur. Vous serez déconnecté immédiatement et ne pourrez plus vous reconnecter ; un administrateur pourra réactiver le compte.
                                </p>

                                <div x-show="!confirm">
                                    <button @click="confirm = true" class="mt-3 rounded-[9px] border border-danger/40 bg-white px-3.5 py-2 text-[12.5px] font-semibold text-danger hover:bg-danger hover:text-white">Désactiver mon compte</button>
                                </div>

                                <div x-show="confirm" x-cloak class="mt-3">
                                    <label class="mb-1.5 block text-[12px] font-semibold text-[#8A1C1C]">Confirmez avec votre mot de passe</label>
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
                                        <div class="sm:w-64">
                                            <input type="password" wire:model="deletePassword" autocomplete="current-password" @class(['eac-input', 'border-danger' => $errors->has('deletePassword')])>
                                            @error('deletePassword') <p class="eac-err">{{ $message }}</p> @enderror
                                        </div>
                                        <button wire:click="deleteAccount" wire:loading.attr="disabled" wire:target="deleteAccount"
                                                class="rounded-[9px] bg-danger px-3.5 py-2 text-[12.5px] font-semibold text-white hover:bg-[#B91C1C] disabled:opacity-60">Confirmer la désactivation</button>
                                        <button type="button" @click="confirm = false" class="rounded-[9px] px-3 py-2 text-[12.5px] font-semibold text-ink-500 hover:bg-ink-100">Annuler</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </x-settings.card>
            @endif

        </div>
    </div>
</div>
