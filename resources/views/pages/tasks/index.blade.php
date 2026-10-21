@extends('layouts.dashboard')

@section('title', 'Tasklist')
@push('scripts')
    @vite('resources/js/pages/tasks/index.js')
@endpush

@php
    $card = 'rounded-panel border border-line bg-surface shadow-sm';
    $button = 'inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50';
    $primaryButton = 'inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50';
    $badge = 'inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap';
@endphp

@section('page')
    <div x-data="tasklist" class="min-w-0">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold tracking-tight text-ink">Tasklist</h1>
                <p class="mt-0.5 text-sm text-muted">Pantau dan kelola tugas berdasarkan status, prioritas, dan deadline.</p>
            </div>

            <button x-show="canCreate" x-cloak type="button" class="{{ $primaryButton }}" @click="createOpen = true">
                <x-icon name="plus" class="size-4" />
                Create Task
            </button>
        </header>

        @include('pages.tasks.components.backend-notice')

        @include('pages.tasks.components.task-summary')

        <section class="{{ $card }} mt-4 p-3 sm:p-4" aria-label="Tampilan dan filter tugas">
            @include('pages.tasks.components.task-filter')
        </section>

        <div class="mt-4">
            <div x-show="view === 'board'">@include('pages.tasks.components.task-board')</div>
            <div x-show="view === 'table'" x-cloak>@include('pages.tasks.components.task-table')</div>
            <div x-show="view === 'timeline'" x-cloak>@include('pages.tasks.components.task-timeline')</div>
            <div x-show="view === 'calendar'" x-cloak>@include('pages.tasks.components.task-calendar')</div>
        </div>

        @include('pages.tasks.components.task-detail')
        @include('pages.tasks.components.task-create-modal')
    </div>
@endsection
