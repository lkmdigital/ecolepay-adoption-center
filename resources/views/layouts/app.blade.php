@php
    use Illuminate\Support\Facades\Route as RouteFacade;

    // Chaque module : libellé, nom de route (si elle existe), titre et sous-titre.
    $modules = [
        'dashboard' => ['Dashboard', 'dashboard.index', 'Dashboard exécutif', "Vue d'ensemble de l'adoption d'EcolePay"],
        'schools' => ['Écoles', 'schools.index', 'Écoles', "Suivi de l'adoption par établissement"],
        'parents' => ['Parents', 'parents.index', 'Parents', 'Recherche et parcours des parents'],
        'campaigns' => ['Campagnes', 'campaigns.index', 'Campagnes', 'Import et suivi des campagnes'],
        'analytics' => ['Analytics', 'analytics.index', 'Analytics', 'Analyses et tendances'],
        'reports' => ['Rapports', 'reports.index', 'Rapports', 'Génération et export'],
        'assistant' => ['Assistant IA', 'assistant.index', 'Assistant IA', 'Copilote décisionnel'],
        'settings' => ['Paramètres', 'settings.index', 'Paramètres', 'Configuration de la plateforme'],
        // Modules d'administration : accessibles depuis le menu avatar, pas la sidebar.
        'notifications' => ['Notifications', 'notifications.index', 'Notifications & alertes', 'Anomalies et alertes'],
        'users' => ['Utilisateurs', 'users.index', 'Utilisateurs & rôles', 'Gestion des accès'],
        'activity' => ["Journal d'activité", 'activity.index', "Journal d'activité", 'Audit des actions'],
    ];

    // Module actif déduit du nom de la route courante (dashboard.index → dashboard).
    $routeName = RouteFacade::currentRouteName() ?? '';
    $active ??= explode('.', $routeName)[0] ?: 'dashboard';
    if (! isset($modules[$active])) {
        $active = 'dashboard';
    }
    $header ??= $modules[$active][2];
    $subheader ??= $modules[$active][3];

    // Navigation principale : les 8 menus de la maquette.
    $primary = ['dashboard', 'schools', 'parents', 'campaigns', 'analytics', 'reports', 'assistant', 'settings'];
    // Menus d'administration : rendus dans le menu déroulant de l'avatar.
    $adminMenu = ['users', 'activity'];

    $linkOf = fn ($key) => RouteFacade::has($modules[$key][1]) ? route($modules[$key][1]) : '#';

    $user = auth()->user();
    $userName = $user?->name ?? 'Utilisateur EAC';
    $userRole = $user?->job_title ?? 'Direction';
    $initials = \Illuminate\Support\Str::of($userName)->explode(' ')->map(fn ($p) => \Illuminate\Support\Str::substr($p, 0, 1))->take(2)->implode('');

    $icons = [
        'dashboard' => '<rect x="3" y="3" width="6" height="6" rx="1.5" fill="currentColor"/><rect x="11" y="3" width="6" height="6" rx="1.5" fill="currentColor" opacity="0.45"/><rect x="3" y="11" width="6" height="6" rx="1.5" fill="currentColor" opacity="0.45"/><rect x="11" y="11" width="6" height="6" rx="1.5" fill="currentColor"/>',
        'schools' => '<polygon points="10,2 17,7 3,7" fill="currentColor"/><rect x="4" y="7.5" width="12" height="9.5" rx="1" stroke="currentColor" stroke-width="1.6" fill="none"/><rect x="9" y="12" width="2" height="5" fill="currentColor"/>',
        'parents' => '<circle cx="7.2" cy="6.5" r="3" stroke="currentColor" stroke-width="1.6"/><circle cx="14" cy="8" r="2.2" stroke="currentColor" stroke-width="1.6" opacity="0.6"/><path d="M2.5 17c0-3 2.1-5 4.7-5s4.7 2 4.7 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M12.3 17c0-2.2 1.4-3.8 3.2-3.8s3.2 1.6 3.2 3.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" opacity="0.6"/>',
        'campaigns' => '<rect x="2.5" y="4" width="15" height="10" rx="3" stroke="currentColor" stroke-width="1.6"/><polygon points="6,14 6,18 10,14" fill="currentColor"/><circle cx="7" cy="9" r="1" fill="currentColor"/><circle cx="10" cy="9" r="1" fill="currentColor"/><circle cx="13" cy="9" r="1" fill="currentColor"/>',
        'analytics' => '<rect x="3" y="11" width="3.5" height="6" rx="1" fill="currentColor" opacity="0.5"/><rect x="8.3" y="6" width="3.5" height="11" rx="1" fill="currentColor"/><rect x="13.6" y="2.5" width="3.5" height="14.5" rx="1" fill="currentColor" opacity="0.75"/>',
        'reports' => '<rect x="4" y="2" width="12" height="16" rx="1.5" stroke="currentColor" stroke-width="1.6"/><rect x="6.5" y="6" width="7" height="1.4" rx="0.7" fill="currentColor"/><rect x="6.5" y="9.3" width="7" height="1.4" rx="0.7" fill="currentColor" opacity="0.6"/><rect x="6.5" y="12.6" width="4.5" height="1.4" rx="0.7" fill="currentColor" opacity="0.6"/>',
        'assistant' => '<rect x="3.5" y="6" width="13" height="10" rx="3" stroke="currentColor" stroke-width="1.6"/><line x1="10" y1="2.5" x2="10" y2="6" stroke="currentColor" stroke-width="1.6"/><circle cx="10" cy="2" r="1.1" fill="currentColor"/><circle cx="7.3" cy="11" r="1.3" fill="currentColor"/><circle cx="12.7" cy="11" r="1.3" fill="currentColor"/>',
        'settings' => '<circle cx="10" cy="10" r="3" stroke="currentColor" stroke-width="1.6"/><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.4" stroke-dasharray="2.2 2.6"/>',
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{{ $title ?? config('app.name') }}</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
        <style>[x-cloak]{display:none!important}</style>
    </head>
    <body class="h-screen overflow-hidden font-sans text-ink-900">
        <div x-data="{ collapsed: localStorage.getItem('eac.sidebar') === '1', mobile: false }"
             class="flex h-screen w-full overflow-hidden bg-ink-50">

            {{-- Voile de fond du tiroir mobile --}}
            <div x-show="mobile" x-transition.opacity @click="mobile = false" x-cloak
                 class="fixed inset-0 z-30 bg-ink-900/40 md:hidden"></div>

            {{-- Sidebar --}}
            <aside x-cloak
                   :class="{
                       'w-[70px]': collapsed, 'w-60': !collapsed,
                       'translate-x-0': mobile, '-translate-x-full': !mobile,
                   }"
                   class="fixed inset-y-0 left-0 z-40 flex flex-shrink-0 flex-col border-r border-ink-200 bg-white transition-all duration-200 ease-out md:static md:translate-x-0">

                {{-- Logo + bouton réduire --}}
                <div class="flex min-h-[64px] items-center gap-2.5 border-b border-ink-150 px-4">
                    <img src="/images/ecolepay-mark.png" alt="EcolePay" class="h-[30px] w-[30px] flex-shrink-0 object-contain">
                    <div x-show="!collapsed" class="min-w-0 flex-1 overflow-hidden whitespace-nowrap">
                        <div class="text-[13.5px] font-bold tracking-tight text-ink-900">Adoption Center</div>
                        <div class="text-[10.5px] font-semibold tracking-wide text-ink-600">ECOLEPAY</div>
                    </div>
                    <button @click="collapsed = !collapsed; localStorage.setItem('eac.sidebar', collapsed ? '1' : '0')"
                            class="ml-auto hidden h-7 w-7 flex-shrink-0 items-center justify-center rounded-md text-ink-500 hover:bg-ink-100 hover:text-ink-800 md:flex"
                            :title="collapsed ? 'Déplier le menu' : 'Réduire le menu'" aria-label="Réduire le menu">
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" class="transition-transform" :class="{ 'rotate-180': collapsed }">
                            <path d="M12.5 5l-5 5 5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>

                {{-- Navigation principale --}}
                <nav class="flex flex-1 flex-col gap-0.5 overflow-y-auto overflow-x-hidden p-2.5">
                    @foreach ($primary as $key)
                        @php $isActive = $key === $active; @endphp
                        <a href="{{ $linkOf($key) }}" @click="mobile = false"
                           :title="collapsed ? '{{ $modules[$key][0] }}' : null"
                           class="flex items-center gap-[11px] rounded-lg px-3 py-[9px] text-[13.5px] font-semibold whitespace-nowrap transition-colors
                                  {{ $isActive ? 'bg-brand-50 text-brand-700' : 'text-ink-800 hover:bg-ink-100' }}"
                           :class="{ 'justify-center px-0': collapsed }">
                            <span class="flex h-[18px] w-[18px] flex-shrink-0 items-center justify-center">
                                <svg width="16" height="16" viewBox="0 0 20 20" fill="none">{!! $icons[$key] !!}</svg>
                            </span>
                            <span x-show="!collapsed" class="overflow-hidden text-ellipsis">{{ $modules[$key][0] }}</span>
                        </a>
                    @endforeach
                </nav>

                {{-- Profil (bas de sidebar) --}}
                <div class="border-t border-ink-150 p-2.5">
                    <flux:dropdown position="top" align="start">
                        <button class="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-left hover:bg-ink-100"
                                :class="{ 'justify-center px-0': collapsed }">
                            <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-brand-50 text-[13px] font-bold text-brand-700">{{ $initials }}</span>
                            <span x-show="!collapsed" class="min-w-0 flex-1 overflow-hidden">
                                <span class="block truncate text-[13px] font-semibold text-ink-900">{{ $userName }}</span>
                                <span class="block truncate text-[11px] text-ink-600">{{ $userRole }}</span>
                            </span>
                        </button>
                        <flux:menu>
                            <flux:menu.item icon="user">Mon profil</flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item icon="arrow-right-start-on-rectangle" variant="danger" href="#">Déconnexion</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </aside>

            {{-- Zone principale --}}
            <div class="flex h-full min-w-0 flex-1 flex-col">
                <header class="flex h-16 flex-shrink-0 items-center justify-between gap-4 border-b border-ink-200 bg-white px-5 md:px-6">
                    <div class="flex min-w-0 items-center gap-3">
                        {{-- Ouvrir le tiroir (mobile) --}}
                        <button @click="mobile = true" class="flex h-9 w-9 items-center justify-center rounded-lg text-ink-700 hover:bg-ink-100 md:hidden" aria-label="Ouvrir le menu">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M3 5.5h14M3 10h14M3 14.5h14" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                        </button>
                        <div class="min-w-0">
                            <div class="truncate text-[17px] font-bold tracking-tight text-ink-900">{{ $header }}</div>
                            <div class="truncate text-[12.5px] text-ink-500">{{ $subheader }}</div>
                        </div>
                    </div>

                    <div class="flex flex-shrink-0 items-center gap-2 md:gap-2.5">
                        {{-- Recherche globale --}}
                        <div class="hidden w-52 items-center gap-2 rounded-lg border border-ink-300 bg-ink-50 px-3 py-2 focus-within:border-brand-600 focus-within:bg-white sm:flex lg:w-60">
                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-600"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="M17 17l-3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                            <input placeholder="Rechercher…" class="w-full border-none bg-transparent text-[13.5px] text-ink-900 outline-none placeholder:text-ink-500">
                        </div>

                        {{-- Notifications --}}
                        <flux:dropdown position="bottom" align="end">
                            <button class="relative flex h-9 w-9 items-center justify-center rounded-lg text-ink-700 hover:bg-ink-100" aria-label="Notifications">
                                <svg width="19" height="19" viewBox="0 0 20 20" fill="none"><path d="M5 8a5 5 0 0110 0c0 4 1.5 5 1.5 5h-13S5 12 5 8z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M8 16a2 2 0 004 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                            </button>
                            <div class="w-80 p-1">
                                <div class="flex items-center justify-between px-3 py-2">
                                    <span class="text-[13px] font-bold text-ink-900">Notifications</span>
                                    <a href="{{ $linkOf('notifications') }}" class="text-[12px] font-semibold text-brand-600 hover:underline">Tout voir</a>
                                </div>
                                <div class="flex flex-col items-center gap-1.5 px-4 py-8 text-center">
                                    <svg width="26" height="26" viewBox="0 0 20 20" fill="none" class="text-ink-300"><path d="M5 8a5 5 0 0110 0c0 4 1.5 5 1.5 5h-13S5 12 5 8z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                                    <span class="text-[12.5px] text-ink-500">Aucune notification pour le moment</span>
                                </div>
                            </div>
                        </flux:dropdown>

                        {{-- Avatar + menu --}}
                        <flux:dropdown position="bottom" align="end">
                            <button class="flex items-center gap-2 rounded-lg py-1 pl-1 pr-1.5 hover:bg-ink-100" aria-label="Menu utilisateur">
                                <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-brand-50 text-[13px] font-bold text-brand-700">{{ $initials }}</span>
                                <span class="hidden min-w-0 text-left md:block">
                                    <span class="block max-w-[130px] truncate text-[13px] font-semibold text-ink-900">{{ $userName }}</span>
                                    <span class="block max-w-[130px] truncate text-[11px] text-ink-500">{{ $userRole }}</span>
                                </span>
                                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" class="hidden flex-shrink-0 text-ink-500 md:block"><path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <flux:menu>
                                <flux:menu.item icon="user">Mon profil</flux:menu.item>
                                @foreach ($adminMenu as $key)
                                    <flux:menu.item icon="{{ $key === 'users' ? 'users' : 'clock' }}" href="{{ $linkOf($key) }}">{{ $modules[$key][2] }}</flux:menu.item>
                                @endforeach
                                <flux:menu.item icon="cog-6-tooth" href="{{ $linkOf('settings') }}">Paramètres</flux:menu.item>
                                <flux:menu.separator />
                                <flux:menu.item icon="arrow-right-start-on-rectangle" variant="danger" href="#">Déconnexion</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </header>

                <main class="flex-1 overflow-y-auto bg-ink-50 p-5 md:p-7">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
