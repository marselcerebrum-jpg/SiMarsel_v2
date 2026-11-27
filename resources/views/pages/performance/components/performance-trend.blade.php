{{--
    Combined trend chart (FIX-06-FE-01): Omzet, Omzet Pembanding, Transaksi Berhasil and Total Transaksi.
    Series, groupings, values and times come from performanceTrend (resources/js/pages/performance/trend.js).
--}}
@php
    $group = 'inline-flex h-9 max-w-full shrink-0 items-center gap-0.5 overflow-x-auto rounded-lg border border-line bg-soft p-0.5';
    $pill = 'inline-flex h-8 shrink-0 items-center rounded-md px-3 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-40';
@endphp

<div x-data="performanceTrend" @performance-filter.window="applyFilter($event.detail)">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <h2 id="performance-trend-title" class="text-base font-semibold text-ink">Analisis Tren &amp; Performa</h2>

        {{-- Grouping; only those in the response `granularities` can be picked. The one shown is sent to the Dashboard export (FIX-14). --}}
        <div class="{{ $group }} self-start" role="radiogroup" aria-label="Pengelompokan">
            <template x-for="option in granularities" :key="option.value">
                <button
                    type="button"
                    role="radio"
                    class="{{ $pill }}"
                    :class="isGranularityActive(option.value) ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink'"
                    :aria-checked="isGranularityActive(option.value).toString()"
                    :disabled="isGranularityDisabled(option.value)"
                    @click="setGranularity(option.value)"
                    x-text="option.label"
                ></button>
            </template>
        </div>
    </div>

    {{-- Legend: keyboard-operable toggles; the line pattern and label tell the active and comparison lines apart. --}}
    <div class="mt-4 flex flex-wrap gap-2" role="group" aria-label="Legenda grafik tren">
        <template x-for="item in series" :key="item.key">
            <button
                type="button"
                class="inline-flex h-8 items-center gap-2 rounded-md border px-2.5 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                :class="isSeriesVisible(item.key) ? 'border-line bg-white text-ink' : 'border-dashed border-line bg-soft text-label line-through'"
                :aria-pressed="isSeriesVisible(item.key).toString()"
                @click="toggleSeries(item.key)"
            >
                @include('pages.performance.components.trend-series-swatch', ['pattern' => 'item.pattern', 'color' => 'item.color'])
                <span x-text="item.label"></span>
            </button>
        </template>
    </div>

    {{-- Chart: hover, tap, or focus and use the arrow keys to open a point. --}}
    <div class="relative mt-4">
        <div
            class="relative h-80 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary sm:h-[26rem]"
            :tabindex="hasPoints ? 0 : -1"
            role="group"
            :aria-label="chartLabel"
            @focus="hasPoints && pointIndex === null && firstPoint()"
            @blur="closePoint()"
            @keydown.arrow-right.prevent="movePoint(1)"
            @keydown.arrow-left.prevent="movePoint(-1)"
            @keydown.home.prevent="firstPoint()"
            @keydown.end.prevent="lastPoint()"
            @keydown.escape="closePoint()"
        >
            <canvas x-ref="canvas" aria-hidden="true" :class="trendLoading && hasPoints && 'opacity-50'"></canvas>

            <div x-show="trendLoading && !hasPoints" class="absolute inset-0 animate-pulse rounded-lg bg-soft" aria-hidden="true"></div>

            <div x-show="!trendLoading && !hasPoints" x-cloak class="absolute inset-0 grid place-items-center text-center">
                <p class="text-sm text-label" :role="trendError ? 'alert' : null" x-text="trendPlaceholder">Grafik akan tampil setelah data tersedia.</p>
            </div>

            {{-- Point detail --}}
            <div
                x-show="detail"
                x-cloak
                class="pointer-events-none absolute top-2 z-10 w-64 max-w-full rounded-lg bg-navy p-3 text-xs text-slate-200 shadow-lg"
                :style="`left: ${detailLeft}px`"
                aria-live="polite"
            >
                <template x-if="detail">
                    <div>
                        <dl class="space-y-1.5">
                            <div>
                                <dt class="text-slate-400">Waktu aktif</dt>
                                <dd class="font-semibold text-white" x-text="detail.active"></dd>
                            </div>
                            <div>
                                <dt class="text-slate-400">Waktu pembanding</dt>
                                <dd class="font-semibold text-white" x-text="detail.comparison"></dd>
                            </div>
                        </dl>

                        <ul x-show="detail.rows.length" class="mt-2 space-y-1 border-t border-navy-line pt-2">
                            <template x-for="row in detail.rows" :key="row.key">
                                <li class="flex items-center justify-between gap-3">
                                    <span class="inline-flex items-center gap-2">
                                        @include('pages.performance.components.trend-series-swatch', ['pattern' => 'row.pattern', 'color' => 'row.color'])
                                        <span x-text="row.label"></span>
                                    </span>
                                    <span class="performance-value font-semibold text-white" x-text="row.value"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
