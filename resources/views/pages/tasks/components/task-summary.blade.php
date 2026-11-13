{{-- Ringkasan TASK-09: empat metrik utama, jumlah tiap status, dan informasi tambahan. Berada di atas semua
     tampilan sehingga tetap terlihat di Papan, Tabel, Linimasa, dan Kalender.
     Angka dari GET /api/tasks/monitoring (scope=tasklist) dengan cakupan dan filter yang sama dengan Papan/Tabel;
     selama dimuat atau gagal, angka tampil "—". --}}
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
                    :class="summaryState === 'loading' && 'animate-pulse text-label!'"
                    x-text="metric('{{ $metric['key'] }}')">—</dd>
            </div>
        @endforeach
    </dl>

    <dl class="mt-2 flex flex-wrap gap-1.5" data-status-summary aria-label="Jumlah tugas per status">
        <template x-for="item in statusSummary" :key="item.key">
            <div class="inline-flex items-center gap-1.5 rounded-md border border-line bg-surface px-2 py-1 text-xs" :data-status-metric="item.key">
                <span class="size-2 shrink-0 rounded-full" :class="item.dot" aria-hidden="true"></span>
                <dt class="text-muted" x-text="item.label"></dt>
                <dd class="font-semibold text-ink tabular-nums" :class="summaryState === 'loading' && 'animate-pulse text-label!'" x-text="item.value"></dd>
            </div>
        </template>
    </dl>

    <div class="mt-1.5 flex flex-wrap items-center justify-between gap-x-4 gap-y-0.5 text-xs text-muted">
        <p class="min-w-0 truncate">
            Cakupan: <span class="font-semibold text-ink" x-text="scopeLabel"></span><span x-show="hasActiveFilter" x-cloak> · mengikuti filter aktif</span>
        </p>
        <p x-show="summaryState !== 'error'">
            <span x-show="summaryState === 'loading'" x-cloak class="mr-2 font-semibold text-label">Memuat ringkasan…</span>
            Completed <span class="font-semibold text-ink tabular-nums" x-text="metric('completed')">—</span>
            <span class="text-line">|</span> Belum Selesai <span class="font-semibold text-ink tabular-nums" x-text="metric('unfinished')">—</span>
            <span class="text-line">|</span> Urgent <span class="font-semibold text-red-700 tabular-nums" x-text="metric('urgent')">—</span>
            <span class="text-line">|</span> Tingkat penyelesaian <span class="font-semibold text-ink tabular-nums" x-text="completionRateText">—</span>
        </p>
        <p x-show="summaryState === 'error'" x-cloak class="text-red-600" role="alert">
            <span x-text="summaryError"></span>
            <button type="button" class="ml-1 font-semibold underline" @click="loadSummary()">Coba lagi</button>
        </p>
    </div>
</section>
