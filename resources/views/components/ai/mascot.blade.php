@props(['size' => 40, 'variant' => 'color'])

@php
    // Si l'image officielle de KATIA est déposée dans public/images/katia.png,
    // on l'utilise partout ; sinon on retombe sur une mascotte SVG (cute robot).
    $hasImage = is_file(public_path('images/katia.png'));

    // variant: 'color' (fond blanc, tête bleutée) ou 'white' (monochrome, sur fond coloré).
    $body = '#FFFFFF';
    $bodyStroke = $variant === 'white' ? 'rgba(255,255,255,0.85)' : '#D5E3FF';
    $ear = $variant === 'white' ? 'rgba(255,255,255,0.9)' : '#7FA8FF';
    $screen = $variant === 'white' ? '#2554C7' : '#14263C';
    $eyes = $variant === 'white' ? '#DCEBFF' : '#7FD6FF';
    $belly = $variant === 'white' ? 'rgba(255,255,255,0.9)' : '#7FA8FF';
    $glow = $variant === 'white' ? '#2554C7' : '#BFE6FF';
@endphp

@if ($hasImage)
    <img src="{{ asset('images/katia.png') }}" alt="KATIA"
         {{ $attributes->merge(['class' => 'object-contain']) }}
         style="width: {{ $size }}px; height: {{ $size }}px;">
@else
    <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 48 48" fill="none" {{ $attributes }}>
        {{-- Oreilles / bras --}}
        <ellipse cx="7" cy="22" rx="3.6" ry="6" fill="{{ $ear }}" transform="rotate(-18 7 22)"/>
        <ellipse cx="41" cy="22" rx="3.6" ry="6" fill="{{ $ear }}" transform="rotate(18 41 22)"/>
        {{-- Corps bas + ventre lumineux --}}
        <rect x="15" y="31" width="18" height="12" rx="6" fill="{{ $belly }}"/>
        <circle cx="24" cy="37" r="3" fill="{{ $glow }}"/>
        {{-- Tête --}}
        <rect x="9.5" y="9" width="29" height="25" rx="11" fill="{{ $body }}" stroke="{{ $bodyStroke }}" stroke-width="1.4"/>
        {{-- Écran --}}
        <rect x="14" y="14.5" width="20" height="15" rx="7" fill="{{ $screen }}"/>
        {{-- Yeux souriants ◡ ◡ --}}
        <path d="M18.4 21 q2.3 3.2 4.6 0" stroke="{{ $eyes }}" stroke-width="2.1" stroke-linecap="round" fill="none"/>
        <path d="M24.9 21 q2.3 3.2 4.6 0" stroke="{{ $eyes }}" stroke-width="2.1" stroke-linecap="round" fill="none"/>
    </svg>
@endif
