{{-- KPI cards for the active analysis filter. Every value, comparison and formula comes from the backend. --}}
@php
    $metrics = [
        ['key' => 'promo_transactions', 'label' => 'Total Transaksi dengan Promo', 'format' => 'number'],
        ['key' => 'non_promo_transactions', 'label' => 'Total Transaksi tanpa Promo', 'format' => 'number'],
        ['key' => 'promo_revenue', 'label' => 'Revenue dengan Promo', 'format' => 'currency'],
        ['key' => 'non_promo_revenue', 'label' => 'Revenue tanpa Promo', 'format' => 'currency'],
        ['key' => 'promo_transaction_percentage', 'label' => 'Persentase Transaksi Promo', 'format' => 'percent'],
        ['key' => 'used_promo_codes', 'label' => 'Kode Promo Terpakai', 'format' => 'number'],
    ];
    $stateButton = 'inline-flex h-9 items-center justify-center rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
@endphp

<section
    x-data="promoAnalysisSummary"
    @promo-analysis-filter.window="load($event.detail)"
    class="mt-5 border-t border-line pt-5"
    aria-label="Ringkasan Promo"
>
    <div x-show="!loading && loadError" x-cloak class="flex flex-col items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-10 text-center" role="alert">
        <p class="text-sm text-red-700" x-text="loadError"></p>
        <button type="button" class="{{ $stateButton }}" @click="retry()">Coba Lagi</button>
    </div>

    <p x-show="!loading && isEmpty" x-cloak x-text="emptyMessage" class="mb-4 rounded-lg border border-dashed border-line bg-soft px-4 py-6 text-center text-sm font-semibold text-ink" role="status"></p>

    <div x-show="!loadError" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" :aria-busy="loading.toString()">
        @foreach ($metrics as $metric)
            @include('pages.promo.components.promo-analysis-card', ['metric' => $metric])
        @endforeach
    </div>
</section>
