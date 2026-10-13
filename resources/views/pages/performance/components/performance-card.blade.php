@php
    $key = Js::from($metric['key']);
    $primary = $size === 'primary';
@endphp

<article
    @class([
        'performance-rise group relative isolate flex flex-col min-h-40 overflow-hidden rounded-2xl border border-white/60 py-5 pr-6 pl-7 bg-surface shadow-[0_16px_40px_rgba(30,41,70,0.22)] transition duration-300 hover:-translate-y-1 hover:shadow-[0_24px_56px_rgba(30,41,70,0.3)] dark:border-white/25 dark:bg-surface-dim',
        'sm:min-h-44' => $primary,
    ])
    style="--delay: {{ $delay }}ms"
    :class="loading && summary && 'opacity-70'"
>
    {{-- Soft brand glow in the corner, brighter on hover --}}
    <span class="pointer-events-none absolute -top-12 -right-12 -z-10 size-36 rounded-full bg-radial from-brand-indigo/10 to-transparent to-70% transition-opacity duration-500 group-hover:opacity-100 sm:opacity-70" aria-hidden="true"></span>

    {{-- Left accent, stretches on hover --}}
    <span class="absolute inset-y-6 left-0 w-1 rounded-r-full bg-linear-to-b from-brand-indigo to-brand-teal transition-all duration-500 group-hover:inset-y-3" aria-hidden="true"></span>

    <div class="flex items-start justify-between gap-3">
        <h3 @class(['font-bold tracking-wider text-label uppercase', 'text-[13px]' => $primary, 'text-xs' => ! $primary])>{{ $metric['label'] }}</h3>
        <span
            x-show="summary && trend({{ $key }}).short"
            x-cloak
            x-transition.scale.origin.right
            class="shrink-0 rounded-lg px-2 py-0.5 text-xs font-bold whitespace-nowrap"
            :class="trend({{ $key }}).tone"
            x-text="trend({{ $key }}).short"
        ></span>
    </div>

    <div x-show="skeleton" class="mt-1.5 space-y-1.5" aria-hidden="true">
        <span @class(['performance-shimmer block rounded-lg', 'mt-1 h-10 w-40' => $primary, 'mt-1 h-9 w-28' => ! $primary])></span>
        <span class="performance-shimmer block h-4 w-36 rounded-md"></span>
    </div>

    <div x-show="!skeleton" x-cloak>
        <p
            @class(['performance-value font-extrabold tracking-tight text-ink', 'mt-1.5 text-[2rem] leading-tight' => $primary, 'mt-1.5 text-[1.75rem] leading-tight' => ! $primary])
            x-text="value({{ $key }}, @js($metric['format']), @js($metric['compact'] ?? false))"
        >—</p>
        <p class="mt-1 text-[13px] text-muted" x-text="detail({{ $key }}, @js($metric['hint']))">{{ $metric['hint'] }}</p>
    </div>

    @if ($metric['progress'] ?? false)
        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-soft" role="presentation">
            <div class="h-full rounded-full bg-linear-to-r from-brand-indigo to-brand-teal" :style="{ width: progress({{ $key }}) }"></div>
        </div>
    @endif

    <p x-show="summary && !isUnavailable({{ $key }})" x-cloak x-transition.opacity.duration.500ms class="mt-auto pt-3 text-xs leading-snug">
        <span class="font-semibold" :class="trend({{ $key }}).textTone" x-text="trend({{ $key }}).sentence"></span>
        <span x-show="trend({{ $key }}).short && previousPeriodLabel" class="text-label" x-text="`(${previousPeriodLabel})`"></span>
    </p>
</article>
