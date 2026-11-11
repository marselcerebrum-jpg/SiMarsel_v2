{{-- Ringkasan TASK-09: empat metrik utama dalam satu baris ringkas + informasi tambahan satu baris.
     Angka dari GET /api/tasks/monitoring (scope=tasklist) dengan cakupan dan filter yang sama dengan Papan/Tabel.
     Jumlah per status hanya tampil di kolom Board agar tidak berulang. --}}
@php
    $primary = [
        ['key' => 'total_tasks', 'label' => 'Total Tugas', 'hint' => 'Semua tugas dalam cakupan & filter', 'tone' => 'text-ink'],
        ['key' => 'on_process', 'label' => 'Sedang Berjalan', 'hint' => 'Tugas berstatus On Process', 'tone' => 'text-amber-700'],
        ['key' => 'overdue', 'label' => 'Terlambat', 'hint' => 'Tugas aktif yang melewati deadline', 'tone' => 'text-red-700'],
        ['key' => 'due_today', 'label' => 'Jatuh Tempo Hari Ini', 'hint' => 'Tugas aktif dengan deadline hari ini', 'tone' => 'text-sky-700'],
    ];
@endphp

<section class="mt-3" aria-labelledby="task-summary-title">
    <h2 id="task-summary-title" class="sr-only">Ringkasan Tugas</h2>

    <dl class="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4" :aria-busy="summaryState === 'loading'">
        @foreach ($primary as $metric)
            <div class="{{ $card }} flex min-w-0 items-center justify-between gap-2 px-3 py-2 sm:px-4" data-metric="{{ $metric['key'] }}" title="{{ $metric['hint'] }}">
                <dt class="truncate text-xs font-semibold text-label">{{ $metric['label'] }}</dt>
                <dd class="text-xl font-bold tabular-nums {{ $metric['tone'] }}" aria-live="polite"
                    :class="summaryState === 'loading' && 'animate-pulse opacity-50'"
                    x-text="monitoring && summaryState !== 'error' ? summary.{{ $metric['key'] }} : '—'">—</dd>
            </div>
        @endforeach
    </dl>

    <div class="mt-1.5 flex flex-wrap items-center justify-between gap-x-4 gap-y-0.5 text-xs text-muted">
        <p class="min-w-0 truncate">
            Cakupan: <span class="font-semibold text-ink" x-text="scopeLabel"></span><span x-show="hasActiveFilter" x-cloak> · mengikuti filter aktif</span>
        </p>
        <p x-show="monitoring && summaryState !== 'error'" x-cloak>
            Completed <span class="font-semibold text-ink tabular-nums" x-text="summary.completed"></span>
            <span class="text-line">|</span> Belum Selesai <span class="font-semibold text-ink tabular-nums" x-text="summary.unfinished"></span>
            <span class="text-line">|</span> Urgent <span class="font-semibold text-red-700 tabular-nums" x-text="summary.urgent"></span>
            <span class="text-line">|</span> Tingkat penyelesaian <span class="font-semibold text-ink tabular-nums" x-text="`${(summary.completion_rate ?? 0).toLocaleString('id-ID')}%`"></span>
        </p>
        <p x-show="summaryState === 'loading' && !monitoring" x-cloak>Memuat ringkasan…</p>
        <p x-show="summaryState === 'error'" x-cloak class="text-red-600" role="alert">
            <span x-text="summaryError"></span>
            <button type="button" class="ml-1 font-semibold underline" @click="loadSummary()">Coba lagi</button>
        </p>
    </div>
</section>
