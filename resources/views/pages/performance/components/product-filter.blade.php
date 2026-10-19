@php
    $labelClass = 'mb-1.5 block text-xs font-semibold text-label';
    $selectClass = 'h-9 w-full appearance-none rounded-lg border border-line bg-white px-3 pr-9 text-sm text-ink focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none';
    $chevron = 'pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-label';
@endphp

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[repeat(3,minmax(0,1fr))_auto] lg:items-end">
    <div>
        <label for="filter-platform" class="{{ $labelClass }}">Source App / Platform</label>
        <div class="relative">
            <select id="filter-platform" x-model="filters.platform" class="{{ $selectClass }}">
                <option value="">All Platforms</option>
                <template x-for="platform in platforms" :key="platform.slug">
                    <option :value="platform.slug" x-text="platform.name"></option>
                </template>
            </select>
            <x-icon name="chevron-down" class="{{ $chevron }}" />
        </div>
    </div>

    <div>
        <label for="filter-year" class="{{ $labelClass }}">Year</label>
        <div class="relative">
            <select id="filter-year" x-model="filters.year" class="{{ $selectClass }}">
                <template x-for="year in years" :key="year">
                    <option :value="year" x-text="year" :selected="year == filters.year"></option>
                </template>
            </select>
            <x-icon name="chevron-down" class="{{ $chevron }}" />
        </div>
    </div>

    <div>
        <label for="filter-month" class="{{ $labelClass }}">Month</label>
        <div class="relative">
            <select id="filter-month" x-model="filters.month" class="{{ $selectClass }}">
                <option value="">All Months</option>
                <template x-for="(month, index) in months" :key="index">
                    <option :value="index + 1" x-text="month"></option>
                </template>
            </select>
            <x-icon name="chevron-down" class="{{ $chevron }}" />
        </div>
    </div>

    <button
        type="button"
        class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50 sm:col-span-2 lg:col-span-1"
        :disabled="!hasActiveFilter"
        @click="resetFilters()"
    >
        Reset Filter
    </button>
</div>

<p x-show="optionsError" x-cloak x-text="optionsError" class="mt-1.5 text-xs text-red-600"></p>
