{{-- Filter shared by every promo analysis component. Periods use Markaz paid_date. --}}
@php
    $label = 'mb-1.5 block text-xs font-semibold text-label';
    $control = 'h-9 w-full rounded-lg border border-line bg-white px-3 text-sm text-ink focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:cursor-not-allowed disabled:bg-soft disabled:text-label';
    $chevron = 'pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-label';
    $menu = 'absolute z-30 mt-1 max-h-64 w-full min-w-48 overflow-y-auto rounded-lg border border-line bg-white p-1 shadow-lg';
    $option = 'flex h-9 cursor-pointer items-center gap-2.5 rounded-md px-2.5 text-sm text-ink hover:bg-soft';
    $checkbox = 'size-4 rounded border-line accent-primary';
    $error = 'mt-1.5 text-xs text-red-600';
    $multiSelects = [
        ['field' => 'platforms', 'label' => 'Platform / Source App', 'options' => 'platformOptions', 'summary' => 'platformSummary', 'empty' => 'Belum ada platform.'],
        ['field' => 'payment_methods', 'label' => 'Metode Pembayaran', 'options' => 'paymentMethodOptions', 'summary' => 'paymentMethodSummary', 'empty' => 'Belum ada metode pembayaran.'],
    ];
@endphp

<p x-show="optionsError" x-cloak class="mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
    <span x-text="optionsError"></span>
    <button type="button" class="font-semibold underline hover:no-underline" @click="loadOptions()">Coba Lagi</button>
</p>

<div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    @foreach ($multiSelects as $select)
        <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false" class="relative">
            <span id="promo-analysis-{{ $select['field'] }}-label" class="{{ $label }}">{{ $select['label'] }}</span>
            <button
                type="button"
                class="{{ $control }} relative truncate pr-9 text-left"
                :class="errors.{{ $select['field'] }} && 'border-red-500'"
                :disabled="optionsLoading"
                aria-labelledby="promo-analysis-{{ $select['field'] }}-label"
                aria-haspopup="listbox"
                :aria-expanded="open.toString()"
                @click="open = !open"
            >
                <span x-text="optionsLoading ? 'Memuat…' : {{ $select['summary'] }}"></span>
                <x-icon name="chevron-down" class="{{ $chevron }}" />
            </button>
            <div x-show="open" x-cloak class="{{ $menu }}" role="listbox" aria-multiselectable="true" aria-labelledby="promo-analysis-{{ $select['field'] }}-label">
                <template x-for="item in {{ $select['options'] }}" :key="item.value">
                    <label class="{{ $option }}">
                        <input type="checkbox" class="{{ $checkbox }}" :checked="filter.{{ $select['field'] }}.includes(item.value)" @change="toggle(@js($select['field']), item.value)">
                        <span x-text="item.label"></span>
                    </label>
                </template>
                <p x-show="{{ $select['options'] }}.length === 0" class="px-2.5 py-2 text-sm text-muted">{{ $select['empty'] }}</p>
            </div>
            <p x-show="errors.{{ $select['field'] }}" x-cloak x-text="errors.{{ $select['field'] }}" class="{{ $error }}"></p>
        </div>
    @endforeach

    <div>
        <label for="promo-analysis-search" class="{{ $label }}">Kode Promo</label>
        <div class="relative">
            <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-label" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7" />
                <path d="M20 20l-3.5-3.5" />
            </svg>
            <input
                id="promo-analysis-search"
                type="search"
                x-model.debounce.400ms="filter.search"
                :maxlength="searchMaxLength"
                placeholder="Cari kode promo…"
                class="{{ $control }} pl-9 placeholder:text-label"
                :class="errors.search && 'border-red-500'"
            >
        </div>
        <p x-show="errors.search" x-cloak x-text="errors.search" class="{{ $error }}"></p>
    </div>

    {{-- Only one period mode may be active; the API answers 422 otherwise --}}
    <div>
        <label for="promo-analysis-mode" class="{{ $label }}">Periode</label>
        <div class="relative">
            <select id="promo-analysis-mode" x-model="filter.mode" @change="changeMode()" class="{{ $control }} appearance-none pr-9">
                <template x-for="mode in modes" :key="mode.value">
                    <option :value="mode.value" x-text="mode.label" :selected="mode.value === filter.mode"></option>
                </template>
            </select>
            <x-icon name="chevron-down" class="{{ $chevron }}" />
        </div>
    </div>

    {{-- Month & Year --}}
    <template x-if="filter.mode === 'month'">
        <div>
            <label for="promo-analysis-month" class="{{ $label }}">Bulan</label>
            <div class="relative">
                <select id="promo-analysis-month" x-model="filter.month" @change="apply()" class="{{ $control }} appearance-none pr-9" :class="errors.month && 'border-red-500'">
                    <template x-for="(month, index) in months" :key="index">
                        <option :value="String(index + 1)" x-text="month" :disabled="isFutureMonth(index + 1)" :selected="String(index + 1) === String(filter.month)"></option>
                    </template>
                </select>
                <x-icon name="chevron-down" class="{{ $chevron }}" />
            </div>
            <p x-show="errors.month" x-cloak x-text="errors.month" class="{{ $error }}"></p>
        </div>
    </template>

    <template x-if="filter.mode === 'month'">
        <div>
            <label for="promo-analysis-year" class="{{ $label }}">Tahun</label>
            <div class="relative">
                <select id="promo-analysis-year" x-model="filter.year" @change="changeYear()" class="{{ $control }} appearance-none pr-9" :class="errors.year && 'border-red-500'">
                    <template x-for="year in years" :key="year">
                        <option :value="String(year)" x-text="year" :selected="String(year) === String(filter.year)"></option>
                    </template>
                </select>
                <x-icon name="chevron-down" class="{{ $chevron }}" />
            </div>
            <p x-show="errors.year" x-cloak x-text="errors.year" class="{{ $error }}"></p>
        </div>
    </template>

    {{-- Date Range --}}
    <template x-if="filter.mode === 'date_range'">
        <div>
            <label for="promo-analysis-start" class="{{ $label }}">Tanggal Mulai</label>
            <input
                id="promo-analysis-start"
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
            <label for="promo-analysis-end" class="{{ $label }}">Tanggal Akhir</label>
            <input
                id="promo-analysis-end"
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
