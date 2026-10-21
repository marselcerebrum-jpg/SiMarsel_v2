@php
    $label = 'mb-1.5 block text-xs font-semibold text-label';
    $control = 'h-9 w-full rounded-lg border border-line bg-white px-3 text-sm text-ink focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:cursor-not-allowed disabled:bg-soft disabled:text-label';
    $chevron = 'pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-label';
    $menu = 'absolute z-30 mt-1 max-h-64 w-full min-w-48 overflow-y-auto rounded-lg border border-line bg-white p-1 shadow-lg';
    $option = 'flex h-9 cursor-pointer items-center gap-2.5 rounded-md px-2.5 text-sm text-ink hover:bg-soft';
    $checkbox = 'size-4 rounded border-line accent-primary';
    $error = 'mt-1.5 text-xs text-red-600';
@endphp

<div class="flex items-center justify-between gap-3">
    <h2 id="filter-title" class="text-base font-semibold text-ink">Filter</h2>
    <button type="button" class="inline-flex h-9 items-center rounded-lg px-3 text-sm font-semibold text-muted hover:bg-soft hover:text-ink" @click="resetFilter()">
        Reset
    </button>
</div>

<div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    {{-- Platforms (multi-select) --}}
    <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false" class="relative">
        <span id="filter-platforms-label" class="{{ $label }}">Platforms</span>
        <button
            type="button"
            class="{{ $control }} relative truncate pr-9 text-left"
            :class="errors.platforms && 'border-red-500'"
            aria-labelledby="filter-platforms-label"
            aria-haspopup="listbox"
            :aria-expanded="open.toString()"
            @click="open = !open"
        >
            <span x-text="platformSummary">All Platforms</span>
            <x-icon name="chevron-down" class="{{ $chevron }}" />
        </button>
        <div x-show="open" x-cloak class="{{ $menu }}" role="listbox" aria-multiselectable="true" aria-labelledby="filter-platforms-label">
            <template x-for="platform in platforms" :key="platform.value">
                <label class="{{ $option }}">
                    <input type="checkbox" class="{{ $checkbox }}" :checked="filter.platforms.includes(platform.value)" @change="togglePlatform(platform.value)">
                    <span x-text="platform.label"></span>
                </label>
            </template>
        </div>
        <p x-show="errors.platforms" x-cloak x-text="errors.platforms" class="{{ $error }}"></p>
    </div>

    {{-- Period mode: only one may be active, the API answers 422 otherwise --}}
    <div>
        <label for="filter-mode" class="{{ $label }}">Period</label>
        <div class="relative">
            <select id="filter-mode" x-model="filter.mode" @change="changeMode()" class="{{ $control }} appearance-none pr-9">
                <template x-for="mode in modes" :key="mode.value">
                    <option :value="mode.value" x-text="mode.label" :selected="mode.value === filter.mode"></option>
                </template>
            </select>
            <x-icon name="chevron-down" class="{{ $chevron }}" />
        </div>
    </div>

    {{-- Year & Month --}}
    <template x-if="filter.mode === 'years'">
        <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false" class="relative">
            <span id="filter-years-label" class="{{ $label }}">Years <span class="font-normal">(maks. <span x-text="maxYears"></span>)</span></span>
            <button
                type="button"
                class="{{ $control }} relative truncate pr-9 text-left"
                :class="errors.years && 'border-red-500'"
                aria-labelledby="filter-years-label"
                aria-haspopup="listbox"
                :aria-expanded="open.toString()"
                @click="open = !open"
            >
                <span x-text="yearSummary"></span>
                <x-icon name="chevron-down" class="{{ $chevron }}" />
            </button>
            <div x-show="open" x-cloak class="{{ $menu }}" role="listbox" aria-multiselectable="true" aria-labelledby="filter-years-label">
                <template x-for="year in years" :key="year">
                    <label class="{{ $option }}" :class="isYearDisabled(year) && 'cursor-not-allowed opacity-50'">
                        <input type="checkbox" class="{{ $checkbox }}" :checked="filter.years.includes(year)" :disabled="isYearDisabled(year)" @change="toggleYear(year)">
                        <span x-text="year"></span>
                    </label>
                </template>
            </div>
            <p x-show="errors.years" x-cloak x-text="errors.years" class="{{ $error }}"></p>
        </div>
    </template>

    <template x-if="filter.mode === 'years'">
        <div>
            <label for="filter-month" class="{{ $label }}">Month</label>
            <div class="relative">
                <select id="filter-month" x-model="filter.month" @change="apply()" :disabled="!monthEnabled" class="{{ $control }} appearance-none pr-9" :class="errors.month && 'border-red-500'">
                    <option value="">All Months</option>
                    <template x-for="(month, index) in months" :key="index">
                        <option :value="String(index + 1)" x-text="month" :selected="String(index + 1) === String(filter.month)"></option>
                    </template>
                </select>
                <x-icon name="chevron-down" class="{{ $chevron }}" />
            </div>
            <p x-show="!monthEnabled" class="mt-1.5 text-xs text-label">Bulan hanya bisa dipilih jika satu tahun dipilih.</p>
            <p x-show="errors.month" x-cloak x-text="errors.month" class="{{ $error }}"></p>
        </div>
    </template>

    {{-- Year range --}}
    <template x-if="filter.mode === 'year_range'">
        <div>
            <label for="filter-year-from" class="{{ $label }}">From Year</label>
            <div class="relative">
                <select id="filter-year-from" x-model="filter.year_from" @change="apply()" class="{{ $control }} appearance-none pr-9" :class="errors.year_from && 'border-red-500'">
                    <template x-for="year in years" :key="year">
                        <option :value="String(year)" x-text="year" :selected="String(year) === String(filter.year_from)"></option>
                    </template>
                </select>
                <x-icon name="chevron-down" class="{{ $chevron }}" />
            </div>
            <p x-show="errors.year_from" x-cloak x-text="errors.year_from" class="{{ $error }}"></p>
        </div>
    </template>

    <template x-if="filter.mode === 'year_range'">
        <div>
            <label for="filter-year-to" class="{{ $label }}">To Year</label>
            <div class="relative">
                <select id="filter-year-to" x-model="filter.year_to" @change="apply()" class="{{ $control }} appearance-none pr-9" :class="errors.year_to && 'border-red-500'">
                    <template x-for="year in years" :key="year">
                        <option :value="String(year)" x-text="year" :selected="String(year) === String(filter.year_to)"></option>
                    </template>
                </select>
                <x-icon name="chevron-down" class="{{ $chevron }}" />
            </div>
            <p x-show="errors.year_to" x-cloak x-text="errors.year_to" class="{{ $error }}"></p>
        </div>
    </template>

    {{-- Date range --}}
    <template x-if="filter.mode === 'date_range'">
        <div>
            <label for="filter-start" class="{{ $label }}">Start Date</label>
            <input
                id="filter-start"
                type="date"
                x-model="filter.date_from"
                :max="filter.date_to || maxDate"
                @change="apply()"
                class="{{ $control }}"
                :class="errors.date_from && 'border-red-500'"
                :aria-invalid="Boolean(errors.date_from).toString()"
            >
            <p x-show="errors.date_from" x-cloak x-text="errors.date_from" class="{{ $error }}"></p>
        </div>
    </template>

    <template x-if="filter.mode === 'date_range'">
        <div>
            <label for="filter-end" class="{{ $label }}">End Date</label>
            <input
                id="filter-end"
                type="date"
                x-model="filter.date_to"
                :min="filter.date_from"
                :max="maxDate"
                @change="apply()"
                class="{{ $control }}"
                :class="errors.date_to && 'border-red-500'"
                :aria-invalid="Boolean(errors.date_to).toString()"
            >
            <p x-show="errors.date_to" x-cloak x-text="errors.date_to" class="{{ $error }}"></p>
        </div>
    </template>
</div>

<p x-show="errors.filter" x-cloak x-text="errors.filter" class="{{ $error }}" role="alert"></p>

<p x-show="periodLabel && !filterInvalid" x-cloak class="mt-4 text-xs text-muted">
    Menampilkan data <span x-text="periodLabel" class="font-semibold text-ink"></span>
</p>
