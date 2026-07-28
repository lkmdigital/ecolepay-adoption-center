@props(['model' => '', 'label' => '', 'desc' => ''])

<label class="flex cursor-pointer items-center justify-between gap-4 py-3.5">
    <span class="min-w-0">
        <span class="block text-[13.5px] font-semibold text-ink-900">{{ $label }}</span>
        @if ($desc)
            <span class="mt-0.5 block text-[12px] text-ink-500">{{ $desc }}</span>
        @endif
    </span>
    <span class="relative flex-shrink-0">
        <input type="checkbox" wire:model="{{ $model }}" class="peer sr-only">
        <span class="block h-6 w-11 rounded-full bg-ink-300 transition-colors peer-checked:bg-brand-600"></span>
        <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
    </span>
</label>
