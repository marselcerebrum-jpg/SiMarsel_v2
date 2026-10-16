{{-- Kondisi kartu selain data siap tampil. Variabel: $placeholder (bars-x|bars-y|table), $emptyText. --}}

{{-- Loading pertama --}}
<div x-show="loading && status !== 'ready'" class="animate-pulse" aria-label="Memuat data">
    @include('pages.performance.components.placeholder', ['type' => $placeholder])
</div>

{{-- API backend belum tersedia: kerangka diburamkan + keterangan --}}
<div x-show="!loading && status === 'unavailable'" x-cloak class="relative">
    <div class="pointer-events-none blur-[3px] select-none" aria-hidden="true">
        @include('pages.performance.components.placeholder', ['type' => $placeholder])
    </div>

    <div class="absolute inset-0 grid place-items-center p-4">
        <div class="max-w-sm rounded-2xl border border-line bg-white/90 px-5 py-4 text-center shadow-lg backdrop-blur" role="status">
            <span class="mx-auto grid size-10 place-items-center rounded-xl bg-brand-indigo/10 text-brand-indigo">
                <x-icon name="clipboard" class="size-5" />
            </span>
            <p class="mt-2 text-sm font-bold text-ink">Data belum tersedia</p>
            <p class="mt-1 text-[13px] text-muted">Data akan tampil otomatis setelah API backend terhubung.</p>
        </div>
    </div>
</div>

{{-- Error --}}
<div x-show="!loading && status === 'error'" x-cloak class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-red-300 bg-red-50 px-4 py-10 text-center">
    <p class="text-sm text-red-700" x-text="error"></p>
    <button type="button" class="h-10 rounded-xl border border-line bg-white px-4 text-sm font-medium text-ink hover:border-brand-indigo" @click="load()">Coba lagi</button>
</div>

{{-- Data kosong --}}
<div x-show="!loading && status === 'empty'" x-cloak class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-line bg-soft px-4 py-10 text-center text-muted">
    <x-icon name="cube" class="size-7" />
    <p class="text-sm font-semibold text-ink">{{ $emptyText }}</p>
    <p class="text-[13px]">Tidak ada data untuk <span x-text="periodLabel"></span>.</p>
    <button x-show="hasActiveFilter" type="button" class="mt-2 h-10 rounded-xl border border-line bg-white px-4 text-sm font-medium text-ink hover:border-brand-indigo" @click="resetFilters()">Reset Filter</button>
</div>
