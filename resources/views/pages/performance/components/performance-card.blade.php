@php
    $key = Js::from($metric['key']);
    $primary = $size === 'primary';
@endphp

<article
    class="flex min-h-36 flex-col rounded-panel border border-line bg-surface p-5 shadow-sm"
    :class="loading && summary && 'opacity-70'"
>
    <h3 class="text-sm font-semibold text-label">{{ $metric['label'] }}</h3>

    <div x-show="skeleton" class="mt-2 space-y-2" aria-hidden="true">
        <span @class(['block animate-pulse rounded-md bg-soft', 'h-9 w-40' => $primary, 'h-8 w-28' => ! $primary])></span>
        <span class="block h-4 w-36 animate-pulse rounded-md bg-soft"></span>
    </div>

    <div x-show="!skeleton" x-cloak>
        <p
            @class(['performance-value mt-1.5 leading-tight font-bold tracking-tight text-ink', 'text-[1.75rem]' => $primary, 'text-2xl' => ! $primary])
            x-text="value({{ $key }}, @js($metric['format']), @js($metric['compact'] ?? false))"
        >—</p>
        <p class="mt-1 text-[13px] text-muted" x-text="detail({{ $key }}, @js($metric['hint']))">{{ $metric['hint'] }}</p>
    </div>

    @if ($metric['progress'] ?? false)
        <div class="mt-auto pt-3">
            <div class="h-2 overflow-hidden rounded-full bg-soft" role="presentation">
                <div class="h-full rounded-full bg-primary" :style="{ width: progress({{ $key }}) }"></div>
            </div>
        </div>
    @endif
</article>
