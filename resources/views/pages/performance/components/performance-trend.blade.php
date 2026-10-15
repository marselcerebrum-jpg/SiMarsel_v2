@php
    $group = 'inline-flex max-w-full shrink-0 items-center gap-1 overflow-x-auto rounded-2xl border border-line bg-soft p-1.5';
    $pill = 'inline-flex h-10 shrink-0 items-center gap-1.5 rounded-xl px-4 text-xs font-bold tracking-wider uppercase transition duration-200 focus-visible:outline-2 focus-visible:outline-brand-indigo';
    $pillActive = "'bg-linear-135 from-brand-indigo via-brand-blue to-brand-teal text-white shadow-md shadow-brand-indigo/30'";
    $pillIdle = "'text-muted hover:bg-white hover:text-brand-indigo'";
@endphp

<span class="absolute top-0 left-6 h-1 w-16 rounded-b-full bg-linear-to-r from-brand-indigo to-brand-teal" aria-hidden="true"></span>

<div
    x-data="performanceTrend(@js($trend['metrics']))"
    @performance-filter.window="applyFilter($event.detail)"
>
    <div class="flex flex-col gap-5 2xl:flex-row 2xl:items-start 2xl:justify-between">
        <div class="2xl:max-w-52">
            <h2 id="performance-trend-title" class="text-2xl leading-tight font-extrabold tracking-tight text-ink">Trend &amp; Performance Analysis</h2>
            <p class="mt-1.5 text-sm text-muted">Pilih metrik, periode data, dan tipe tampilan.</p>
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
                {{-- Periode data --}}
                <div class="{{ $group }}" role="radiogroup" aria-label="Data Period">
                    @foreach ($trend['granularities'] as $option)
                        <button
                            type="button"
                            role="radio"
                            class="{{ $pill }}"
                            :class="period === @js($option['value']) ? {{ $pillActive }} : {{ $pillIdle }}"
                            :aria-checked="(period === @js($option['value'])).toString()"
                            @click="setPeriod(@js($option['value']))"
                        >{{ $option['label'] }}</button>
                    @endforeach
                </div>

                {{-- Visualisasi (dropdown) --}}
                <div class="{{ $group }} relative">
                    <label for="trend-chart" class="sr-only">Visualization</label>
                    <x-icon name="chart" class="pointer-events-none absolute top-1/2 left-5 size-4 -translate-y-1/2 text-white" />
                    <select
                        id="trend-chart"
                        x-model="chartType"
                        @change="draw()"
                        class="h-10 w-40 cursor-pointer appearance-none rounded-xl bg-linear-135 from-brand-indigo via-brand-blue to-brand-teal pr-9 pl-10 text-xs font-bold tracking-wider text-white uppercase shadow-md shadow-brand-indigo/30 focus:ring-3 focus:ring-brand-indigo/25 focus:outline-none"
                    >
                        @foreach ($trend['charts'] as $option)
                            <option value="{{ $option['value'] }}" class="bg-surface text-ink">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    <x-icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-4 size-4 -translate-y-1/2 text-white" />
                </div>
            </div>
        </div>
    </div>

    <div class="mt-6 border-t border-line"></div>

    {{-- Chart --}}
    <div x-show="chartType !== 'table'" class="relative pt-6">
        <div class="relative h-80 sm:h-[26rem]">
            <canvas x-ref="canvas" role="img" :aria-label="chartLabel" class="transition-opacity duration-300" :class="trendLoading && hasPoints && 'opacity-50'"></canvas>

            <div x-show="trendLoading && !hasPoints" class="absolute inset-0 flex items-end gap-1.5 px-2 pb-2 sm:gap-2.5" aria-hidden="true">
                @foreach ([38, 52, 45, 63, 50, 72, 58, 44, 66, 80, 61, 49, 70, 55, 90, 62, 47, 58, 75, 52, 41, 57] as $height)
                    <span class="performance-shimmer flex-1 rounded-t-md" style="height: {{ $height }}%"></span>
                @endforeach
            </div>

            <div x-show="!trendLoading && !hasPoints" x-cloak class="absolute inset-0 grid place-items-center text-center">
                <div class="flex flex-col items-center gap-2 text-label">
                    <x-icon name="chart" class="size-8" />
                    <p class="text-sm" x-text="trendPlaceholder">Grafik akan tampil setelah data tersedia.</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div x-show="chartType === 'table'" x-cloak class="mt-6 overflow-hidden rounded-2xl border border-line bg-white/70 dark:bg-white/40">
        <div class="max-h-[26rem] overflow-auto">
            <table class="w-full min-w-[560px] text-left text-sm">
                <thead class="sticky top-0 bg-[#eaf0f8] text-[11px] font-bold tracking-widest text-label uppercase dark:bg-[#dbe3ee]">
                    <tr>
                        <th scope="col" class="px-5 py-3.5">Period</th>
                        <th scope="col" class="px-5 py-3.5 text-right" x-text="metricMeta.label"></th>
                        <th scope="col" class="px-5 py-3.5 text-right">Change</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line transition-opacity" :class="trendLoading && 'opacity-60'">
                    <template x-for="row in trendTableRows" :key="row.key">
                        <tr class="transition hover:bg-soft/70">
                            <th scope="row" class="px-5 py-3.5 font-semibold whitespace-nowrap text-ink" x-text="row.period"></th>
                            <td class="performance-value px-5 py-3.5 text-right font-semibold text-ink" x-text="row.value"></td>
                            <td class="px-5 py-3.5 text-right">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-bold whitespace-nowrap" :class="row.trend.tone" x-text="row.trend.text"></span>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="!hasPoints">
                        <td class="px-5 py-3.5 text-label">—</td>
                        <td class="px-5 py-3.5 text-right text-label">—</td>
                        <td class="px-5 py-3.5 text-right text-label">—</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
