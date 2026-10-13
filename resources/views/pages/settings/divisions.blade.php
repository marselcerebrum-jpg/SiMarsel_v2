@extends('layouts.dashboard')

@section('title', 'Manajemen Divisi')
@push('scripts')
    @vite('resources/js/pages/settings/divisions.js')
@endpush

@php
    $card = 'rounded-3xl border border-white/60 bg-surface shadow-[0_24px_60px_rgba(30,41,70,0.25)] dark:border-white/25 dark:bg-surface-dim';
    $canManage = auth()->user()->can('create', App\Models\Division::class);

    $tabs = [
        'create' => ['label' => 'Buat Divisi', 'icon' => 'building'],
        'list' => ['label' => 'Daftar Divisi', 'icon' => 'list'],
    ];
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

        @if ($canManage)
            {{-- Tab --}}
            <div class="mt-6 inline-flex max-w-full gap-1 overflow-x-auto rounded-2xl border border-white/30 bg-white/15 p-1.5 backdrop-blur" role="tablist" aria-label="Menu divisi">
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
                @include('pages.settings.components.division-create-form')
            </section>
        @endif

        <section
            id="panel-list"
            @if ($canManage) role="tabpanel" aria-labelledby="tab-list" x-show="tab === 'list'" x-cloak @endif
            class="{{ $card }} {{ $canManage ? 'mt-4' : 'mt-6' }} p-5 sm:p-8"
        >
            <div class="border-l-4 border-brand-indigo pl-4">
                <p class="text-[11px] font-bold tracking-[.2em] text-label uppercase">Divisi Terdaftar</p>
                <h2 class="mt-1 flex items-center gap-2 text-xl font-bold tracking-tight text-ink">
                    <x-icon name="list" class="size-5 text-brand-indigo" />
                    Daftar Divisi
                </h2>
                <p class="mt-1 text-sm text-muted">
                    {{ $canManage ? 'Ubah kode dan nama divisi, atau hapus divisi yang tidak lagi digunakan.' : 'Hanya manager yang dapat menambah, mengubah, atau menghapus divisi.' }}
                </p>
            </div>

            @include('pages.settings.components.division-filter')
            @include('pages.settings.components.division-table')
        </section>

        @if ($canManage)
            @include('pages.settings.components.division-edit-modal')
            @include('pages.settings.components.division-delete-modal')
        @endif
    </div>
@endsection
