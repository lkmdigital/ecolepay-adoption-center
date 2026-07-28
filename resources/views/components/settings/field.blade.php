@props(['label' => '', 'hint' => '', 'live' => false])

<div>
    <label class="mb-1.5 flex items-center text-[12.5px] font-semibold text-ink-800">
        {{ $label }}
        @if ($live)
            <span class="ml-2 inline-flex items-center gap-1 rounded-full bg-[#E7F6EE] px-2 py-0.5 text-[10px] font-bold text-[#0F7A44]">
                <span class="h-1.5 w-1.5 rounded-full bg-[#189B57]"></span>Actif
            </span>
        @endif
    </label>
    {{ $slot }}
    @if ($hint)
        <p class="mt-1.5 text-[11.5px] leading-snug text-ink-500">{{ $hint }}</p>
    @endif
</div>
