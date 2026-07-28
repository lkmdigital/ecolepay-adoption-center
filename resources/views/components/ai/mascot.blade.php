@props(['size' => 40, 'variant' => 'color'])

@php
    // variant: 'color' (fond blanc, tête bleutée) ou 'white' (monochrome blanc, sur fond coloré).
    $body = $variant === 'white' ? '#FFFFFF' : '#FFFFFF';
    $bodyStroke = $variant === 'white' ? 'rgba(255,255,255,0.9)' : '#CFE0FF';
    $accent = $variant === 'white' ? '#FFFFFF' : '#7FA8FF';
    $limb = $variant === 'white' ? 'rgba(255,255,255,0.85)' : '#7FA8FF';
    $screen = $variant === 'white' ? '#2554C7' : '#12233B';
    $eyes = $variant === 'white' ? '#BFE0FF' : '#5AC8FA';
@endphp

<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 48 48" fill="none" {{ $attributes }}>
    {{-- Antenne --}}
    <line x1="24" y1="5.5" x2="24" y2="11" stroke="{{ $limb }}" stroke-width="2" stroke-linecap="round"/>
    <circle cx="24" cy="4" r="2.4" fill="{{ $accent }}"/>
    {{-- Bras --}}
    <rect x="3.5" y="20" width="6" height="12" rx="3" fill="{{ $limb }}"/>
    <rect x="38.5" y="20" width="6" height="12" rx="3" fill="{{ $limb }}"/>
    {{-- Tête / corps --}}
    <rect x="9" y="11" width="30" height="27" rx="10" fill="{{ $body }}" stroke="{{ $bodyStroke }}" stroke-width="1.5"/>
    {{-- Écran --}}
    <rect x="14" y="16.5" width="20" height="15" rx="6.5" fill="{{ $screen }}"/>
    {{-- Yeux souriants ^_^ --}}
    <path d="M18 26 q2.4 -3.4 4.8 0" stroke="{{ $eyes }}" stroke-width="2" stroke-linecap="round" fill="none"/>
    <path d="M25.2 26 q2.4 -3.4 4.8 0" stroke="{{ $eyes }}" stroke-width="2" stroke-linecap="round" fill="none"/>
    {{-- Petits pieds --}}
    <rect x="16" y="38.5" width="6" height="4" rx="2" fill="{{ $limb }}"/>
    <rect x="26" y="38.5" width="6" height="4" rx="2" fill="{{ $limb }}"/>
</svg>
