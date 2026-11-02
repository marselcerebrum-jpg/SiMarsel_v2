@extends('layouts.dashboard')

@section('title', 'Organization Management')
@push('scripts')
    @vite('resources/js/pages/settings/index.js')
@endpush

@php
    $tabs = ['accounts' => 'Accounts', 'divisions' => 'Divisions'];
@endphp

@section('page')
    <div x-data="organizationManagement(@js($initialTab))" class="min-w-0">
        <header>
            <h1 class="text-2xl font-bold tracking-tight text-ink">Organization Management</h1>
            <p class="mt-1 text-sm text-muted">Kelola akun pengguna dan divisi organisasi.</p>
        </header>

        @include('pages.settings.components.organization-tabs')

        <div id="panel-accounts" role="tabpanel" aria-labelledby="tab-accounts" tabindex="0" x-show="tab === 'accounts'" x-cloak class="focus-visible:outline-none">
            @include('pages.settings.accounts')
        </div>

        <div id="panel-divisions" role="tabpanel" aria-labelledby="tab-divisions" tabindex="0" x-show="tab === 'divisions'" x-cloak class="focus-visible:outline-none">
            @include('pages.settings.divisions')
        </div>
    </div>
@endsection
