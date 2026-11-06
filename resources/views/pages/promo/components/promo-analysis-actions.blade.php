{{-- Ekspor Excel and Salin for the active analysis filter. Both only read data: nothing is imported and no master data changes. --}}
@php
    $button = 'inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50';
    $spinner = 'size-3.5 animate-spin rounded-full border-2 border-t-transparent motion-reduce:animate-none';
@endphp

<div x-data="promoAnalysisActions" @promo-analysis-filter.window="clearStatus()" class="mt-4">
    <div class="flex flex-wrap items-center justify-end gap-2" role="group" aria-label="Ekspor dan Salin">
        <div class="relative w-full sm:w-72">
            <label for="promo-analysis-copy-target" class="sr-only">Komponen yang Disalin</label>
            <select
                id="promo-analysis-copy-target"
                x-model="copyTarget"
                :disabled="busy"
                class="h-9 w-full appearance-none rounded-lg border border-line bg-white px-3 pr-9 text-sm text-ink focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:cursor-not-allowed disabled:bg-soft"
            >
                <template x-for="target in copyTargets" :key="target.value">
                    <option :value="target.value" x-text="target.label" :selected="target.value === copyTarget"></option>
                </template>
            </select>
            <x-icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-label" />
        </div>

        <button
            type="button"
            class="{{ $button }} border border-line bg-white text-ink hover:bg-soft"
            :disabled="busy || actionsDisabled"
            @click="copy()"
        >
            <span x-show="copying" x-cloak class="{{ $spinner }} border-primary" aria-hidden="true"></span>
            <x-icon name="clipboard" x-show="!copying" class="size-4" />
            Salin
        </button>

        <button
            type="button"
            class="{{ $button }} bg-primary text-white hover:bg-primary-hover"
            :disabled="busy || actionsDisabled"
            :aria-busy="exporting.toString()"
            @click="exportExcel()"
        >
            <span x-show="exporting" x-cloak class="{{ $spinner }} border-white" aria-hidden="true"></span>
            <x-icon name="table" x-show="!exporting" class="size-4" />
            <span x-text="exporting ? 'Mengekspor…' : 'Ekspor Excel'">Ekspor Excel</span>
        </button>
    </div>

    <p
        x-show="status"
        x-cloak
        class="mt-2 rounded-lg border px-4 py-2.5 text-sm"
        :class="status?.type === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-line bg-soft text-muted'"
        :role="status?.type === 'error' ? 'alert' : 'status'"
        x-text="status?.message"
    ></p>
</div>
