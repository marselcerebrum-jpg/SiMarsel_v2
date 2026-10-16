@extends('layouts.dashboard')

@section('title', 'Manajemen Divisi')
@push('scripts')
    @vite('resources/js/pages/settings/divisions.js')
@endpush

@php
    $card = 'rounded-3xl border border-white/60 bg-surface shadow-[0_24px_60px_rgba(30,41,70,0.25)] dark:border-white/25 dark:bg-surface-dim';
    $canManage = auth()->user()->can('create', App\Models\Division::class);
@endphp

@section('page')
    <div x-data="divisionManagement(@js($canManage))" class="min-w-0">
        <header class="text-white">
            <p class="flex items-center gap-2 text-[11px] font-bold tracking-[.2em] text-white/75 uppercase">
                <span class="size-1.5 rounded-full bg-white/80" aria-hidden="true"></span>
                Sistem
            </p>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">Manajemen Divisi</h1>
            <p class="mt-1.5 text-sm text-white/80">
                {{ $canManage ? 'Kelola divisi dan lihat jumlah anggota di tiap divisi.' : 'Lihat daftar divisi dan jumlah anggotanya.' }}
            </p>
        </header>

        {{-- Ringkasan --}}
        <section class="mt-6 grid gap-4 sm:grid-cols-3" aria-label="Ringkasan divisi">
            <article class="{{ $card }} p-5 sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-[11px] font-bold tracking-widest text-label uppercase">Total Divisi</p>
                    <span class="grid size-10 place-items-center rounded-xl bg-brand-logo/10 text-brand-logo"><x-icon name="building" class="size-5" /></span>
                </div>
                <p class="-mt-1 text-3xl font-extrabold text-ink" x-text="loading ? '—' : divisions.length">—</p>
                <p class="mt-1 text-sm text-muted">Terdaftar di sistem</p>
            </article>

            <article class="{{ $card }} p-5 sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-[11px] font-bold tracking-widest text-label uppercase">Anggota Divisi</p>
                    <span class="grid size-10 place-items-center rounded-xl bg-brand-blue/10 text-brand-blue"><x-icon name="users" class="size-5" /></span>
                </div>
                <p class="-mt-1 text-3xl font-extrabold text-ink" x-text="loading ? '—' : totalMembers">—</p>
                <p class="mt-1 text-sm text-muted">Akun yang tergabung di divisi</p>
            </article>

            <article class="{{ $card }} p-5 sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-[11px] font-bold tracking-widest text-label uppercase">Divisi Kosong</p>
                    <span class="grid size-10 place-items-center rounded-xl bg-brand-teal/15 text-brand-teal"><x-icon name="user" class="size-5" /></span>
                </div>
                <p class="-mt-1 text-3xl font-extrabold text-ink" x-text="loading ? '—' : emptyDivisions">—</p>
                <p class="mt-1 text-sm text-muted">Belum memiliki anggota</p>
            </article>
        </section>

        <section class="{{ $card }} mt-6 p-5 sm:p-8" aria-labelledby="division-list-title">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="border-l-4 border-brand-indigo pl-4">
                    <p class="text-[11px] font-bold tracking-[.2em] text-label uppercase">Divisi Terdaftar</p>
                    <h2 id="division-list-title" class="mt-1 flex items-center gap-2 text-xl font-bold tracking-tight text-ink">
                        <x-icon name="list" class="size-5 text-brand-indigo" />
                        Daftar Divisi
                    </h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ $canManage ? 'Tambah, ubah, atau hapus divisi yang tidak lagi digunakan.' : 'Hanya manager yang dapat menambah, mengubah, atau menghapus divisi.' }}
                    </p>
                </div>

                @if ($canManage)
                    <button
                        type="button"
                        class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-linear-135 from-brand-indigo via-brand-blue to-brand-teal px-5 text-xs font-bold tracking-widest text-white uppercase shadow-[0_8px_18px_rgba(93,96,162,0.3)] [text-shadow:0_1px_2px_rgba(20,30,50,0.35)] hover:brightness-95"
                        @click="openCreate()"
                    >
                        <x-icon name="building" class="size-4" />
                        Buat Divisi
                    </button>
                @endif
            </div>

            @include('pages.settings.components.division-filter')
            @include('pages.settings.components.division-table')
        </section>

        @if ($canManage)
            @include('pages.settings.components.division-create-modal')
            @include('pages.settings.components.division-edit-modal')
            @include('pages.settings.components.division-delete-modal')
        @endif
    </div>
@endsection
