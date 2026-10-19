@extends('layouts.dashboard')

@section('title', 'Division Management')
@push('scripts')
    @vite('resources/js/pages/settings/divisions.js')
@endpush

@php
    $card = 'rounded-panel border border-line bg-surface shadow-sm';
    $tileLabel = 'text-xs font-semibold text-label';
    $tileValue = 'mt-1 text-2xl font-bold text-ink';
    $canManage = auth()->user()->can('create', App\Models\Division::class);
@endphp

@section('page')
    <div x-data="divisionManagement(@js($canManage))" class="min-w-0">
        <header>
            <h1 class="text-2xl font-bold tracking-tight text-ink">Division Management</h1>
            <p class="mt-1 text-sm text-muted">
                {{ $canManage ? 'Kelola divisi dan lihat jumlah anggota di tiap divisi.' : 'Lihat daftar divisi dan jumlah anggotanya.' }}
            </p>
        </header>

        {{-- Ringkasan --}}
        <section class="mt-6 grid gap-4 sm:grid-cols-3" aria-label="Division Summary">
            <article class="{{ $card }} p-4">
                <p class="{{ $tileLabel }}">Total Divisions</p>
                <p class="{{ $tileValue }}" x-text="loading ? '—' : divisions.length">—</p>
                <p class="mt-1 text-sm text-muted">Terdaftar di sistem</p>
            </article>

            <article class="{{ $card }} p-4">
                <p class="{{ $tileLabel }}">Division Members</p>
                <p class="{{ $tileValue }}" x-text="loading ? '—' : totalMembers">—</p>
                <p class="mt-1 text-sm text-muted">Akun yang tergabung di divisi</p>
            </article>

            <article class="{{ $card }} p-4">
                <p class="{{ $tileLabel }}">Empty Divisions</p>
                <p class="{{ $tileValue }}" x-text="loading ? '—' : emptyDivisions">—</p>
                <p class="mt-1 text-sm text-muted">Belum memiliki anggota</p>
            </article>
        </section>

        <section class="{{ $card }} mt-6 p-5" aria-labelledby="division-list-title">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 id="division-list-title" class="text-base font-semibold text-ink">Division List</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ $canManage ? 'Tambah, ubah, atau hapus divisi yang tidak lagi digunakan.' : 'Hanya manager yang dapat menambah, mengubah, atau menghapus divisi.' }}
                    </p>
                </div>

                @if ($canManage)
                    <button
                        type="button"
                        class="inline-flex h-9 shrink-0 items-center justify-center gap-2 self-start rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
                        @click="openCreate()"
                    >
                        Create Division
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
