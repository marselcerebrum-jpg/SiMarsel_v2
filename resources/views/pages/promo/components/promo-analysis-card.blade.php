{{-- One KPI card. Variable: $metric (key, label, format). --}}
@php
    $key = Js::from($metric['key']);
    $format = Js::from($metric['format']);
    $shimmer = 'relative block h-8 w-32 overflow-hidden rounded-md bg-line/70';
    $sheen = 'absolute inset-0 -translate-x-full animate-shimmer bg-linear-to-r from-transparent via-white/90 to-transparent motion-reduce:hidden';
@endphp

<article class="flex min-h-28 flex-col rounded-panel border border-line bg-surface p-5 shadow-sm">
    <div class="flex items-start justify-between gap-2">
        <h3 class="text-sm font-semibold text-label">{{ $metric['label'] }}</h3>
        <span
            x-show="!loading && comparison({{ $key }})"
            x-cloak
            class="inline-flex shrink-0 items-center rounded-md px-2 py-0.5 text-xs font-semibold whitespace-nowrap"
            :class="comparison({{ $key }})?.tone"
            x-text="comparison({{ $key }})?.short"
        ></span>
    </div>

    <div x-show="loading" class="mt-2" aria-hidden="true">
        <span class="{{ $shimmer }}"><span class="{{ $sheen }}"></span></span>
    </div>

    <p x-show="!loading" x-cloak class="mt-1.5 text-2xl leading-tight font-bold tracking-tight text-ink" x-text="value({{ $key }}, {{ $format }})">—</p>
</article>
