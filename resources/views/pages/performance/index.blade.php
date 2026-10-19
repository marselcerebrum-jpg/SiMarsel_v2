@extends('layouts.dashboard')

@section('title', 'Performance Summary')
@push('scripts')
    @vite('resources/js/pages/performance/index.js')
@endpush

@php
    $card = 'rounded-3xl border border-white/60 bg-surface shadow-[0_24px_60px_rgba(30,41,70,0.25)] dark:border-white/25 dark:bg-surface-dim';

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

    $definitions = collect($rows)
        ->flatMap(fn (array $row) => $row['metrics'])
        ->map(fn (array $metric) => Arr::only($metric, ['key', 'label', 'format']))
        ->values();

    $trend = [
        'metrics' => [
            ['value' => 'revenue', 'label' => 'Revenue', 'format' => 'currency'],
            ['value' => 'transactions', 'label' => 'Transactions', 'format' => 'number'],
            ['value' => 'downloaders', 'label' => 'Downloader', 'format' => 'number'],
            ['value' => 'conversion', 'label' => 'Conversion', 'format' => 'percent'],
            ['value' => 'new_users', 'label' => 'User Baru', 'format' => 'number'],
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

    $delay = 120;
@endphp

@section('page')
    <div x-data="performanceSummary(@js($definitions), @js($platformOptions))" class="min-w-0">
        <header class="performance-rise flex flex-col gap-3 text-white sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-3xl font-extrabold tracking-tight sm:text-4xl">Performance Summary</h1>
                <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-white/80">
                    <span>Data keseluruhan performa perusahaan</span>
                    <span x-show="loadedLabel" x-cloak class="size-1 rounded-full bg-white/60" aria-hidden="true"></span>
                    <span x-show="loadedLabel" x-cloak>Dimuat <span x-text="loadedLabel"></span></span>
                </p>
            </div>

            <div class="flex items-center gap-2 self-start sm:self-auto">
                <button
                    type="button"
                    x-show="!loading && loadError"
                    x-cloak
                    class="grid size-11 place-items-center rounded-xl border border-white/30 bg-white/15 backdrop-blur transition hover:bg-white/25"
                    title="Muat ulang data"
                    aria-label="Reload Data"
                    @click="load()"
                >
                    <x-icon name="repeat" class="size-4" />
                </button>
            </div>
        </header>

        <section class="{{ $card }} performance-rise relative mt-5 overflow-hidden px-5 py-5 sm:px-6" style="--delay: 60ms" aria-labelledby="filter-title">
            @include('pages.performance.components.performance-filter')
        </section>

        <section aria-label="Key Performance Indicators" :aria-busy="loading.toString()">
            @foreach ($rows as $size => $row)
                <div class="mt-4 grid gap-4 {{ $row['grid'] }}">
                    @foreach ($row['metrics'] as $metric)
                        @include('pages.performance.components.performance-card', ['metric' => $metric, 'size' => $size, 'delay' => $delay])
                        @php($delay += 60)
                    @endforeach
                </div>
            @endforeach
        </section>

        <section class="{{ $card }} performance-rise relative mt-4 overflow-hidden p-5 sm:p-7" style="--delay: {{ $delay }}ms" aria-labelledby="performance-trend-title">
            @include('pages.performance.components.performance-trend')
        </section>

        <section class="{{ $card }} performance-rise relative mt-4 overflow-hidden p-5 sm:p-7" style="--delay: {{ $delay + 60 }}ms" aria-labelledby="performance-contribution-title">
            @include('pages.performance.components.performance-contribution')
        </section>

        <section class="{{ $card }} performance-rise relative mt-4 overflow-hidden p-5 sm:p-7" style="--delay: {{ $delay + 120 }}ms" aria-labelledby="performance-payment-method-title">
            @include('pages.performance.components.performance-payment-method')
        </section>
    </div>
@endsection
