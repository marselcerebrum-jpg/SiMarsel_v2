@php
    $group = 'inline-flex h-9 max-w-full shrink-0 items-center gap-0.5 overflow-x-auto rounded-lg border border-line bg-soft p-0.5';
    $pill = 'inline-flex h-8 shrink-0 items-center rounded-md px-3 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-40';
    $pillActive = "'bg-white text-ink shadow-sm'";
    $pillIdle = "'text-muted hover:text-ink'";
@endphp

<div
    x-data="performanceTrend(@js($trend['metrics']))"
    @performance-filter.window="applyFilter($event.detail)"
>
    <div class="flex flex-col gap-4 2xl:flex-row 2xl:items-start 2xl:justify-between">
        <div class="2xl:max-w-56">
            <h2 id="performance-trend-title" class="text-base font-semibold text-ink">Trend &amp; Performance Analysis</h2>
            <p class="mt-1 text-sm text-muted">Pilih metrik, periode data, dan tipe tampilan.</p>
        </div>

        <div class="flex min-w-0 flex-col gap-3">
            {{-- Metric --}}
            <div class="{{ $group }} self-start" role="radiogroup" aria-label="Metric">
                @foreach ($trend['metrics'] as $option)
                    <button
                        type="button"
                        role="radio"
                        class="{{ $pill }}"
                        :class="metric === @js($option['value']) ? {{ $pillActive }} : {{ $pillIdle }}"
                        :aria-checked="(metric === @js($option['value'])).toString()"
                        @click="setMetric(@js($option['value']))"
                    >{{ $option['label'] }}</button>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-3">
                {{-- Data period; several years or more than 366 days only allow monthly --}}
                <div class="{{ $group }}" role="radiogroup" aria-label="Data Period">
                    @foreach ($trend['granularities'] as $option)
                        <button
                            type="button"
                            role="radio"
                            class="{{ $pill }}"
                            :class="activePeriod === @js($option['value']) ? {{ $pillActive }} : {{ $pillIdle }}"
                            :aria-checked="(activePeriod === @js($option['value'])).toString()"
                            :disabled="isPeriodDisabled(@js($option['value']))"
                            @click="setPeriod(@js($option['value']))"
                        >{{ $option['label'] }}</button>
                    @endforeach
                </div>

                {{-- Visualization --}}
                <div class="relative">
                    <label for="trend-chart" class="sr-only">Visualization</label>
                    <select
                        id="trend-chart"
                        x-model="chartType"
                        @change="draw()"
                        class="h-9 w-36 appearance-none rounded-lg border border-line bg-white pr-9 pl-3 text-sm font-semibold text-ink focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                    >
                        @foreach ($trend['charts'] as $option)
                            <option value="{{ $option['value'] }}" :disabled="isChartDisabled(@js($option['value']))">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    <x-icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-label" />
                </div>
            </div>
        </div>
    </div>

    <div class="mt-5 border-t border-line"></div>

    {{-- Chart --}}
    <div x-show="chartType !== 'table'" class="relative pt-5">
        <div class="relative h-80 sm:h-[26rem]">
            <canvas x-ref="canvas" role="img" :aria-label="chartLabel" :class="trendLoading && hasPoints && 'opacity-50'"></canvas>

            <div x-show="trendLoading && !hasPoints" class="absolute inset-0 animate-pulse rounded-lg bg-soft" aria-hidden="true"></div>

            <div x-show="!trendLoading && !hasPoints" x-cloak class="absolute inset-0 grid place-items-center text-center">
                <p class="text-sm text-label" x-text="trendPlaceholder">Grafik akan tampil setelah data tersedia.</p>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div x-show="chartType === 'table'" x-cloak class="mt-5 overflow-hidden rounded-lg border border-line">
        <div class="max-h-[26rem] overflow-auto">
            <table class="w-full min-w-[560px] text-left text-sm">
                <thead class="sticky top-0 bg-soft text-xs font-semibold text-label">
                    <tr>
                        <th scope="col" class="px-5 py-3">Period</th>
                        <th scope="col" class="px-5 py-3 text-right" x-text="metricMeta.label"></th>
                        <th scope="col" class="px-5 py-3 text-right">Change</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line transition-opacity" :class="trendLoading && 'opacity-60'">
                    <template x-for="row in trendTableRows" :key="row.key">
                        <tr class="transition hover:bg-soft/60">
                            <th scope="row" class="px-5 py-3 font-semibold whitespace-nowrap text-ink" x-text="row.period"></th>
                            <td class="performance-value px-5 py-3 text-right font-semibold text-ink" x-text="row.value"></td>
                            <td class="px-5 py-3 text-right">
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold whitespace-nowrap" :class="row.trend.tone" x-text="row.trend.text"></span>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="!hasPoints">
                        <td class="px-5 py-3 text-label">—</td>
                        <td class="px-5 py-3 text-right text-label">—</td>
                        <td class="px-5 py-3 text-right text-label">—</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
