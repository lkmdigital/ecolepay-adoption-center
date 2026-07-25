@props([
    'label',
    'value',
    'sub' => null,
    'iconBg' => 'var(--color-brand-50)',
    'iconColor' => 'var(--color-brand-600)',
])

<div class="rounded-[14px] border border-ink-200 bg-white p-[22px] shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition-[box-shadow,transform] duration-150 hover:-translate-y-px hover:shadow-[0_10px_24px_rgba(15,23,42,0.09)]">
    <div class="flex items-start justify-between">
        <div class="flex h-[34px] w-[34px] flex-shrink-0 items-center justify-center rounded-[9px]"
             style="background: {{ $iconBg }}; color: {{ $iconColor }}">
            {{ $slot }}
        </div>
    </div>
    <div class="mt-4 text-[29px] font-bold tracking-tight text-ink-900">{{ $value }}</div>
    <div class="mt-1.5 flex items-end justify-between gap-2">
        <div class="text-[13px] font-semibold text-ink-700">{{ $label }}</div>
    </div>
    @if ($sub)
        <div class="mt-1 text-[11.5px] text-ink-500">{{ $sub }}</div>
    @endif
</div>
