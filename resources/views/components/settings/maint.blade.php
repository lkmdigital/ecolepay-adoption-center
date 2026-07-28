@props(['title' => '', 'desc' => '', 'action' => '', 'cta' => 'Exécuter'])

<div class="flex items-center justify-between gap-4 rounded-[12px] border border-ink-200 p-4">
    <div class="min-w-0">
        <div class="text-[13.5px] font-semibold text-ink-900">{{ $title }}</div>
        <div class="mt-0.5 text-[12px] text-ink-500">{{ $desc }}</div>
    </div>
    <button type="button" wire:click="{{ $action }}" wire:loading.attr="disabled" wire:target="{{ $action }}"
            class="inline-flex flex-shrink-0 items-center gap-2 rounded-[9px] border border-ink-300 bg-white px-3.5 py-2 text-[12.5px] font-semibold text-ink-800 transition-colors hover:bg-ink-100 disabled:opacity-60">
        <svg wire:loading wire:target="{{ $action }}" width="14" height="14" viewBox="0 0 20 20" fill="none" class="animate-spin"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" stroke-opacity="0.3"/><path d="M17 10a7 7 0 00-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        {{ $cta }}
    </button>
</div>
