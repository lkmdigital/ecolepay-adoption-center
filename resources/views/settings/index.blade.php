<?php

use App\Domains\Activity\Actions\SyncActivityLog;
use App\Domains\Notifications\Actions\DetectNotifications;
use App\Domains\Settings\Support\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    /** Section active du centre de configuration. */
    #[Url]
    public string $section = 'general';

    /** Valeurs éditables, chargées depuis les réglages effectifs. */
    public array $form = [];

    /** Confirmation de dernière opération (bandeau de résultat). */
    public string $flash = '';

    public string $flashKind = 'success';

    /** Clés réellement éditables depuis l'UI (le reste est verrouillé/en lecture seule). */
    private const EDITABLE = [
        'platform_name', 'platform_org', 'default_landing', 'timezone', 'locale', 'currency', 'date_format',
        'engaged_min_payments', 'school_year_start_month', 'payment_window_end_month',
        'kpi_green_min', 'kpi_orange_min', 'critical_rate_max', 'critical_known_min', 'health_target',
        'campaign_default_channel', 'attribution_window_days',
        'notif_enabled', 'notif_drop_threshold', 'notif_critical_schools', 'notif_revenue_milestones', 'notif_digest',
        'report_default_period', 'report_footer', 'export_include_test',
        'theme', 'density',
        'ai_enabled', 'ai_model', 'ai_effort', 'ai_max_tokens',
    ];

    // La clé API est gérée à part (masquée, jamais renvoyée en clair au client).
    public string $aiKey = '';

    public string $aiTest = '';

    public function mount(): void
    {
        $all = Settings::all();
        foreach (self::EDITABLE as $key) {
            $this->form[$key] = $all[$key] ?? Settings::DEFAULTS[$key] ?? null;
        }
    }

    protected function rules(): array
    {
        return [
            'form.platform_name' => 'required|string|max:40',
            'form.platform_org' => 'required|string|max:40',
            'form.engaged_min_payments' => 'required|integer|min:2|max:10',
            'form.school_year_start_month' => 'required|integer|min:1|max:12',
            'form.payment_window_end_month' => 'required|integer|min:1|max:12',
            'form.kpi_green_min' => 'required|integer|min:1|max:100',
            'form.kpi_orange_min' => 'required|integer|min:1|max:100',
            'form.critical_rate_max' => 'required|integer|min:1|max:100',
            'form.critical_known_min' => 'required|integer|min:1|max:1000',
            'form.health_target' => 'required|integer|min:1|max:100',
            'form.attribution_window_days' => 'required|integer|min:1|max:365',
            'form.notif_drop_threshold' => 'required|integer|min:1|max:100',
            'form.report_footer' => 'nullable|string|max:120',
            'form.ai_max_tokens' => 'required|integer|min:256|max:8192',
        ];
    }

    public function save(): void
    {
        $this->validate();

        // Cohérence des seuils de couleur : vert doit rester au-dessus d'orange.
        if ((int) $this->form['kpi_green_min'] <= (int) $this->form['kpi_orange_min']) {
            $this->addError('form.kpi_green_min', 'Le seuil vert doit être supérieur au seuil orange.');

            return;
        }

        $casts = [
            'engaged_min_payments', 'school_year_start_month', 'payment_window_end_month',
            'kpi_green_min', 'kpi_orange_min', 'critical_rate_max', 'critical_known_min',
            'health_target', 'attribution_window_days', 'notif_drop_threshold', 'ai_max_tokens',
        ];
        $bools = ['notif_enabled', 'notif_critical_schools', 'notif_revenue_milestones', 'export_include_test', 'ai_enabled'];

        $payload = [];
        foreach (self::EDITABLE as $key) {
            $v = $this->form[$key] ?? null;
            if (in_array($key, $casts, true)) {
                $v = (int) $v;
            } elseif (in_array($key, $bools, true)) {
                $v = (bool) $v;
            }
            $payload[$key] = $v;
        }

        Settings::save($payload);

        $this->flashMessage('Paramètres enregistrés. La marque et les seuils sont appliqués sur toute la plateforme.');
    }

    // --- Assistant IA : clé API + test de connexion --------------------------

    public function saveApiKey(): void
    {
        $key = trim($this->aiKey);
        if ($key === '') {
            return;
        }
        \App\Domains\Settings\Support\Settings::save(['ai_api_key' => $key]);
        $this->aiKey = '';
        $this->flashMessage('Clé API enregistrée.');
    }

    public function clearApiKey(): void
    {
        \App\Domains\Settings\Support\Settings::save(['ai_api_key' => '']);
        $this->aiKey = '';
        $this->flashMessage('Clé API supprimée.', 'info');
    }

    public function testAi(): void
    {
        $ask = app(\App\Domains\AI\Actions\AskClaude::class);
        if (! $ask->isConfigured()) {
            $this->aiTest = 'error:Aucune clé API configurée.';

            return;
        }
        $res = $ask([['role' => 'user', 'content' => 'Réponds simplement « OK » si tu me reçois.']]);
        $this->aiTest = $res['ok']
            ? 'ok:Connexion réussie ('.($res['model'] ?? '').').'
            : 'error:'.match ($res['error'] ?? 'api') {
                'auth' => 'Clé API invalide ou refusée.',
                'rate_limit' => 'Limite de requêtes atteinte, réessayez plus tard.',
                'connection' => 'Impossible de joindre l\'API Claude.',
                'no_key' => 'Aucune clé API configurée.',
                default => 'Échec de l\'appel à l\'API Claude.',
            };
    }

    public function resetSection(string $section): void
    {
        // Restaure les valeurs par défaut d'usine pour la section courante.
        $map = $this->sectionKeys();
        foreach ($map[$section] ?? [] as $key) {
            if (in_array($key, self::EDITABLE, true)) {
                $this->form[$key] = Settings::DEFAULTS[$key] ?? $this->form[$key];
            }
        }
        $this->flashMessage('Valeurs par défaut restaurées pour cette section. Enregistrez pour confirmer.', 'info');
    }

    // --- Actions de maintenance réelles ---------------------------------------

    public function clearCache(): void
    {
        Artisan::call('cache:clear');
        Settings::flushCache();
        $this->flashMessage('Cache applicatif vidé.');
    }

    public function resyncActivity(): void
    {
        app(SyncActivityLog::class)();
        $this->flashMessage("Journal d'activité recalculé depuis les données.");
    }

    public function resyncNotifications(): void
    {
        app(DetectNotifications::class)();
        $this->flashMessage('Notifications & alertes recalculées.');
    }

    private function flashMessage(string $msg, string $kind = 'success'): void
    {
        $this->flash = $msg;
        $this->flashKind = $kind;
        $this->dispatch('settings-flash');
    }

    /** Correspondance section → clés (pour « réinitialiser cette section »). */
    private function sectionKeys(): array
    {
        return [
            'general' => ['platform_name', 'platform_org', 'default_landing', 'timezone', 'locale', 'currency', 'date_format'],
            'adoption' => ['engaged_min_payments', 'school_year_start_month', 'payment_window_end_month', 'kpi_green_min', 'kpi_orange_min', 'critical_rate_max', 'critical_known_min', 'health_target'],
            'campaigns' => ['campaign_default_channel', 'attribution_window_days'],
            'notifications' => ['notif_enabled', 'notif_drop_threshold', 'notif_critical_schools', 'notif_revenue_milestones', 'notif_digest'],
            'reports' => ['report_default_period', 'report_footer', 'export_include_test'],
            'appearance' => ['theme', 'density'],
            'assistant' => ['ai_enabled', 'ai_model', 'ai_effort', 'ai_max_tokens'],
        ];
    }

    /** Infos système réelles pour la section Maintenance / À propos. */
    public function system(): array
    {
        $dbOk = true;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $dbOk = false;
        }

        return [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'db_driver' => DB::connection()->getDriverName(),
            'db_ok' => $dbOk,
            'schools' => (int) DB::table('dim_schools')->where('is_test', false)->count(),
            'parents' => (int) DB::table('dim_parents')->where('is_test', false)->count(),
            'payments' => (int) DB::table('fact_payments')->where('is_test', false)->count(),
            'env' => app()->environment(),
        ];
    }
};

?>

@php
    // Navigation des sections : clé, libellé, sous-titre.
    $nav = [
        'general' => ['Général', 'Marque, langue, devise'],
        'adoption' => ['Adoption & règles métier', "Définition de l'adoption et seuils"],
        'campaigns' => ['Campagnes', "Canaux et attribution"],
        'notifications' => ['Notifications', "Alertes et seuils de détection"],
        'reports' => ['Rapports & exports', "Période, pied de page, données de test"],
        'assistant' => ['Assistant IA', "Clé API Claude et modèle"],
        'integrations' => ['Intégrations', "Sources et connecteurs"],
        'security' => ['Sécurité', "Accès et authentification"],
        'appearance' => ['Apparence', "Thème et densité"],
        'maintenance' => ['Maintenance', "Cache et recalculs"],
        'about' => ['À propos', "Version et équipe"],
    ];

    $navIcons = [
        'general' => 'M4 5h12M4 10h12M4 15h8',
        'adoption' => 'M10 3l2 4 4 .6-3 3 .7 4-3.7-2-3.7 2 .7-4-3-3 4-.6z',
        'campaigns' => 'M3 7h10l4-3v12l-4-3H3z',
        'notifications' => 'M6 8a4 4 0 018 0c0 4 1.5 5 1.5 5h-11S6 12 6 8z',
        'reports' => 'M6 3h8v14H6zM8 7h4M8 10h4M8 13h2',
        'assistant' => 'M5 6h10v7H8l-3 3zM8 9h.01M11 9h.01',
        'integrations' => 'M7 3v4M13 3v4M4 7h12v3a6 6 0 01-12 0z',
        'security' => 'M10 3l6 2v5c0 4-3 6-6 7-3-1-6-3-6-7V5z',
        'appearance' => 'M10 3a7 7 0 100 14 3 3 0 010-6 3 3 0 000-6z',
        'maintenance' => 'M8 3l2 2-1 2 3 3 2-1 2 2-3 3-2-1-3-3 1-2z',
        'about' => 'M10 3a7 7 0 100 14 7 7 0 000-14zM10 8v5M10 6.5h.01',
    ];

    $s = $this->section;
    $sys = $this->system();

    // Petit badge « Actif » = réglage déjà appliqué en temps réel dans l'app.
    $liveTag = '<span class="ml-2 inline-flex items-center gap-1 rounded-full bg-[#E7F6EE] px-2 py-0.5 text-[10px] font-bold text-[#0F7A44]"><span class="h-1.5 w-1.5 rounded-full bg-[#189B57]"></span>Actif</span>';
@endphp

<div class="mx-auto max-w-[1180px]"
     x-data="{ toast: false, msg: '', kind: 'success' }"
     @settings-flash.window="msg = $wire.flash; kind = $wire.flashKind; toast = true; clearTimeout(window._st); window._st = setTimeout(() => toast = false, 3200)">

    {{-- Toast flottant --}}
    <div x-show="toast" x-cloak x-transition
         class="fixed bottom-6 right-6 z-50 flex items-center gap-2.5 rounded-xl px-4 py-3 text-[13px] font-semibold text-white shadow-lg"
         :class="kind === 'info' ? 'bg-ink-800' : 'bg-[#189B57]'">
        <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M4 10l4 4 8-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span x-text="msg"></span>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[240px_1fr]">

        {{-- Rail de navigation des sections --}}
        <aside class="lg:sticky lg:top-0 lg:self-start">
            <nav class="flex flex-col gap-0.5 rounded-[14px] border border-ink-200 bg-white p-2 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                @foreach ($nav as $key => [$label, $sub])
                    <button wire:click="$set('section', '{{ $key }}')"
                            class="flex items-center gap-2.5 rounded-[10px] px-3 py-2.5 text-left transition-colors
                                   {{ $s === $key ? 'bg-brand-50 text-brand-700' : 'text-ink-800 hover:bg-ink-100' }}">
                        <svg width="17" height="17" viewBox="0 0 20 20" fill="none" class="flex-shrink-0"><path d="{{ $navIcons[$key] }}" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span class="min-w-0">
                            <span class="block text-[13.5px] font-semibold leading-tight">{{ $label }}</span>
                            <span class="block truncate text-[11px] {{ $s === $key ? 'text-brand-600/70' : 'text-ink-500' }}">{{ $sub }}</span>
                        </span>
                    </button>
                @endforeach
            </nav>
        </aside>

        {{-- Contenu de la section --}}
        <div class="min-w-0">

            {{-- ============================ GÉNÉRAL ============================ --}}
            @if ($s === 'general')
                <x-settings.card title="Identité de la plateforme" subtitle="Nom affiché dans le menu et l'onglet du navigateur.">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-settings.field label="Nom de la plateforme" :live="true">
                            <input type="text" wire:model="form.platform_name" @class(['eac-input', 'border-danger' => $errors->has('form.platform_name')])>
                            @error('form.platform_name') <p class="eac-err">{{ $message }}</p> @enderror
                        </x-settings.field>
                        <x-settings.field label="Organisation" :live="true">
                            <input type="text" wire:model="form.platform_org" class="eac-input">
                        </x-settings.field>
                    </div>
                </x-settings.card>

                <x-settings.card title="Localisation" subtitle="Fuseau, langue et devise appliqués aux affichages.">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-settings.field label="Page d'accueil par défaut">
                            <select wire:model="form.default_landing" class="eac-input">
                                <option value="dashboard">Dashboard exécutif</option>
                                <option value="schools">Écoles</option>
                                <option value="analytics">Analytics</option>
                            </select>
                        </x-settings.field>
                        <x-settings.field label="Fuseau horaire">
                            <select wire:model="form.timezone" class="eac-input">
                                <option value="Africa/Abidjan">Africa/Abidjan (GMT)</option>
                                <option value="Europe/Paris">Europe/Paris</option>
                                <option value="UTC">UTC</option>
                            </select>
                        </x-settings.field>
                        <x-settings.field label="Langue">
                            <select wire:model="form.locale" class="eac-input">
                                <option value="fr">Français</option>
                                <option value="en">English</option>
                            </select>
                        </x-settings.field>
                        <x-settings.field label="Devise">
                            <select wire:model="form.currency" class="eac-input">
                                <option value="FCFA">FCFA (XOF)</option>
                                <option value="EUR">Euro (€)</option>
                            </select>
                        </x-settings.field>
                    </div>
                    <x-settings.note>Le fuseau, la langue et la devise sont enregistrés et repris progressivement par les affichages ; le formatage monétaire de l'entrepôt reste en FCFA.</x-settings.note>
                </x-settings.card>

                <x-settings.actions />

            {{-- ==================== ADOPTION & RÈGLES MÉTIER ==================== --}}
            @elseif ($s === 'adoption')
                <div class="mb-5 flex items-start gap-3 rounded-[13px] border border-brand-200 bg-brand-50 p-4">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" class="mt-0.5 flex-shrink-0 text-brand-600"><path d="M10 2l7 3v5c0 4.5-3.2 7-7 8-3.8-1-7-3.5-7-8V5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M7 10l2 2 4-4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <div>
                        <div class="flex items-center gap-2 text-[13.5px] font-bold text-brand-800">
                            Règle d'adoption <span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-bold text-brand-700">Verrouillé</span>
                        </div>
                        <p class="mt-1 text-[12.5px] leading-relaxed text-brand-800/80">
                            L'adoption d'un parent = <strong>son premier paiement via l'app</strong>, jamais la création de compte.
                            Parcours : connu → inscrit → <strong>adoptant ⭐</strong> → engagé. Cette définition est le socle de tous les KPI et n'est pas modifiable ici.
                        </p>
                    </div>
                </div>

                <x-settings.card title="Seuils du parcours d'adoption" subtitle="Reprennent config/eac.php et pilotent le calcul du statut.">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-settings.field label="Paiements minimum pour « engagé »" hint="Un parent devient « engagé » à partir de ce nombre de paiements réussis.">
                            <input type="number" min="2" max="10" wire:model="form.engaged_min_payments" @class(['eac-input', 'border-danger' => $errors->has('form.engaged_min_payments')])>
                            @error('form.engaged_min_payments') <p class="eac-err">{{ $message }}</p> @enderror
                        </x-settings.field>
                        <x-settings.field label="Mois de début d'année scolaire" hint="Frontière d'année scolaire (rentrée nationale).">
                            <input type="number" min="1" max="12" wire:model="form.school_year_start_month" class="eac-input">
                        </x-settings.field>
                        <x-settings.field label="Fin de la fenêtre de paiement" hint="Mois de fin de tolérance de renouvellement.">
                            <input type="number" min="1" max="12" wire:model="form.payment_window_end_month" class="eac-input">
                        </x-settings.field>
                    </div>
                </x-settings.card>

                <x-settings.card title="Seuils des KPI d'adoption" subtitle="Bornes de couleur du taux d'adoption et repérage des écoles critiques.">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-settings.field label="Seuil « bon » — vert (%)" hint="Taux d'adoption au-dessus duquel une école est en vert.">
                            <input type="number" min="1" max="100" wire:model="form.kpi_green_min" @class(['eac-input', 'border-danger' => $errors->has('form.kpi_green_min')])>
                            @error('form.kpi_green_min') <p class="eac-err">{{ $message }}</p> @enderror
                        </x-settings.field>
                        <x-settings.field label="Seuil « moyen » — orange (%)" hint="En dessous de ce seuil, l'école bascule en rouge.">
                            <input type="number" min="1" max="100" wire:model="form.kpi_orange_min" class="eac-input">
                        </x-settings.field>
                        <x-settings.field label="École critique sous (%)" hint="Taux d'adoption en dessous duquel une école est signalée critique.">
                            <input type="number" min="1" max="100" wire:model="form.critical_rate_max" class="eac-input">
                        </x-settings.field>
                        <x-settings.field label="… avec au moins (parents connus)" hint="Volume minimum de parents connus pour qualifier la criticité.">
                            <input type="number" min="1" max="1000" wire:model="form.critical_known_min" class="eac-input">
                        </x-settings.field>
                        <x-settings.field label="Score de santé cible" hint="Objectif du score composite (0–100) affiché dans le pilotage.">
                            <input type="number" min="1" max="100" wire:model="form.health_target" class="eac-input">
                        </x-settings.field>
                    </div>
                    <x-settings.note>Ces seuils centralisent des règles aujourd'hui dispersées dans le code. Ils sont enregistrés ici et lus par les modules à mesure qu'ils s'y branchent — les écrans déjà câblés (pilotage, notifications) les reprennent.</x-settings.note>
                </x-settings.card>

                <x-settings.actions section="adoption" />

            {{-- ============================ CAMPAGNES ============================ --}}
            @elseif ($s === 'campaigns')
                <x-settings.card title="Paramètres des opérations marketing" subtitle="Valeurs par défaut à la création et à la mesure d'attribution.">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-settings.field label="Canal par défaut">
                            <select wire:model="form.campaign_default_channel" class="eac-input">
                                <option value="sms">SMS</option>
                                <option value="email">Email</option>
                                <option value="push">Notification push</option>
                                <option value="social">Réseaux sociaux</option>
                                <option value="field">Action terrain</option>
                            </select>
                        </x-settings.field>
                        <x-settings.field label="Fenêtre d'attribution (jours)" hint="Délai après contact pendant lequel un premier paiement est attribué à l'opération.">
                            <input type="number" min="1" max="365" wire:model="form.attribution_window_days" class="eac-input">
                        </x-settings.field>
                    </div>
                    <x-settings.note>Les campagnes sont exécutées dans Perfect CX. EAC importe les listes de contacts et mesure l'attribution par empreinte de téléphone (HMAC), sans envoyer de message lui-même.</x-settings.note>
                </x-settings.card>
                <x-settings.actions section="campaigns" />

            {{-- ========================== NOTIFICATIONS ========================== --}}
            @elseif ($s === 'notifications')
                <x-settings.card title="Détection des alertes" subtitle="Ce que la plateforme surveille et signale automatiquement.">
                    <div class="flex flex-col divide-y divide-ink-150">
                        <x-settings.toggle model="form.notif_enabled" label="Activer la détection des alertes" desc="Analyse les écoles, campagnes et revenus pour lever des alertes." />
                        <x-settings.toggle model="form.notif_critical_schools" label="Écoles critiques" desc="Signaler les écoles dont le taux d'adoption chute sous le seuil." />
                        <x-settings.toggle model="form.notif_revenue_milestones" label="Jalons de revenus" desc="Notifier au franchissement des paliers de revenus." />
                    </div>
                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <x-settings.field label="Seuil de chute des premiers paiements (%)" hint="Alerte si les premiers paiements baissent d'au moins ce pourcentage sur 30 jours.">
                            <input type="number" min="1" max="100" wire:model="form.notif_drop_threshold" class="eac-input">
                        </x-settings.field>
                        <x-settings.field label="Fréquence du récapitulatif">
                            <select wire:model="form.notif_digest" class="eac-input">
                                <option value="realtime">Temps réel</option>
                                <option value="daily">Quotidien</option>
                                <option value="weekly">Hebdomadaire</option>
                            </select>
                        </x-settings.field>
                    </div>
                    <x-settings.note>L'envoi par email/SMS des récapitulatifs nécessite un connecteur de messagerie (à venir). Les alertes restent consultables dans le module Notifications.</x-settings.note>
                </x-settings.card>
                <x-settings.actions section="notifications" />

            {{-- ======================= RAPPORTS & EXPORTS ======================= --}}
            @elseif ($s === 'reports')
                <x-settings.card title="Rapports & exports" subtitle="Valeurs par défaut de génération et d'export.">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-settings.field label="Période par défaut">
                            <select wire:model="form.report_default_period" class="eac-input">
                                <option value="last_30_days">30 derniers jours</option>
                                <option value="last_90_days">90 derniers jours</option>
                                <option value="school_year">Année scolaire</option>
                                <option value="all_time">Tout l'historique</option>
                            </select>
                        </x-settings.field>
                        <x-settings.field label="Pied de page des rapports">
                            <input type="text" wire:model="form.report_footer" class="eac-input" placeholder="Confidentiel — LKM Digital">
                        </x-settings.field>
                    </div>
                    <div class="mt-5">
                        <x-settings.toggle model="form.export_include_test" label="Inclure les données de test dans les exports" desc="Déconseillé — les données de démonstration seraient mêlées aux vraies." />
                    </div>
                </x-settings.card>
                <x-settings.actions section="reports" />

            {{-- =========================== ASSISTANT IA =========================== --}}
            @elseif ($s === 'assistant')
                @php $aiConfigured = app(\App\Domains\AI\Actions\AskClaude::class)->isConfigured(); @endphp

                <x-settings.card title="Clé API Claude" subtitle="Connectez l'Assistant IA à votre compte Anthropic.">
                    <div class="mb-4 flex items-center gap-2.5 rounded-[12px] border p-3.5 {{ $aiConfigured ? 'border-[#189B57]/30 bg-[#E7F6EE]' : 'border-warning/30 bg-[#FEF9EF]' }}">
                        <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full {{ $aiConfigured ? 'bg-[#189B57] text-white' : 'bg-warning text-white' }}">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="{{ $aiConfigured ? 'M4 10l4 4 8-9' : 'M10 4v8M10 15h.01' }}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <div class="text-[13px] font-semibold {{ $aiConfigured ? 'text-[#0F7A44]' : 'text-[#8A5A06]' }}">
                            {{ $aiConfigured ? 'Une clé API est configurée — l’Assistant IA est actif.' : 'Aucune clé API configurée — l’Assistant IA est en attente.' }}
                        </div>
                    </div>

                    <x-settings.field label="Clé API Anthropic" hint="Collez votre clé (format sk-ant-…). Elle est stockée côté serveur et n’est jamais réaffichée en clair.">
                        <input type="password" wire:model="aiKey" class="eac-input" placeholder="{{ $aiConfigured ? '•••••••••••••••• (une clé est enregistrée)' : 'sk-ant-...' }}" autocomplete="off">
                    </x-settings.field>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <button wire:click="saveApiKey" class="rounded-[10px] bg-brand-600 px-4 py-2.5 text-[13px] font-semibold text-white hover:bg-brand-700">Enregistrer la clé</button>
                        <button wire:click="testAi" wire:loading.attr="disabled" wire:target="testAi" class="inline-flex items-center gap-2 rounded-[10px] border border-ink-300 bg-white px-4 py-2.5 text-[13px] font-semibold text-ink-800 hover:bg-ink-100 disabled:opacity-60">
                            <svg wire:loading wire:target="testAi" width="15" height="15" viewBox="0 0 20 20" fill="none" class="animate-spin"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" stroke-opacity="0.3"/><path d="M17 10a7 7 0 00-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            Tester la connexion
                        </button>
                        @if ($aiConfigured)
                            <button wire:click="clearApiKey" class="rounded-[10px] px-4 py-2.5 text-[13px] font-semibold text-danger hover:bg-[#FDECEC]">Supprimer la clé</button>
                        @endif
                    </div>

                    @if ($aiTest !== '')
                        @php [$kind, $msg] = explode(':', $aiTest, 2); @endphp
                        <div class="mt-3 flex items-center gap-2 rounded-[10px] px-3.5 py-2.5 text-[12.5px] font-semibold {{ $kind === 'ok' ? 'bg-[#E7F6EE] text-[#0F7A44]' : 'bg-[#FDECEC] text-danger' }}">
                            <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="{{ $kind === 'ok' ? 'M4 10l4 4 8-9' : 'M6 6l8 8M14 6l-8 8' }}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            {{ $msg }}
                        </div>
                    @endif

                    <x-settings.note>La clé peut aussi être définie via la variable d’environnement <span class="font-mono">ANTHROPIC_API_KEY</span> ; la clé saisie ici prime. Aucun secret n’est affiché après enregistrement.</x-settings.note>
                </x-settings.card>

                <x-settings.card title="Comportement de l'assistant" subtitle="Modèle et réactivité des réponses.">
                    <x-settings.toggle model="form.ai_enabled" label="Activer l'Assistant IA" desc="Rend le module Assistant IA accessible et interrogeable." />
                    <div class="mt-4 grid gap-5 sm:grid-cols-2">
                        <x-settings.field label="Modèle Claude" hint="Opus = le plus fin ; Sonnet = rapide et économique ; Haiku = le plus rapide.">
                            <select wire:model="form.ai_model" class="eac-input">
                                <option value="claude-opus-5">Claude Opus 5 (le plus capable)</option>
                                <option value="claude-sonnet-5">Claude Sonnet 5 (rapide, économique)</option>
                                <option value="claude-haiku-4-5">Claude Haiku 4.5 (le plus rapide)</option>
                            </select>
                        </x-settings.field>
                        <x-settings.field label="Effort de raisonnement" hint="Plus l'effort est élevé, plus la réponse est fouillée — mais plus lente.">
                            <select wire:model="form.ai_effort" class="eac-input">
                                <option value="low">Faible (réponses rapides)</option>
                                <option value="medium">Moyen</option>
                                <option value="high">Élevé (analyses poussées)</option>
                            </select>
                        </x-settings.field>
                        <x-settings.field label="Longueur maximale (tokens)" hint="Plafond de longueur d'une réponse.">
                            <input type="number" min="256" max="8192" wire:model="form.ai_max_tokens" class="eac-input">
                        </x-settings.field>
                    </div>
                    <x-settings.note>Les réponses sont ancrées sur un instantané des vraies données EcolePay (KPI, écoles, campagnes) injecté dans le contexte : l'assistant ne doit pas inventer de chiffres. Le coût des requêtes est facturé sur votre compte Anthropic.</x-settings.note>
                </x-settings.card>

                <x-settings.actions section="assistant" />

            {{-- =========================== INTÉGRATIONS =========================== --}}
            @elseif ($s === 'integrations')
                <x-settings.card title="Sources & connecteurs" subtitle="État réel des flux de données de la plateforme.">
                    @php
                        $integrations = [
                            ['Base EcolePay (entrepôt)', "Synchronisation des écoles, parents et paiements.", $sys['db_ok'] ? 'ok' : 'ko', $sys['db_ok'] ? 'Connecté · '.strtoupper($sys['db_driver']) : 'Indisponible'],
                            ['Perfect CX — campagnes', "Import manuel des listes de contacts pour la mesure d'attribution.", 'partial', 'Import manuel'],
                            ['Meta / WhatsApp Business', "Envoi de messages et retours de campagne.", 'todo', 'À venir'],
                            ['Passerelle SMS', "Envoi des SMS d'alerte et récapitulatifs.", 'todo', 'À venir'],
                        ];
                        $stMeta = [
                            'ok' => ['#189B57', '#E7F6EE', 'Connecté'],
                            'partial' => ['#D97706', '#FEF3E2', 'Partiel'],
                            'todo' => ['#5B6472', '#F2F3F5', 'À venir'],
                            'ko' => ['#DC2626', '#FDECEC', 'Erreur'],
                        ];
                    @endphp
                    <div class="flex flex-col gap-3">
                        @foreach ($integrations as [$name, $desc, $st, $note])
                            @php [$fg, $bg, $lbl] = $stMeta[$st]; @endphp
                            <div class="flex items-start justify-between gap-4 rounded-[12px] border border-ink-200 p-4">
                                <div class="min-w-0">
                                    <div class="text-[13.5px] font-semibold text-ink-900">{{ $name }}</div>
                                    <div class="mt-0.5 text-[12px] text-ink-500">{{ $desc }}</div>
                                </div>
                                <div class="flex flex-shrink-0 flex-col items-end gap-1">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold" style="background: {{ $bg }}; color: {{ $fg }}">
                                        <span class="h-1.5 w-1.5 rounded-full" style="background: {{ $fg }}"></span>{{ $lbl }}
                                    </span>
                                    <span class="text-[11px] text-ink-500">{{ $note }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <x-settings.note>Ces états reflètent la configuration réelle. Les connecteurs « à venir » seront activés quand les accès seront fournis — aucune configuration factice n'est proposée ici.</x-settings.note>
                </x-settings.card>

            {{-- ============================= SÉCURITÉ ============================= --}}
            @elseif ($s === 'security')
                <div class="mb-5 flex items-start gap-3 rounded-[13px] border border-warning/30 bg-[#FEF9EF] p-4">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" class="mt-0.5 flex-shrink-0 text-warning"><path d="M10 3l7 12H3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 8v3.5M10 13.5h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    <div>
                        <div class="text-[13.5px] font-bold text-[#8A5A06]">Authentification non encore posée</div>
                        <p class="mt-1 text-[12.5px] leading-relaxed text-[#8A5A06]/85">
                            La plateforme n'a pas encore de connexion utilisateur. Les réglages de sécurité ci-dessous seront activés avec le module
                            <strong>Utilisateurs &amp; rôles</strong> (rôles déjà installés via spatie/permission, table utilisateurs vide). Aucun réglage de sécurité factice n'est enregistré.
                        </p>
                    </div>
                </div>

                <x-settings.card title="Accès & sessions" subtitle="Disponible après la mise en place de l'authentification.">
                    <div class="flex flex-col gap-3 opacity-60">
                        @foreach ([
                            ['Double authentification (2FA)', "Renforce la connexion par un second facteur."],
                            ['Durée de session', "Déconnexion automatique après inactivité."],
                            ['Politique de mot de passe', "Longueur et complexité minimales."],
                            ['Journal des connexions', "Adresse IP, appareil et horodatage."],
                        ] as [$name, $desc])
                            <div class="flex items-center justify-between gap-4 rounded-[12px] border border-dashed border-ink-300 p-4">
                                <div>
                                    <div class="text-[13.5px] font-semibold text-ink-800">{{ $name }}</div>
                                    <div class="mt-0.5 text-[12px] text-ink-500">{{ $desc }}</div>
                                </div>
                                <span class="flex-shrink-0 rounded-full bg-ink-100 px-2.5 py-1 text-[11px] font-bold text-ink-600">À venir</span>
                            </div>
                        @endforeach
                    </div>
                </x-settings.card>

            {{-- ============================ APPARENCE ============================ --}}
            @elseif ($s === 'appearance')
                <x-settings.card title="Apparence" subtitle="Préférences d'affichage de la plateforme.">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-settings.field label="Thème">
                            <select wire:model="form.theme" class="eac-input">
                                <option value="light">Clair</option>
                                <option value="dark">Sombre</option>
                                <option value="system">Système</option>
                            </select>
                        </x-settings.field>
                        <x-settings.field label="Densité d'affichage">
                            <select wire:model="form.density" class="eac-input">
                                <option value="confortable">Confortable</option>
                                <option value="compact">Compact</option>
                            </select>
                        </x-settings.field>
                    </div>
                    <x-settings.note>La préférence est enregistrée. Le thème sombre est en cours de déploiement — l'interface est actuellement optimisée pour le thème clair.</x-settings.note>
                </x-settings.card>
                <x-settings.actions section="appearance" />

            {{-- =========================== MAINTENANCE =========================== --}}
            @elseif ($s === 'maintenance')
                <x-settings.card title="Recalculs & cache" subtitle="Opérations réelles sur les données dérivées.">
                    <div class="flex flex-col gap-3">
                        <x-settings.maint title="Recalculer le journal d'activité" desc="Reconstruit les jalons métier et les traces techniques depuis les données."
                            action="resyncActivity" cta="Recalculer" />
                        <x-settings.maint title="Recalculer les notifications & alertes" desc="Rejoue la détection des anomalies et met à jour les alertes."
                            action="resyncNotifications" cta="Recalculer" />
                        <x-settings.maint title="Vider le cache applicatif" desc="Purge le cache (dont les réglages) pour forcer une relecture."
                            action="clearCache" cta="Vider" />
                    </div>
                </x-settings.card>

                <x-settings.card title="État du système" subtitle="Informations réelles de l'environnement.">
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-[13px] sm:grid-cols-3">
                        <div><dt class="text-ink-500">Environnement</dt><dd class="font-semibold text-ink-900">{{ $sys['env'] }}</dd></div>
                        <div><dt class="text-ink-500">PHP</dt><dd class="font-semibold text-ink-900">{{ $sys['php'] }}</dd></div>
                        <div><dt class="text-ink-500">Laravel</dt><dd class="font-semibold text-ink-900">{{ $sys['laravel'] }}</dd></div>
                        <div><dt class="text-ink-500">Base de données</dt><dd class="font-semibold text-ink-900">{{ strtoupper($sys['db_driver']) }} · {{ $sys['db_ok'] ? 'OK' : 'KO' }}</dd></div>
                        <div><dt class="text-ink-500">Écoles</dt><dd class="font-semibold text-ink-900">{{ number_format($sys['schools'], 0, ',', ' ') }}</dd></div>
                        <div><dt class="text-ink-500">Parents</dt><dd class="font-semibold text-ink-900">{{ number_format($sys['parents'], 0, ',', ' ') }}</dd></div>
                    </dl>
                </x-settings.card>

            {{-- ============================= À PROPOS ============================= --}}
            @elseif ($s === 'about')
                <x-settings.card title="À propos" subtitle="EcolePay Adoption Center (EAC).">
                    <div class="flex items-center gap-4">
                        <img src="/images/ecolepay-mark.png" alt="EcolePay" class="h-12 w-12 flex-shrink-0 object-contain">
                        <div>
                            <div class="text-[15px] font-bold text-ink-900">{{ $this->form['platform_name'] ?? 'Adoption Center' }}</div>
                            <div class="text-[12.5px] text-ink-500">Centre de pilotage de l'adoption d'EcolePay — LKM Digital</div>
                        </div>
                    </div>
                    <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-3 text-[13px] sm:grid-cols-3">
                        <div><dt class="text-ink-500">Version</dt><dd class="font-semibold text-ink-900">v1.0 · Sprint 16</dd></div>
                        <div><dt class="text-ink-500">Stack</dt><dd class="font-semibold text-ink-900">Laravel · Livewire · Flux</dd></div>
                        <div><dt class="text-ink-500">Éditeur</dt><dd class="font-semibold text-ink-900">LKM Digital</dd></div>
                    </dl>
                    <x-settings.note>« IA » désigne partout dans l'app des règles métier déterministes (v1), pas un modèle de langage.</x-settings.note>
                </x-settings.card>
            @endif

        </div>
    </div>
</div>
