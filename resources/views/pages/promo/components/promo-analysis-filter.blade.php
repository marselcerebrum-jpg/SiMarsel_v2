{{-- Filter shared by every promo analysis component. Edits apply on "Terapkan filter"; periods use Markaz paid_date. --}}
@php
    $label = 'mb-1.5 block text-xs font-semibold text-label';
    $control = 'h-10 w-full rounded-lg border border-line bg-white px-3 text-sm text-ink focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:cursor-not-allowed disabled:bg-soft disabled:text-label';
    $chevron = 'pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-label';
    $menu = 'absolute z-30 mt-1 max-h-64 w-full min-w-48 overflow-y-auto rounded-lg border border-line bg-white p-1 shadow-lg';
    $option = 'flex h-10 cursor-pointer items-center gap-2.5 rounded-md px-2.5 text-sm text-ink hover:bg-soft';
    $checkbox = 'size-4 rounded border-line accent-primary';
    $error = 'mt-1.5 text-xs break-words text-red-600';
    $button = 'inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:w-auto';
    $platformSelect = ['field' => 'platforms', 'label' => 'Platform / Source App', 'options' => 'platformOptions', 'summary' => 'platformSummary', 'empty' => 'Belum ada platform.'];
    $paymentSelect = ['field' => 'payment_methods', 'label' => 'Metode Pembayaran', 'options' => 'paymentMethodOptions', 'summary' => 'paymentMethodSummary', 'empty' => 'Belum ada metode pembayaran.'];
@endphp

<section class="{{ $card }} min-w-0 p-5" aria-labelledby="promo-analysis-title">
    <h2 id="promo-analysis-title" class="text-base font-semibold text-ink">Filter Analisis</h2>
    <p class="mt-1 text-sm text-muted">Bandingkan transaksi dan revenue dengan promo dan tanpa promo berdasarkan tanggal bayar Markaz.</p>

    <p x-show="optionsError" x-cloak class="mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
        <span x-text="optionsError"></span>
        <button type="button" class="font-semibold underline hover:no-underline" @click="loadOptions()">Coba Lagi</button>
    </p>

    <form class="mt-4" novalidate @submit.prevent="apply()">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
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
                        <select id="promo-analysis-month" x-model="filter.month" class="{{ $control }} appearance-none pr-9" :class="errors.month && 'border-red-500'" :aria-invalid="Boolean(errors.month).toString()" aria-describedby="promo-analysis-month-error">
                            <template x-for="(month, index) in months" :key="index">
                                <option :value="String(index + 1)" x-text="month" :disabled="isFutureMonth(index + 1)" :selected="String(index + 1) === String(filter.month)"></option>
                            </template>
                        </select>
                        <x-icon name="chevron-down" class="{{ $chevron }}" />
                    </div>
                    <p id="promo-analysis-month-error" x-show="errors.month" x-cloak x-text="errors.month" class="{{ $error }}"></p>
                </div>
            </template>

            <template x-if="filter.mode === 'month'">
                <div>
                    <label for="promo-analysis-year" class="{{ $label }}">Tahun</label>
                    <div class="relative">
                        <select id="promo-analysis-year" x-model="filter.year" @change="changeYear()" class="{{ $control }} appearance-none pr-9" :class="errors.year && 'border-red-500'" :aria-invalid="Boolean(errors.year).toString()" aria-describedby="promo-analysis-year-error">
                            <template x-for="year in years" :key="year">
                                <option :value="String(year)" x-text="year" :selected="String(year) === String(filter.year)"></option>
                            </template>
                        </select>
                        <x-icon name="chevron-down" class="{{ $chevron }}" />
                    </div>
                    <p id="promo-analysis-year-error" x-show="errors.year" x-cloak x-text="errors.year" class="{{ $error }}"></p>
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
                        class="{{ $control }}"
                        :class="errors.date_from && 'border-red-500'"
                        :aria-invalid="Boolean(errors.date_from).toString()"
                        aria-describedby="promo-analysis-start-error"
                    >
                    <p id="promo-analysis-start-error" x-show="errors.date_from" x-cloak x-text="errors.date_from" class="{{ $error }}"></p>
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
                        class="{{ $control }}"
                        :class="errors.date_to && 'border-red-500'"
                        :aria-invalid="Boolean(errors.date_to).toString()"
                        aria-describedby="promo-analysis-end-error"
                    >
                    <p id="promo-analysis-end-error" x-show="errors.date_to" x-cloak x-text="errors.date_to" class="{{ $error }}"></p>
                </div>
            </template>

            @include('pages.promo.components.promo-analysis-multiselect', ['select' => $platformSelect])
        </div>

        <div class="mt-4">
            <button
                type="button"
                class="inline-flex min-h-11 items-center gap-2 rounded-lg px-1 text-sm font-semibold text-primary hover:text-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                aria-controls="promo-analysis-advanced"
                :aria-expanded="advancedOpen.toString()"
                @click="advancedOpen = !advancedOpen"
            >
                <x-icon name="chevron-down" class="size-4 transition-transform" ::class="advancedOpen && 'rotate-180'" />
                Filter lanjutan
            </button>

            <div id="promo-analysis-advanced" x-show="advancedOpen" x-cloak class="mt-2 grid gap-4 rounded-lg border border-line bg-soft/60 p-4 sm:grid-cols-2">
                @include('pages.promo.components.promo-analysis-multiselect', ['select' => $paymentSelect])

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
                            x-model="filter.search"
                            :maxlength="searchMaxLength"
                            placeholder="Cari kode promo…"
                            class="{{ $control }} pl-9 placeholder:text-label"
                            :class="errors.search && 'border-red-500'"
                            :aria-invalid="Boolean(errors.search).toString()"
                            aria-describedby="promo-analysis-search-error"
                        >
                    </div>
                    <p id="promo-analysis-search-error" x-show="errors.search" x-cloak x-text="errors.search" class="{{ $error }}"></p>
                </div>
            </div>
        </div>

        <p x-show="errors.filter" x-cloak x-text="errors.filter" class="{{ $error }}" role="alert"></p>

        <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center">
            <button type="submit" class="{{ $button }} bg-primary text-white hover:bg-primary-hover">Terapkan filter</button>
            <button type="button" class="{{ $button }} border border-line bg-white text-ink hover:bg-soft" @click="resetFilter()">Atur ulang</button>
        </div>
    </form>

    <div class="mt-5 border-t border-line pt-4">
        <p class="text-xs font-semibold text-label">Data yang ditampilkan</p>
        <dl class="mt-2 flex flex-wrap gap-2 text-xs" aria-live="polite">
            <template x-for="item in activeFilters" :key="item.label">
                <div class="inline-flex max-w-full items-center gap-1 rounded-md bg-soft px-2 py-1">
                    <dt class="text-muted" x-text="item.label + ':'"></dt>
                    <dd class="font-semibold break-all text-ink" x-text="item.value"></dd>
                </div>
            </template>
        </dl>

        @include('pages.promo.components.promo-analysis-actions')
    </div>
</section>
