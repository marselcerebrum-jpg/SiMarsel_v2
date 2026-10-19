{{-- Kondisi kartu selain data siap tampil. Variabel: $placeholder (bars-x|bars-y|table), $emptyText. --}}
@php
    $stateButton = 'inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50';
@endphp

{{-- Loading pertama --}}
<div x-show="loading && status !== 'ready'" aria-label="Memuat data">
    @include('pages.performance.components.placeholder', ['type' => $placeholder])
</div>

{{-- API backend belum tersedia: kerangka statis diredupkan + keterangan --}}
<div x-show="!loading && status === 'unavailable'" x-cloak class="relative">
    <div class="pointer-events-none opacity-50 select-none" aria-hidden="true">
        @include('pages.performance.components.placeholder', ['type' => $placeholder, 'static' => true])
    </div>

    <div class="absolute inset-0 grid place-items-center p-4">
        <div class="max-w-sm rounded-lg border border-line bg-white px-5 py-4 text-center shadow-sm" role="status">
            <p class="text-sm font-semibold text-ink">Data belum tersedia</p>
            <p class="mt-1 text-sm text-muted">Data akan tampil otomatis setelah API backend terhubung.</p>
        </div>
    </div>
</div>

{{-- Error --}}
<div x-show="!loading && status === 'error'" x-cloak class="flex flex-col items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-10 text-center">
    <p class="text-sm text-red-700" x-text="error"></p>
    <button type="button" class="{{ $stateButton }}" @click="load()">Try Again</button>
</div>

{{-- Data kosong --}}
<div x-show="!loading && status === 'empty'" x-cloak class="flex flex-col items-center gap-1 rounded-lg border border-dashed border-line bg-soft px-4 py-10 text-center">
    <p class="text-sm font-semibold text-ink">{{ $emptyText }}</p>
    <p class="text-sm text-muted">Tidak ada data untuk <span x-text="periodLabel"></span>.</p>
    <button x-show="hasActiveFilter" type="button" class="{{ $stateButton }} mt-3" @click="resetFilters()">Reset Filter</button>
</div>
