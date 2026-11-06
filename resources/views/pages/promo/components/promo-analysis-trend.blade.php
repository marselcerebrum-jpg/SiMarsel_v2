{{-- Promo transactions and revenue per daily, weekly, or monthly bucket, with total sales as the overlay. Buckets and totals come from the backend. --}}
@php
    $periods = [
        ['value' => 'daily', 'label' => 'Harian'],
        ['value' => 'weekly', 'label' => 'Mingguan'],
        ['value' => 'monthly', 'label' => 'Bulanan'],
    ];
    $pill = 'inline-flex h-8 shrink-0 items-center rounded-md px-3 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-40';
@endphp

<section
    x-data="promoAnalysisTrend"
    @promo-analysis-filter.window="load($event.detail)"
    class="mt-5 border-t border-line pt-5"
    aria-labelledby="promo-trend-title"
>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h3 id="promo-trend-title" class="text-sm font-semibold text-ink">Tren Penggunaan Promo</h3>

        <div class="inline-flex h-9 shrink-0 items-center gap-0.5 rounded-lg border border-line bg-soft p-0.5" role="radiogroup" aria-label="Periode Grafik">
            @foreach ($periods as $option)
                <button
                    type="button"
                    role="radio"
                    class="{{ $pill }}"
                    :class="activePeriod === @js($option['value']) ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink'"
                    :aria-checked="(activePeriod === @js($option['value'])).toString()"
                    :disabled="isPeriodDisabled(@js($option['value']))"
                    :title="isPeriodDisabled(@js($option['value'])) ? 'Rentang tanggal lebih dari 366 hari hanya dapat ditampilkan bulanan.' : null"
                    @click="setPeriod(@js($option['value']))"
                >{{ $option['label'] }}</button>
            @endforeach
        </div>
    </div>

    <div class="relative mt-4 h-80" :aria-busy="loading.toString()">
        <canvas x-ref="canvas" role="img" :aria-label="chartLabel" :class="loading && hasPoints && 'opacity-50'"></canvas>

        <div x-show="loading && !hasPoints" class="absolute inset-0 animate-pulse rounded-lg bg-soft" aria-hidden="true"></div>

        <div x-show="!loading && !hasPoints" x-cloak class="absolute inset-0 grid place-items-center rounded-lg border border-dashed border-line px-4 text-center">
            <div :role="loadError ? 'alert' : 'status'">
                <p class="text-sm font-medium" :class="loadError ? 'text-red-600' : 'text-ink'" x-text="placeholder"></p>
                <button x-show="loadError" type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="retry()">Coba Lagi</button>
            </div>
        </div>
    </div>
</section>
