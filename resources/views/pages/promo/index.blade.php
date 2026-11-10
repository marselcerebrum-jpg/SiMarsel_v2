@extends('layouts.dashboard')

@section('title', 'Manajemen Promo')
@push('scripts')
    @vite('resources/js/pages/promo/index.js')
@endpush

@php
    $card = 'rounded-panel border border-line bg-surface shadow-sm';
    $tabs = ['analysis' => 'Analisis', 'promo-codes' => 'Kelola Kode', 'master-data' => 'Data Master'];
@endphp

@section('page')
    <div x-data="promoManagement" class="min-w-0">
        <header>
            <h1 class="text-2xl font-bold tracking-tight text-ink">Manajemen Promo</h1>
            <p class="mt-1 text-sm text-muted">Analisis dan kelola kode promo beserta master funnel, source, dan platform.</p>
        </header>

        @include('pages.promo.components.promo-tabs', ['id' => 'promo', 'label' => 'Manajemen Promo', 'tabs' => $tabs])

        {{-- x-show keeps every panel mounted, so switching tabs keeps inputs, filters and loaded data. --}}
        <div id="panel-promo-analysis" role="tabpanel" aria-labelledby="tab-promo-analysis" tabindex="0" x-show="tab === 'analysis'" x-cloak class="focus-visible:outline-none">
            @include('pages.promo.components.promo-analysis-panel')
        </div>

        <div id="panel-promo-promo-codes" role="tabpanel" aria-labelledby="tab-promo-promo-codes" tabindex="0" x-show="tab === 'promo-codes'" x-cloak class="focus-visible:outline-none">
            @include('pages.promo.components.promo-code-panel')
        </div>

        <div id="panel-promo-master-data" role="tabpanel" aria-labelledby="tab-promo-master-data" tabindex="0" x-show="tab === 'master-data'" x-cloak class="focus-visible:outline-none">
            @include('pages.promo.components.master-data-panel')
        </div>
    </div>
@endsection
