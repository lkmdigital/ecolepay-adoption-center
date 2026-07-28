@props(['title' => '', 'subtitle' => ''])

<section {{ $attributes->merge(['class' => 'mb-5 rounded-[14px] border border-ink-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.03)] sm:p-6']) }}>
    @if ($title)
        <div class="mb-5">
            <h2 class="text-[15px] font-bold tracking-tight text-ink-900">{{ $title }}</h2>
            @if ($subtitle)
                <p class="mt-0.5 text-[12.5px] text-ink-500">{{ $subtitle }}</p>
            @endif
        </div>
    @endif
    {{ $slot }}
</section>
