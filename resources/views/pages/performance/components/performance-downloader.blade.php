{{--
    Downloader chart and Lihat Rincian table (FIX-08-FE-01).
    Series, groupings, values and periods come from performanceDownloader (resources/js/pages/performance/downloader.js).
--}}
@php
    $group = 'inline-flex h-9 max-w-full shrink-0 items-center gap-0.5 overflow-x-auto rounded-lg border border-line bg-soft p-0.5';
    $pill = 'inline-flex h-8 shrink-0 items-center rounded-md px-3 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-primary';
    $columns = ['Downloader', 'Periode Pembanding', 'Downloader Pembanding', 'Perubahan'];
@endphp

<div x-data="performanceDownloader" @performance-filter.window="applyDownloaderFilter($event.detail)">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <h2 id="dashboard-downloader-title" class="text-base font-semibold text-ink">Downloader</h2>

        <div class="{{ $group }} self-start" role="radiogroup" aria-label="Pengelompokan Downloader">
            <template x-for="option in downloaderGranularities" :key="option.value">
                <button
                    type="button"
                    role="radio"
                    class="{{ $pill }}"
                    :class="isDownloaderGranularity(option.value) ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink'"
                    :aria-checked="isDownloaderGranularity(option.value).toString()"
                    @click="setDownloaderGranularity(option.value)"
                    x-text="option.label"
                ></button>
            </template>
        </div>
    </div>

    {{-- Legend: the line pattern and label tell the active and comparison lines apart. --}}
    <ul class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-xs font-semibold text-ink" aria-label="Legenda grafik Downloader">
        <template x-for="item in downloaderSeries" :key="item.key">
            <li class="inline-flex items-center gap-2">
                @include('pages.performance.components.trend-series-swatch', ['pattern' => 'item.pattern', 'color' => 'item.color'])
                <span x-text="item.label"></span>
            </li>
        </template>
    </ul>

    <p x-show="hasDownloaderPoints" x-cloak class="mt-3 text-xs text-muted" x-text="downloaderCompleteness"></p>

    {{-- Chart: hover or tap to open a point; tapping elsewhere closes it. --}}
    <div
        class="relative mt-3 h-72 rounded-lg sm:h-80"
        role="group"
        :aria-label="downloaderChartLabel"
        @click.outside="closeDownloaderPoint()"
    >
        <canvas x-ref="downloaderCanvas" aria-hidden="true" :class="downloaderLoading && hasDownloaderPoints && 'opacity-50'"></canvas>

        <div x-show="downloaderLoading && !hasDownloaderPoints" class="absolute inset-0 animate-pulse rounded-lg bg-soft" aria-hidden="true"></div>

        <div x-show="!downloaderLoading && !hasDownloaderPoints" x-cloak class="absolute inset-0 grid place-items-center text-center">
            <p class="text-sm text-label" :role="downloaderError ? 'alert' : null" x-text="downloaderPlaceholder">Grafik akan tampil setelah data tersedia.</p>
        </div>

        {{-- Point detail: same labels, order and values as the table row. --}}
        <div
            x-show="downloaderDetail"
            x-cloak
            class="pointer-events-none absolute top-2 z-10 w-64 max-w-full rounded-lg bg-navy p-3 text-xs text-slate-200 shadow-lg"
            :style="`left: ${downloaderDetailLeft}px`"
            aria-live="polite"
        >
            <template x-if="downloaderDetail">
                <dl class="space-y-1.5">
                    <div>
                        <dt class="text-slate-400">Periode Aktif</dt>
                        <dd class="font-semibold text-white">
                            <span x-text="downloaderDetail.activePeriod"></span>
                            <span x-show="downloaderDetail.provisional" class="font-normal text-slate-300" x-text="`(${downloaderDetail.provisional})`"></span>
                        </dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-slate-400">Downloader</dt>
                        <dd class="performance-value text-right font-semibold text-white" x-text="downloaderDetail.downloader"></dd>
                    </div>
                    <div class="border-t border-navy-line pt-1.5">
                        <dt class="text-slate-400">Periode Pembanding</dt>
                        <dd class="font-semibold text-white" x-text="downloaderDetail.comparisonPeriod"></dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-slate-400">Downloader Pembanding</dt>
                        <dd class="performance-value text-right font-semibold text-white" x-text="downloaderDetail.comparisonDownloader"></dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 border-t border-navy-line pt-1.5">
                        <dt class="text-slate-400">Perubahan</dt>
                        <dd class="performance-value text-right font-semibold text-white" x-text="downloaderDetail.change"></dd>
                    </div>
                </dl>
            </template>
        </div>
    </div>

    <div x-show="hasDownloaderPoints" x-cloak class="mt-4">
        <button
            type="button"
            class="inline-flex h-9 items-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            aria-controls="downloader-details"
            :aria-expanded="detailsOpen.toString()"
            @click="toggleDetails()"
        >
            <span>Lihat Rincian</span>
            <svg class="size-4 transition-transform" :class="detailsOpen && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
            </svg>
        </button>

        {{-- The period column stays pinned while the other columns scroll on phones. --}}
        <div
            id="downloader-details"
            x-show="detailsOpen"
            x-cloak
            class="mt-3 overflow-x-auto rounded-lg border border-line focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            role="region"
            aria-label="Rincian Downloader"
            tabindex="0"
        >
            <table class="w-full min-w-[760px] text-left text-sm">
                <caption class="sr-only">Rincian Downloader per periode dan pembandingnya</caption>
                <thead class="bg-soft text-xs font-semibold text-label">
                    <tr>
                        <th scope="col" class="sticky left-0 z-10 w-40 bg-soft px-4 py-3">Periode Aktif</th>
                        @foreach ($columns as $column)
                            <th scope="col" class="px-4 py-3">{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <template x-for="row in downloaderRows" :key="row.key">
                        <tr>
                            <th scope="row" class="sticky left-0 z-10 w-40 bg-surface px-4 py-3 align-top font-semibold text-ink">
                                <span class="block" x-text="row.activePeriod"></span>
                                <span x-show="row.provisional" class="mt-0.5 block text-xs font-normal text-muted" x-text="row.provisional"></span>
                            </th>
                            <td class="performance-value px-4 py-3 align-top font-semibold text-ink" x-text="row.downloader"></td>
                            <td class="px-4 py-3 align-top text-muted" x-text="row.comparisonPeriod"></td>
                            <td class="performance-value px-4 py-3 align-top text-muted" x-text="row.comparisonDownloader"></td>
                            <td class="performance-value px-4 py-3 align-top text-muted" x-text="row.change"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>
