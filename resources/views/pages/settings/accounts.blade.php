@extends('layouts.dashboard')

@section('title', 'Settings')
@push('scripts')
    @vite('resources/js/pages/settings/accounts.js')
@endpush

@php
    $card = 'rounded-3xl border border-white/60 bg-surface shadow-[0_24px_60px_rgba(30,41,70,0.25)] dark:border-white/25 dark:bg-surface-dim';

    $roleCards = [
        'MGR' => ['icon' => 'shield', 'hint' => 'Akses penuh', 'tone' => 'bg-brand-indigo/10 text-brand-indigo'],
        'EMP' => ['icon' => 'user', 'hint' => 'Anggota tim', 'tone' => 'bg-brand-teal/15 text-brand-teal'],
    ];

    // Every role can read the account list; only Manager can create, edit, and delete.
    $canManage = auth()->user()->can('create', App\Models\Account::class);
@endphp

@section('page')
    <div x-data="accountSettings(@js($roles), @js($divisions), @js($canManage))" class="min-w-0">
        <header class="text-white">
            <p class="flex items-center gap-2 text-[11px] font-bold tracking-[.2em] text-white/75 uppercase">
                <span class="size-1.5 rounded-full bg-white/80" aria-hidden="true"></span>
                System
            </p>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">Settings</h1>
            <p class="mt-1.5 text-sm text-white/80">
                {{ $canManage ? 'Kelola akun anggota tim beserta role dan divisinya.' : 'Lihat daftar akun anggota tim beserta role dan divisinya.' }}
            </p>
        </header>

        {{-- Ringkasan --}}
        <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Account Summary">
            <article class="{{ $card }} p-5 sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-[11px] font-bold tracking-widest text-label uppercase">Total Accounts</p>
                    <span class="grid size-10 place-items-center rounded-xl bg-brand-blue/10 text-brand-blue"><x-icon name="users" class="size-5" /></span>
                </div>
                <p class="-mt-1 text-3xl font-extrabold text-ink" x-text="loading ? '—' : users.length">—</p>
                <p class="mt-1 text-sm text-muted">Terdaftar di sistem</p>
            </article>

            @foreach ($roles as $role)
                @php
                    $meta = $roleCards[$role->code_role] ?? ['icon' => 'user', 'hint' => 'Role '.$role->name, 'tone' => 'bg-soft text-muted'];
                @endphp
                <article class="{{ $card }} p-5 sm:p-6">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-[11px] font-bold tracking-widest text-label uppercase">{{ $role->name }}</p>
                        <span class="grid size-10 place-items-center rounded-xl {{ $meta['tone'] }}"><x-icon :name="$meta['icon']" class="size-5" /></span>
                    </div>
                    <p class="-mt-1 text-3xl font-extrabold text-ink" x-text="loading ? '—' : countByRole(@js($role->code_role))">—</p>
                    <p class="mt-1 text-sm text-muted">{{ $meta['hint'] }}</p>
                </article>
            @endforeach

            <article class="{{ $card }} p-5 sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-[11px] font-bold tracking-widest text-label uppercase">Divisions</p>
                    <span class="grid size-10 place-items-center rounded-xl bg-brand-logo/10 text-brand-logo"><x-icon name="building" class="size-5" /></span>
                </div>
                <p class="-mt-1 text-3xl font-extrabold text-ink">{{ $divisions->count() }}</p>
                <p class="mt-1 text-sm text-muted">
                    <span x-text="loading ? '—' : countWithoutDivision()">—</span> akun tanpa divisi
                </p>
            </article>
        </section>

        <section class="{{ $card }} mt-6 p-5 sm:p-8" aria-labelledby="account-list-title">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="border-l-4 border-brand-indigo pl-4">
                    <p class="text-[11px] font-bold tracking-[.2em] text-label uppercase">Registered Accounts</p>
                    <h2 id="account-list-title" class="mt-1 flex items-center gap-2 text-xl font-bold tracking-tight text-ink">
                        <x-icon name="list" class="size-5 text-brand-indigo" />
                        Account List
                    </h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ $canManage ? 'Tambah, ubah, atau hapus akun anggota tim beserta role dan divisinya.' : 'Cari akun anggota tim dan saring berdasarkan divisi.' }}
                    </p>
                </div>

                @if ($canManage)
                    <button
                        type="button"
                        class="inline-flex h-11 shrink-0 items-center justify-center gap-2 self-start rounded-xl bg-linear-135 from-brand-indigo via-brand-blue to-brand-teal px-5 text-xs font-bold tracking-widest text-white uppercase shadow-[0_8px_18px_rgba(93,96,162,0.3)] [text-shadow:0_1px_2px_rgba(20,30,50,0.35)] transition hover:brightness-95"
                        @click="openCreate()"
                    >
                        <x-icon name="user-plus" class="size-4" />
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
