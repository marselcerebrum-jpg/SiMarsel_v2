@extends('layouts.dashboard')

@section('title', 'Product Overview')
@push('scripts')
    @vite('resources/js/pages/performance/products.js')
@endpush

@php
    $card = 'rounded-panel border border-line bg-surface shadow-sm';
@endphp

@section('page')
    <div x-data="productOverview" class="min-w-0">
        <header>
            <h1 class="text-2xl font-bold tracking-tight text-ink">Product Overview</h1>
            <p class="mt-1 text-sm text-muted">Pantau performa paket produk berdasarkan platform dan periode.</p>
        </header>

        <section class="{{ $card }} mt-6 p-5" aria-label="Filter ringkasan produk">
            @include('pages.performance.components.product-filter')
        </section>

        <div class="mt-4 grid gap-4">
            @include('pages.performance.components.bar-chart-card', [
                'component' => 'topPackagesByRevenue',
                'id' => 'top-revenue-title',
                'title' => 'Top Packages by Revenue',
                'placeholder' => 'bars-y',
                'emptyText' => 'Tidak ada data pendapatan paket',
                'chartLabel' => 'Diagram batang pendapatan per paket',
            ])

            @include('pages.performance.components.bar-chart-card', [
                'component' => 'topPackagesByVolume',
                'id' => 'top-volume-title',
                'title' => 'Top Packages by Transaction Volume',
                'placeholder' => 'bars-y',
                'emptyText' => 'Tidak ada data transaksi paket',
                'chartLabel' => 'Diagram batang jumlah transaksi per paket',
            ])
        </div>

        <div class="mt-4">
            @include('pages.performance.components.package-performance-table')
        </div>
    </div>
@endsection
