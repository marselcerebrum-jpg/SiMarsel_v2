@extends('layouts.dashboard')

@section('title', 'Ringkasan Produk')
@push('scripts')
    @vite('resources/js/pages/performance/products.js')
@endpush

@php
    $card = 'rounded-3xl border border-white/60 bg-surface shadow-[0_24px_60px_rgba(30,41,70,0.25)] dark:border-white/25 dark:bg-surface-dim';
@endphp

@section('page')
    <div x-data="productOverview" class="min-w-0">
        <header class="text-white">
            <p class="flex items-center gap-2 text-[11px] font-bold tracking-[.2em] text-white/75 uppercase">
                <span class="size-1.5 rounded-full bg-white/80" aria-hidden="true"></span>
                Analisis Performa
            </p>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">Ringkasan Produk</h1>
            <p class="mt-1.5 text-sm text-white/80">Pantau performa paket produk berdasarkan platform dan periode.</p>
        </header>

        <section class="{{ $card }} mt-6 p-5 sm:p-6" aria-label="Filter ringkasan produk">
            @include('pages.performance.components.product-filter')
        </section>

        <div class="mt-4 grid gap-4 xl:grid-cols-2">
            @include('pages.performance.components.bar-chart-card', [
                'component' => 'topPackagesByRevenue',
                'id' => 'top-revenue-title',
                'eyebrow' => 'Pendapatan Paket',
                'title' => 'Top Paket Berdasarkan Pendapatan',
                'icon' => 'chart',
                'placeholder' => 'bars-x',
                'emptyText' => 'Tidak ada data pendapatan paket',
                'chartLabel' => 'Diagram batang pendapatan per paket',
            ])

            @include('pages.performance.components.bar-chart-card', [
                'component' => 'topPackagesByVolume',
                'id' => 'top-volume-title',
                'eyebrow' => 'Volume Transaksi',
                'title' => 'Top Paket Berdasarkan Volume Transaksi',
                'icon' => 'ticket',
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
