@props(['section' => null])

<div class="flex items-center justify-between gap-3">
    <div>
        @if ($section)
            <button type="button" wire:click="resetSection('{{ $section }}')"
                    class="text-[12.5px] font-semibold text-ink-500 hover:text-ink-800">
                Réinitialiser cette section
            </button>
        @endif
    </div>
    <button type="button" wire:click="save" wire:loading.attr="disabled"
            class="inline-flex items-center gap-2 rounded-[10px] bg-brand-600 px-4 py-2.5 text-[13px] font-semibold text-white shadow-sm transition-colors hover:bg-brand-700 disabled:opacity-60">
        <svg wire:loading.remove wire:target="save" width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M4 10l4 4 8-9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <svg wire:loading wire:target="save" width="16" height="16" viewBox="0 0 20 20" fill="none" class="animate-spin"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" stroke-opacity="0.3"/><path d="M17 10a7 7 0 00-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        Enregistrer
    </button>
</div>
