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
        'notifications' => ['Notifications', 'notifications.index', 'Notifications & alertes', 'Anomalies et alertes'],
        'assistant' => ['Assistant IA', 'assistant.index', 'Assistant IA', 'Copilote décisionnel'],
        'users' => ['Utilisateurs', 'users.index', 'Utilisateurs & rôles', 'Gestion des accès'],
        'activity' => ["Journal d'activité", 'activity.index', "Journal d'activité", 'Audit des actions'],
        'settings' => ['Paramètres', 'settings.index', 'Paramètres', 'Configuration de la plateforme'],
    ];

    // Module actif déduit du nom de la route courante (dashboard.index → dashboard).
    $routeName = RouteFacade::currentRouteName() ?? '';
    $active ??= explode('.', $routeName)[0] ?: 'dashboard';
    if (! isset($modules[$active])) {
        $active = 'dashboard';
    }
    $header ??= $modules[$active][2];
    $subheader ??= $modules[$active][3];

    $nav = collect($modules)->map(fn ($m, $key) => [
        'key' => $key,
        'label' => $m[0],
        'route' => RouteFacade::has($m[1]) ? route($m[1]) : '#',
    ])->values()->all();
    $icons = [
        'dashboard' => '<rect x="3" y="3" width="6" height="6" rx="1.5" fill="currentColor"/><rect x="11" y="3" width="6" height="6" rx="1.5" fill="currentColor" opacity="0.45"/><rect x="3" y="11" width="6" height="6" rx="1.5" fill="currentColor" opacity="0.45"/><rect x="11" y="11" width="6" height="6" rx="1.5" fill="currentColor"/>',
        'schools' => '<polygon points="10,2 17,7 3,7" fill="currentColor"/><rect x="4" y="7.5" width="12" height="9.5" rx="1" stroke="currentColor" stroke-width="1.6" fill="none"/><rect x="9" y="12" width="2" height="5" fill="currentColor"/>',
        'parents' => '<circle cx="7.2" cy="6.5" r="3" stroke="currentColor" stroke-width="1.6"/><circle cx="14" cy="8" r="2.2" stroke="currentColor" stroke-width="1.6" opacity="0.6"/><path d="M2.5 17c0-3 2.1-5 4.7-5s4.7 2 4.7 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M12.3 17c0-2.2 1.4-3.8 3.2-3.8s3.2 1.6 3.2 3.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" opacity="0.6"/>',
        'campaigns' => '<rect x="2.5" y="4" width="15" height="10" rx="3" stroke="currentColor" stroke-width="1.6"/><polygon points="6,14 6,18 10,14" fill="currentColor"/><circle cx="7" cy="9" r="1" fill="currentColor"/><circle cx="10" cy="9" r="1" fill="currentColor"/><circle cx="13" cy="9" r="1" fill="currentColor"/>',
        'analytics' => '<rect x="3" y="11" width="3.5" height="6" rx="1" fill="currentColor" opacity="0.5"/><rect x="8.3" y="6" width="3.5" height="11" rx="1" fill="currentColor"/><rect x="13.6" y="2.5" width="3.5" height="14.5" rx="1" fill="currentColor" opacity="0.75"/>',
        'reports' => '<rect x="4" y="2" width="12" height="16" rx="1.5" stroke="currentColor" stroke-width="1.6"/><rect x="6.5" y="6" width="7" height="1.4" rx="0.7" fill="currentColor"/><rect x="6.5" y="9.3" width="7" height="1.4" rx="0.7" fill="currentColor" opacity="0.6"/><rect x="6.5" y="12.6" width="4.5" height="1.4" rx="0.7" fill="currentColor" opacity="0.6"/>',
        'notifications' => '<path d="M5 8a5 5 0 0110 0c0 4 1.5 5 1.5 5h-13S5 12 5 8z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M8 16a2 2 0 004 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
        'assistant' => '<rect x="3.5" y="6" width="13" height="10" rx="3" stroke="currentColor" stroke-width="1.6"/><line x1="10" y1="2.5" x2="10" y2="6" stroke="currentColor" stroke-width="1.6"/><circle cx="10" cy="2" r="1.1" fill="currentColor"/><circle cx="7.3" cy="11" r="1.3" fill="currentColor"/><circle cx="12.7" cy="11" r="1.3" fill="currentColor"/>',
        'users' => '<path d="M10 2.5l6 2.3v4.3c0 4-2.6 6.8-6 8.4-3.4-1.6-6-4.4-6-8.4V4.8z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="10" cy="8.6" r="1.9" stroke="currentColor" stroke-width="1.4"/><path d="M6.8 13.2c.6-1.4 1.8-2.1 3.2-2.1s2.6.7 3.2 2.1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>',
        'activity' => '<circle cx="10" cy="10" r="7.2" stroke="currentColor" stroke-width="1.6"/><path d="M10 5.8v4.4l3 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
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
    </head>
    <body class="h-screen overflow-hidden font-sans text-ink-900">
        <div class="flex h-screen w-full overflow-hidden bg-ink-50">

            {{-- Sidebar --}}
            <aside class="flex w-60 flex-shrink-0 flex-col border-r border-ink-200 bg-white">
                <div class="flex min-h-[64px] items-center gap-2.5 border-b border-ink-150 px-4">
                    <img src="/images/ecolepay-mark.png" alt="EcolePay" class="h-[30px] w-[30px] flex-shrink-0 object-contain">
                    <div class="overflow-hidden whitespace-nowrap">
                        <div class="text-[13.5px] font-bold tracking-tight text-ink-900">Adoption Center</div>
                        <div class="text-[10.5px] font-semibold tracking-wide text-ink-600">ECOLEPAY</div>
                    </div>
                </div>

                <nav class="flex flex-1 flex-col gap-0.5 overflow-y-auto p-2.5">
                    @foreach ($nav as $item)
                        @php $isActive = $item['key'] === $active; @endphp
                        <a href="{{ $item['route'] }}"
                           class="flex items-center gap-[11px] rounded-lg px-3 py-[9px] text-[13.5px] font-semibold whitespace-nowrap transition-colors
                                  {{ $isActive ? 'bg-brand-50 text-brand-700' : 'text-ink-800 hover:bg-ink-100' }}">
                            <span class="flex h-[18px] w-[18px] flex-shrink-0 items-center justify-center">
                                <svg width="16" height="16" viewBox="0 0 20 20" fill="none">{!! $icons[$item['key']] !!}</svg>
                            </span>
                            <span class="overflow-hidden text-ellipsis">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </nav>

                <div class="flex items-center gap-2.5 border-t border-ink-150 px-3 py-3">
                    <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-brand-50 text-[13px] font-bold text-brand-700">
                        {{ \Illuminate\Support\Str::of(auth()->user()?->name ?? 'EAC')->explode(' ')->map(fn ($p) => \Illuminate\Support\Str::substr($p, 0, 1))->take(2)->implode('') }}
                    </div>
                    <div class="min-w-0 flex-1 overflow-hidden">
                        <div class="truncate text-[13px] font-semibold text-ink-900">{{ auth()->user()?->name ?? 'Utilisateur EAC' }}</div>
                        <div class="truncate text-[11px] text-ink-600">{{ auth()->user()?->job_title ?? 'Direction' }}</div>
                    </div>
                </div>
            </aside>

            {{-- Zone principale --}}
            <div class="flex h-full min-w-0 flex-1 flex-col">
                <header class="flex h-16 flex-shrink-0 items-center justify-between gap-5 border-b border-ink-200 bg-white px-6">
                    <div class="min-w-0">
                        <div class="truncate text-[17px] font-bold tracking-tight text-ink-900">{{ $header ?? 'Dashboard exécutif' }}</div>
                        <div class="truncate text-[12.5px] text-ink-500">{{ $subheader ?? "Vue d'ensemble de l'adoption d'EcolePay" }}</div>
                    </div>
                    <div class="flex flex-shrink-0 items-center gap-2.5">
                        <div class="flex w-60 items-center gap-2 rounded-lg border border-ink-300 bg-ink-50 px-3 py-2 focus-within:border-brand-600 focus-within:bg-white">
                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" class="flex-shrink-0 text-ink-600"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="M17 17l-3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                            <input placeholder="Rechercher…" class="w-full border-none bg-transparent text-[13.5px] text-ink-900 outline-none placeholder:text-ink-500">
                        </div>
                    </div>
                </header>

                <main class="flex-1 overflow-y-auto bg-ink-50 p-7">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
