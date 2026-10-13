@php
    $label = 'mb-1.5 block text-[11px] font-bold tracking-widest text-label uppercase';
    $control = 'h-11 w-full rounded-xl border border-line bg-soft px-4 text-sm font-semibold text-ink transition hover:border-brand-indigo/40 focus:border-brand-indigo focus:bg-white focus:ring-3 focus:ring-brand-indigo/15 focus:outline-none';
@endphp

<span class="absolute top-0 left-6 h-1 w-16 rounded-b-full bg-linear-to-r from-brand-indigo to-brand-teal" aria-hidden="true"></span>

<div class="flex items-center justify-between gap-3">
    <h2 id="filter-title" class="flex items-center gap-2 text-sm font-extrabold tracking-widest text-ink uppercase">
        <x-icon name="sliders" class="size-4 text-brand-indigo" />
        Filter
    </h2>
    <button type="button" class="group inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-sm font-medium text-muted transition hover:bg-soft hover:text-brand-indigo" @click="resetFilter()">
        <x-icon name="repeat" class="size-3.5 transition-transform duration-500 group-hover:-rotate-180" />
        Reset
    </button>
</div>

<div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div>
        <label for="filter-preset" class="{{ $label }}">Period</label>
        <div class="relative">
            <select id="filter-preset" x-model="filter.preset" @change="changePreset()" class="{{ $control }} appearance-none pr-10">
                <template x-for="preset in presets" :key="preset.value">
                    <option :value="preset.value" x-text="preset.label" :selected="preset.value === filter.preset"></option>
                </template>
            </select>
            <x-icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-4 size-4 -translate-y-1/2 text-label" />
        </div>
    </div>

    <div>
        <label for="filter-platform" class="{{ $label }}">Platform</label>
        <div class="relative">
            <select
                id="filter-platform"
                x-model="filter.platform"
                @change="load()"
                class="{{ $control }} appearance-none pr-10"
                :class="errors.platform && 'border-red-500'"
                aria-describedby="filter-platform-error"
            >
                <option value="">All Platforms</option>
                <template x-for="platform in platforms" :key="platform.value">
                    <option :value="platform.value" x-text="platform.label" :selected="platform.value === filter.platform"></option>
                </template>
            </select>
            <x-icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-4 size-4 -translate-y-1/2 text-label" />
        </div>
        <p id="filter-platform-error" x-show="errors.platform" x-cloak x-text="errors.platform" class="mt-1.5 text-xs text-red-600"></p>
    </div>

    <div>
        <label for="filter-start" class="{{ $label }}">Start Date</label>
        <input
            id="filter-start"
            type="date"
            x-model="filter.start_date"
            :max="filter.end_date || maxDate"
            @change="changeDate()"
            class="performance-date {{ $control }}"
            :class="errors.start_date && 'border-red-500'"
            :aria-invalid="Boolean(errors.start_date).toString()"
            aria-describedby="filter-start-error"
        >
        <p id="filter-start-error" x-show="errors.start_date" x-cloak x-text="errors.start_date" class="mt-1.5 text-xs text-red-600"></p>
    </div>

    <div>
        <label for="filter-end" class="{{ $label }}">End Date</label>
        <input
            id="filter-end"
            type="date"
            x-model="filter.end_date"
            :min="filter.start_date"
            :max="maxDate"
            @change="changeDate()"
            class="performance-date {{ $control }}"
            :class="errors.end_date && 'border-red-500'"
            :aria-invalid="Boolean(errors.end_date).toString()"
            aria-describedby="filter-end-error"
        >
        <p id="filter-end-error" x-show="errors.end_date" x-cloak x-text="errors.end_date" class="mt-1.5 text-xs text-red-600"></p>
    </div>
</div>
