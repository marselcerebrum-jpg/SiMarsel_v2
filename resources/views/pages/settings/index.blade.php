@extends('layouts.dashboard')

@section('title', 'Pengaturan')
@push('scripts')
    @vite('resources/js/pages/settings/index.js')
@endpush

@php
    $card = 'rounded-3xl border border-white/60 bg-surface shadow-[0_24px_60px_rgba(30,41,70,0.25)] dark:border-white/25 dark:bg-surface-dim';

    $roleCards = [
        'MGR' => ['icon' => 'shield', 'hint' => 'Akses penuh', 'tone' => 'bg-brand-indigo/10 text-brand-indigo'],
        'EMP' => ['icon' => 'user', 'hint' => 'Anggota tim', 'tone' => 'bg-brand-teal/15 text-brand-teal'],
    ];

    $tabs = [
        'create' => ['label' => 'Buat Akun', 'icon' => 'user-plus'],
        'list' => ['label' => 'Daftar Akun', 'icon' => 'list'],
    ];
@endphp

@section('page')
    <div x-data="accountSettings(@js($roles), @js($divisions))" class="min-w-0">
        <header class="text-white">
            <p class="flex items-center gap-2 text-[11px] font-bold tracking-[.2em] text-white/75 uppercase">
                <span class="size-1.5 rounded-full bg-white/80" aria-hidden="true"></span>
                Sistem
            </p>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">Pengaturan</h1>
            <p class="mt-1.5 text-sm text-white/80">Kelola akun anggota tim beserta role dan divisinya.</p>
        </header>

        {{-- Ringkasan --}}
        <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Ringkasan akun">
            <article class="{{ $card }} p-5 sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-[11px] font-bold tracking-widest text-label uppercase">Total Akun</p>
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
                    <p class="text-[11px] font-bold tracking-widest text-label uppercase">Divisi</p>
                    <span class="grid size-10 place-items-center rounded-xl bg-brand-logo/10 text-brand-logo"><x-icon name="building" class="size-5" /></span>
                </div>
                <p class="-mt-1 text-3xl font-extrabold text-ink">{{ $divisions->count() }}</p>
                <p class="mt-1 text-sm text-muted">
                    <span x-text="loading ? '—' : countWithoutDivision()">—</span> akun tanpa divisi
                </p>
            </article>
        </section>

        {{-- Tab --}}
        <div class="mt-6 inline-flex max-w-full gap-1 overflow-x-auto rounded-2xl border border-white/30 bg-white/15 p-1.5 backdrop-blur" role="tablist" aria-label="Menu pengaturan">
            @foreach ($tabs as $key => $tab)
                <button
                    type="button"
                    role="tab"
                    id="tab-{{ $key }}"
                    aria-controls="panel-{{ $key }}"
                    :aria-selected="(tab === @js($key)).toString()"
                    class="inline-flex h-11 shrink-0 items-center gap-2 rounded-xl px-5 text-xs font-bold tracking-widest uppercase transition"
                    :class="tab === @js($key) ? 'bg-surface text-brand-indigo shadow-lg' : 'text-white/85 hover:bg-white/10 hover:text-white'"
                    @click="tab = @js($key)"
                >
                    <x-icon :name="$tab['icon']" class="size-4" />
                    {{ $tab['label'] }}
                </button>
            @endforeach
        </div>

        <section id="panel-create" role="tabpanel" aria-labelledby="tab-create" x-show="tab === 'create'" class="{{ $card }} mt-4 p-5 sm:p-8">
            @include('pages.settings.components.account-create-form')
        </section>

        <section id="panel-list" role="tabpanel" aria-labelledby="tab-list" x-show="tab === 'list'" x-cloak class="{{ $card }} mt-4 p-5 sm:p-8">
            <div class="border-l-4 border-brand-indigo pl-4">
                <p class="text-[11px] font-bold tracking-[.2em] text-label uppercase">Akun Terdaftar</p>
                <h2 class="mt-1 flex items-center gap-2 text-xl font-bold tracking-tight text-ink">
                    <x-icon name="list" class="size-5 text-brand-indigo" />
                    Daftar Akun
                </h2>
                <p class="mt-1 text-sm text-muted">Ubah data, role, dan divisi akun, atau hapus akun yang tidak lagi digunakan.</p>
            </div>

            @include('pages.settings.components.account-filter')
            @include('pages.settings.components.account-table')
        </section>

        @include('pages.settings.components.account-edit-modal')
        @include('pages.settings.components.account-delete-modal')
    </div>
@endsection
