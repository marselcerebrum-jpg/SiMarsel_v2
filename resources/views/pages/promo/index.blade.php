@extends('layouts.dashboard')

@section('title', 'Promo Management')
@push('scripts')
    @vite('resources/js/pages/promo/index.js')
@endpush

@php
    $card = 'rounded-panel border border-line bg-surface shadow-sm';
    $tabs = ['promo-codes' => 'Promo Codes', 'master-data' => 'Master Data'];
@endphp

@section('page')
    <div x-data="promoManagement" class="min-w-0">
        <header>
            <h1 class="text-2xl font-bold tracking-tight text-ink">Promo Management</h1>
            <p class="mt-1 text-sm text-muted">Analisis dan kelola kode promo beserta master funnel, source, dan platform.</p>
        </header>

        @include('pages.promo.components.promo-tabs')

        <div id="panel-promo-codes" role="tabpanel" aria-labelledby="tab-promo-codes" tabindex="0" x-show="tab === 'promo-codes'" x-cloak class="focus-visible:outline-none">
            @include('pages.promo.components.promo-code-panel')
        </div>

        <div id="panel-master-data" role="tabpanel" aria-labelledby="tab-master-data" tabindex="0" x-show="tab === 'master-data'" x-cloak class="focus-visible:outline-none">
            @include('pages.promo.components.master-data-panel')
        </div>
    </div>
@endsection
