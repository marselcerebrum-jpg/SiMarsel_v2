@extends('layouts.dashboard')

@section('title', 'Dashboard')
@push('scripts')
    @vite('resources/js/pages/performance/index.js')
@endpush

@php
    $card = 'rounded-panel border border-line bg-surface shadow-sm';
    $exportButton = 'inline-flex h-9 items-center justify-center rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';

    // Display metadata only. Every value, comparison and formula comes from the backend.
    $rows = [
        'primary' => [
            'metrics' => [
                ['key' => 'total_revenue', 'label' => 'Total Revenue', 'format' => 'currency', 'compact' => true, 'hint' => 'Total nilai transaksi berhasil'],
                ['key' => 'total_transactions', 'label' => 'Total Transaksi', 'format' => 'number', 'hint' => 'Jumlah transaksi berhasil'],
                ['key' => 'total_downloaders', 'label' => 'Total Downloader', 'format' => 'number', 'hint' => 'Jumlah downloader aplikasi'],
                ['key' => 'new_users', 'label' => 'Pengguna Baru', 'format' => 'number', 'hint' => 'Transaksi perdana'],
            ],
        ],
        'secondary' => [
            'metrics' => [
                ['key' => 'unique_users', 'label' => 'Pembeli Unik', 'format' => 'number', 'hint' => 'Akun pembayar unik per platform'],
                ['key' => 'average_order_value', 'label' => 'AOV', 'format' => 'currency', 'hint' => 'Total revenue ÷ jumlah transaksi'],
                ['key' => 'repeat_orders', 'label' => 'Repeat Order', 'format' => 'number', 'hint' => 'Transaksi perpanjangan'],
                ['key' => 'conversion_rate', 'label' => 'Konversi', 'format' => 'percent', 'hint' => 'Transaksi dibanding downloader', 'progress' => true],
            ],
        ],
    ];

    $platformOptions = $platforms->map(fn ($platform) => ['value' => $platform->slug, 'label' => $platform->name])->values();

    $paymentMethod = [
        'parameters' => [
            ['value' => 'revenue', 'label' => 'Revenue'],
            ['value' => 'transactions', 'label' => 'Transaksi'],
        ],
    ];

    // English labels for the backend time range keys.
    $purchaseTime = [
        'labels' => [
            'dawn' => 'Dini Hari',
            'morning' => 'Pagi',
            'midday' => 'Siang',
            'afternoon' => 'Sore',
            'night' => 'Malam',
            'unknown' => 'Tidak Diketahui',
        ],
    ];
@endphp

@section('page')
    {{-- dashboardFilter owns the global filter; performanceSummary and every section listen to performance-filter,
         and performanceSummary hands the resolved period of its response back through performance-period. --}}
    <div x-data="dashboardFilter(@js($platformOptions))" @performance-period="receivePeriod($event.detail)" class="min-w-0">
        <div x-data="performanceSummary" @performance-filter.window="loadSummary($event.detail)" class="min-w-0">
            <div x-data="dashboardShell" class="min-w-0">
                @include('pages.performance.components.dashboard-compact-header')

                {{-- 1. Header & Filter --}}
                <header x-ref="pageHeader" class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-ink">Dashboard</h1>
                        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted">
                            <span>Data keseluruhan performa perusahaan</span>
                            <span x-show="loadedLabel" x-cloak aria-hidden="true">·</span>
                            <span x-show="loadedLabel" x-cloak>Dimuat <span x-text="loadedLabel"></span></span>
                        </p>
                    </div>

                    {{-- FIX-14-FE-01 wires the final Ekspor Laporan Dashboard action here. --}}
                    <div data-dashboard-slot="dashboard-export" role="group" aria-label="Ekspor Laporan Dashboard" class="flex flex-wrap items-center gap-2">
                        <button type="button" x-show="!loading && loadError" x-cloak class="{{ $exportButton }}" @click="load()">Muat Ulang</button>
                        <a :href="exportLink('xlsx')" href="{{ route('api.performance.export', ['format' => 'xlsx']) }}" class="{{ $exportButton }}" :class="filterInvalid && 'pointer-events-none opacity-50'" :aria-disabled="filterInvalid.toString()">Ekspor Excel</a>
                        <a :href="exportLink('csv')" href="{{ route('api.performance.export', ['format' => 'csv']) }}" class="{{ $exportButton }}" :class="filterInvalid && 'pointer-events-none opacity-50'" :aria-disabled="filterInvalid.toString()">Ekspor CSV</a>
                    </div>
                </header>

                <p x-show="loadError" x-cloak x-text="loadError" class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert"></p>

                <section data-dashboard-section="filter" class="{{ $card }} relative mt-5 p-5" aria-labelledby="filter-title">
                    <div class="flex items-center justify-between gap-3">
                        <h2 id="filter-title" class="text-base font-semibold text-ink">Filter</h2>
                        <button type="button" class="inline-flex h-9 items-center rounded-lg px-3 text-sm font-semibold text-muted hover:bg-soft hover:text-ink" @click="resetFilter()">
                            Atur Ulang
                        </button>
                    </div>

                    <div class="mt-4">
                        @include('pages.performance.components.performance-filter', ['prefix' => 'filter', 'statusShown' => '!filterPanelOpen'])
                    </div>
                </section>

                <p x-show="emptyMessage" x-cloak x-text="emptyMessage" class="mt-4 rounded-lg border border-line bg-soft px-4 py-2.5 text-sm text-muted" role="status"></p>

                {{-- 2. Ringkasan Sales: two blocks side by side when there is room, stacked on phones. --}}
                <section data-dashboard-section="sales-summary" class="mt-6" aria-labelledby="dashboard-sales-summary-title" :aria-busy="loading.toString()">
                    <h2 id="dashboard-sales-summary-title" class="text-base font-semibold text-ink">Ringkasan Sales</h2>

                    <div class="mt-3 grid gap-4 xl:grid-cols-2">
                        @foreach ($rows as $size => $row)
                            <div class="grid gap-4 sm:grid-cols-2">
                                @foreach ($row['metrics'] as $metric)
                                    @include('pages.performance.components.performance-card', ['metric' => $metric, 'size' => $size])
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </section>

                {{-- 3. Tren dan Performa: combined Omzet and transaction chart (FIX-06-FE-01). --}}
                <section data-dashboard-section="trend" class="{{ $card }} mt-4 p-5" aria-labelledby="performance-trend-title">
                    @include('pages.performance.components.performance-trend')
                </section>

                {{-- 4. Kontribusi Platform --}}
                <section data-dashboard-section="platform-contribution" class="{{ $card }} mt-4 p-5" aria-labelledby="performance-contribution-title">
                    @include('pages.performance.components.performance-contribution')
                </section>

                {{-- 5. Downloader: line chart and Lihat Rincian table (FIX-08-FE-01). --}}
                <section data-dashboard-section="downloader" class="{{ $card }} mt-4 min-w-0 p-5" aria-labelledby="dashboard-downloader-title">
                    @include('pages.performance.components.performance-downloader')
                </section>

                {{-- 6. Status Transaksi & Perdana vs Perpanjangan: side by side when there is room, stacked on phones. --}}
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <section data-dashboard-section="transaction-status" class="{{ $card }} min-w-0 p-5" aria-labelledby="dashboard-transaction-status-title">
                        @include('pages.performance.components.dashboard-slot', [
                            'key' => 'transaction-status',
                            'title' => 'Status Transaksi',
                        ])
                    </section>

                    <section data-dashboard-section="product-type" class="{{ $card }} min-w-0 p-5" aria-labelledby="dashboard-product-type-title">
                        @include('pages.performance.components.dashboard-slot', [
                            'key' => 'product-type',
                            'title' => 'Perdana vs Perpanjangan',
                        ])
                    </section>
                </div>

                {{-- 7. Metode Pembayaran --}}
                <section data-dashboard-section="payment-method" class="{{ $card }} mt-4 p-5" aria-labelledby="performance-payment-method-title">
                    @include('pages.performance.components.performance-payment-method')
                </section>

                {{-- 8. Performa Produk: the table is built in FIX-11-FE-01. --}}
                <section data-dashboard-section="product-performance" class="{{ $card }} mt-4 min-w-0 p-5" aria-labelledby="dashboard-product-performance-title">
                    @include('pages.performance.components.dashboard-slot', [
                        'key' => 'product-performance',
                        'title' => 'Performa Produk',
                    ])
                </section>

                {{-- 9. Jam Ramai Pembelian --}}
                <section data-dashboard-section="purchase-time" class="{{ $card }} mt-4 p-5" aria-labelledby="performance-purchase-time-title">
                    @include('pages.performance.components.performance-purchase-time')
                </section>

                {{-- 10. Daftar Transaksi: Ekspor Daftar Transaksi lives in this section (FIX-14-FE-01). --}}
                <section data-dashboard-section="transactions" class="{{ $card }} mt-4 min-w-0 p-5" aria-labelledby="dashboard-transactions-title">
                    @include('pages.performance.components.dashboard-slot', [
                        'key' => 'transactions',
                        'title' => 'Daftar Transaksi',
                        'exportLabel' => 'Ekspor Daftar Transaksi',
                    ])
                </section>
            </div>
        </div>
    </div>
@endsection
