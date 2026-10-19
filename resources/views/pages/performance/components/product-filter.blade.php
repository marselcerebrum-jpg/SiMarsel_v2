@php
    $labelClass = 'mb-1.5 block pl-1 text-[11px] font-bold tracking-widest text-label uppercase';
    $selectClass = 'h-11 w-full appearance-none rounded-xl border border-line bg-soft pr-10 pl-4 text-sm font-medium text-ink focus:border-brand-indigo focus:bg-white focus:ring-3 focus:ring-brand-indigo/15 focus:outline-none';
    $chevron = 'pointer-events-none absolute top-1/2 right-4 size-3 -translate-y-1/2 text-label';
@endphp

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[repeat(3,minmax(0,1fr))_auto] lg:items-end">
    <div>
        <label for="filter-platform" class="{{ $labelClass }}">Source App / Platform</label>
        <div class="relative">
            <select id="filter-platform" x-model="filters.platform" class="{{ $selectClass }}">
                <option value="">Semua Platform</option>
                <template x-for="platform in platforms" :key="platform.slug">
                    <option :value="platform.slug" x-text="platform.name"></option>
                </template>
            </select>
            <x-icon name="chevron-down" class="{{ $chevron }}" />
        </div>
    </div>

    <div>
        <label for="filter-year" class="{{ $labelClass }}">Tahun</label>
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
        <label for="filter-month" class="{{ $labelClass }}">Bulan</label>
        <div class="relative">
            <select id="filter-month" x-model="filters.month" class="{{ $selectClass }}">
                <option value="">Semua Bulan</option>
                <template x-for="(month, index) in months" :key="index">
                    <option :value="index + 1" x-text="month"></option>
                </template>
            </select>
            <x-icon name="chevron-down" class="{{ $chevron }}" />
        </div>
    </div>

    <button
        type="button"
        class="h-11 rounded-xl border border-line bg-soft px-5 text-sm font-medium text-ink hover:border-brand-indigo disabled:opacity-50 sm:col-span-2 lg:col-span-1"
        :disabled="!hasActiveFilter"
        @click="resetFilters()"
    >
        Reset Filter
    </button>
</div>

<p x-show="optionsError" x-cloak x-text="optionsError" class="mt-3 text-xs text-red-600"></p>
