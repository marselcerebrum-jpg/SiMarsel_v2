@extends('layouts.dashboard')

@section('title', 'Performance Summary')
@push('scripts')
    @vite('resources/js/pages/performance/index.js')
@endpush

@php
    $card = 'rounded-panel border border-line bg-surface shadow-sm';
    $exportButton = 'inline-flex h-9 items-center justify-center rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';

    // Display metadata only. Every value, comparison and formula comes from the backend.
    $rows = [
        'primary' => [
            'grid' => 'sm:grid-cols-2 xl:grid-cols-4',
            'metrics' => [
                ['key' => 'total_revenue', 'label' => 'Total Revenue', 'format' => 'currency', 'compact' => true, 'hint' => 'Total nilai transaksi berhasil'],
                ['key' => 'total_transactions', 'label' => 'Total Transactions', 'format' => 'number', 'hint' => 'Jumlah transaksi berhasil'],
                ['key' => 'total_downloaders', 'label' => 'Total Downloader', 'format' => 'number', 'hint' => 'Jumlah downloader aplikasi'],
                ['key' => 'new_users', 'label' => 'New Users', 'format' => 'number', 'hint' => 'Transaksi perdana'],
            ],
        ],
        'secondary' => [
            'grid' => 'sm:grid-cols-2 xl:grid-cols-4',
            'metrics' => [
                ['key' => 'unique_users', 'label' => 'Unique Users', 'format' => 'number', 'hint' => 'Email pembeli unik'],
                ['key' => 'average_order_value', 'label' => 'AOV', 'format' => 'currency', 'hint' => 'Total revenue ÷ jumlah transaksi'],
                ['key' => 'repeat_orders', 'label' => 'Repeat Order', 'format' => 'number', 'hint' => 'Transaksi perpanjangan'],
                ['key' => 'conversion_rate', 'label' => 'Conversion', 'format' => 'percent', 'hint' => 'Transaksi dibanding downloader', 'progress' => true],
            ],
        ],
    ];

    $platformOptions = $platforms->map(fn ($platform) => ['value' => $platform->slug, 'label' => $platform->name])->values();

    $trend = [
        'metrics' => [
            ['value' => 'revenue', 'label' => 'Revenue', 'format' => 'currency'],
            ['value' => 'transactions', 'label' => 'Transactions', 'format' => 'number'],
            ['value' => 'downloaders', 'label' => 'Downloader', 'format' => 'number'],
            ['value' => 'conversion', 'label' => 'Conversion', 'format' => 'percent'],
            ['value' => 'new_users', 'label' => 'New Users', 'format' => 'number'],
            ['value' => 'repeat_orders', 'label' => 'Repeat Order', 'format' => 'number'],
        ],
        'charts' => [
            ['value' => 'bar', 'label' => 'Bar'],
            ['value' => 'line', 'label' => 'Line'],
            ['value' => 'area', 'label' => 'Area'],
            ['value' => 'pie', 'label' => 'Pie'],
            ['value' => 'table', 'label' => 'Table'],
        ],
        'granularities' => [
            ['value' => 'daily', 'label' => 'Daily'],
            ['value' => 'weekly', 'label' => 'Weekly'],
            ['value' => 'monthly', 'label' => 'Monthly'],
        ],
    ];

    $paymentMethod = [
        'parameters' => [
            ['value' => 'revenue', 'label' => 'Revenue'],
            ['value' => 'transactions', 'label' => 'Transactions'],
        ],
    ];

    // English labels for the backend time range keys.
    $purchaseTime = [
        'labels' => [
            'dawn' => 'Early Morning',
            'morning' => 'Morning',
            'midday' => 'Midday',
            'afternoon' => 'Afternoon',
            'night' => 'Night',
            'unknown' => 'Unknown',
        ],
    ];
@endphp

@section('page')
    <div x-data="performanceSummary(@js($platformOptions), @js($years))" class="min-w-0">
        <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink">Performance Summary</h1>
                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted">
                    <span>Data keseluruhan performa perusahaan</span>
                    <span x-show="loadedLabel" x-cloak aria-hidden="true">·</span>
                    <span x-show="loadedLabel" x-cloak>Dimuat <span x-text="loadedLabel"></span></span>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" x-show="!loading && loadError" x-cloak class="{{ $exportButton }}" @click="load()">Reload</button>
                <a :href="exportLink('xlsx')" href="{{ route('api.performance.export', ['format' => 'xlsx']) }}" class="{{ $exportButton }}" :class="filterInvalid && 'pointer-events-none opacity-50'" :aria-disabled="filterInvalid.toString()">Export Excel</a>
                <a :href="exportLink('csv')" href="{{ route('api.performance.export', ['format' => 'csv']) }}" class="{{ $exportButton }}" :class="filterInvalid && 'pointer-events-none opacity-50'" :aria-disabled="filterInvalid.toString()">Export CSV</a>
            </div>
        </header>

        <p x-show="loadError" x-cloak x-text="loadError" class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert"></p>

        <section class="{{ $card }} relative mt-5 p-5" aria-labelledby="filter-title">
            @include('pages.performance.components.performance-filter')
        </section>

        <section aria-label="Key Performance Indicators" :aria-busy="loading.toString()">
            @foreach ($rows as $size => $row)
                <div class="mt-4 grid gap-4 {{ $row['grid'] }}">
                    @foreach ($row['metrics'] as $metric)
                        @include('pages.performance.components.performance-card', ['metric' => $metric, 'size' => $size])
                    @endforeach
                </div>
            @endforeach
        </section>

        <section class="{{ $card }} mt-4 p-5" aria-labelledby="performance-trend-title">
            @include('pages.performance.components.performance-trend')
        </section>

        <section class="{{ $card }} mt-4 p-5" aria-labelledby="performance-contribution-title">
            @include('pages.performance.components.performance-contribution')
        </section>

        <section class="{{ $card }} mt-4 p-5" aria-labelledby="performance-payment-method-title">
            @include('pages.performance.components.performance-payment-method')
        </section>

        <section class="{{ $card }} mt-4 p-5" aria-labelledby="performance-purchase-time-title">
            @include('pages.performance.components.performance-purchase-time')
        </section>
    </div>
@endsection
