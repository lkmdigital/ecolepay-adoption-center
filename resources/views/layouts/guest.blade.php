<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{{ $title ?? 'Adoption Center' }}</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
        <style>[x-cloak]{display:none!important}</style>
    </head>
    <body class="min-h-screen bg-ink-50 font-sans text-ink-900">
        <div class="flex min-h-screen items-center justify-center px-4 py-10">
            <div class="w-full max-w-[400px]">
                <div class="mb-7 flex flex-col items-center text-center">
                    <img src="/images/ecolepay-mark.png" alt="EcolePay" class="h-11 w-11 object-contain">
                    <div class="mt-3 text-[18px] font-bold tracking-tight text-ink-900">Adoption Center</div>
                    <div class="text-[11.5px] font-semibold tracking-wide text-ink-500">ECOLEPAY · LKM DIGITAL</div>
                </div>

                {{ $slot }}

                <p class="mt-6 text-center text-[11.5px] text-ink-400">Plateforme interne — accès réservé aux collaborateurs LKM Digital.</p>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
