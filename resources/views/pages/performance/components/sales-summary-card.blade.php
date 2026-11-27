{{--
    One Ringkasan Sales metric: active value, then the comparison and its change right under it.
    Variables: $metric (key, label), $framed (card border for the supporting row).
--}}
@php
    $card = 'salesCards.'.$metric['key'];
@endphp

<article
    data-sales-summary-metric="{{ $metric['key'] }}"
    @class([
        'flex min-w-0 flex-col',
        'rounded-panel border border-line bg-surface p-4 shadow-sm' => $framed ?? false,
        'rounded-lg bg-soft p-3' => ! ($framed ?? false),
    ])
>
    <h4 class="text-sm font-semibold text-label">{{ $metric['label'] }}</h4>

    <div x-show="salesSummaryLoading" class="mt-2 space-y-2">
        @include('pages.performance.components.loading-shimmer', ['class' => 'h-8 w-32 max-w-full rounded-md'])
        @include('pages.performance.components.loading-shimmer', ['class' => 'h-4 w-40 max-w-full rounded-md'])
    </div>

    <div x-show="!salesSummaryLoading" x-cloak>
        <p class="performance-value mt-1.5 text-2xl leading-tight font-bold tracking-tight break-words text-ink" x-text="{{ $card }}.value">—</p>

        <dl class="mt-1.5 space-y-0.5 text-[13px]">
            <div class="flex flex-wrap gap-x-1">
                <dt class="text-muted">Pembanding:</dt>
                <dd class="performance-value font-semibold text-ink" x-text="{{ $card }}.comparison"></dd>
            </div>
            <div x-show="{{ $card }}.change.text" class="flex flex-wrap gap-x-1">
                <dt class="text-muted">Perubahan:</dt>
                <dd class="performance-value font-semibold text-ink">
                    <span x-show="{{ $card }}.change.symbol" aria-hidden="true" x-text="{{ $card }}.change.symbol"></span>
                    <span x-show="{{ $card }}.change.label" class="sr-only" x-text="{{ $card }}.change.label"></span>
                    <span x-text="{{ $card }}.change.text"></span>
                </dd>
            </div>
        </dl>

        <p x-show="{{ $card }}.note" x-cloak class="mt-2 inline-flex rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800" x-text="{{ $card }}.note"></p>
        <p x-show="{{ $card }}.detail" x-cloak class="mt-1.5 text-xs text-muted" x-text="{{ $card }}.detail"></p>
    </div>
</article>
