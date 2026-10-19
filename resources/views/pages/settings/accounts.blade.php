@extends('layouts.dashboard')

@section('title', 'Settings')
@push('scripts')
    @vite('resources/js/pages/settings/accounts.js')
@endpush

@php
    $card = 'rounded-panel border border-line bg-surface shadow-sm';
    $tileLabel = 'text-xs font-semibold text-label';
    $tileValue = 'mt-1 text-2xl font-bold text-ink';

    $roleHints = [
        'MGR' => 'Akses penuh',
        'EMP' => 'Anggota tim',
    ];

    // Every role can read the account list; only Manager can create, edit, and delete.
    $canManage = auth()->user()->can('create', App\Models\Account::class);
@endphp

@section('page')
    <div x-data="accountSettings(@js($roles), @js($divisions), @js($canManage))" class="min-w-0">
        <header>
            <h1 class="text-2xl font-bold tracking-tight text-ink">Settings</h1>
            <p class="mt-1 text-sm text-muted">
                {{ $canManage ? 'Kelola akun anggota tim beserta role dan divisinya.' : 'Lihat daftar akun anggota tim beserta role dan divisinya.' }}
            </p>
        </header>

        {{-- Ringkasan --}}
        <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Account Summary">
            <article class="{{ $card }} p-4">
                <p class="{{ $tileLabel }}">Total Accounts</p>
                <p class="{{ $tileValue }}" x-text="loading ? '—' : users.length">—</p>
                <p class="mt-1 text-sm text-muted">Terdaftar di sistem</p>
            </article>

            @foreach ($roles as $role)
                <article class="{{ $card }} p-4">
                    <p class="{{ $tileLabel }}">{{ $role->name }}</p>
                    <p class="{{ $tileValue }}" x-text="loading ? '—' : countByRole(@js($role->code_role))">—</p>
                    <p class="mt-1 text-sm text-muted">{{ $roleHints[$role->code_role] ?? 'Role '.$role->name }}</p>
                </article>
            @endforeach

            <article class="{{ $card }} p-4">
                <p class="{{ $tileLabel }}">Divisions</p>
                <p class="{{ $tileValue }}">{{ $divisions->count() }}</p>
                <p class="mt-1 text-sm text-muted">
                    <span x-text="loading ? '—' : countWithoutDivision()">—</span> akun tanpa divisi
                </p>
            </article>
        </section>

        <section class="{{ $card }} mt-6 p-5" aria-labelledby="account-list-title">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 id="account-list-title" class="text-base font-semibold text-ink">Account List</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ $canManage ? 'Tambah, ubah, atau hapus akun anggota tim beserta role dan divisinya.' : 'Cari akun anggota tim dan saring berdasarkan divisi.' }}
                    </p>
                </div>

                @if ($canManage)
                    <button
                        type="button"
                        class="inline-flex h-9 shrink-0 items-center justify-center gap-2 self-start rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
                        @click="openCreate()"
                    >
                        Create Account
                    </button>
                @endif
            </div>

            @include('pages.settings.components.account-filter')
            @include('pages.settings.components.account-table')
        </section>

        @if ($canManage)
            @include('pages.settings.components.account-create-modal')
            @include('pages.settings.components.account-edit-modal')
            @include('pages.settings.components.account-delete-modal')
        @endif
    </div>
@endsection
