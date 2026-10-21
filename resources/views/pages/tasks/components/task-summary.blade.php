{{-- Ringkasan TASK-09: empat metrik utama + informasi tambahan. Dihitung dari daftar tugas yang sama
     dengan Board/Table dan mengikuti filter aktif. Jumlah per status ada di kolom Board. --}}
@php
    $primary = [
        ['key' => 'total', 'label' => 'Total Tasks', 'hint' => 'Dalam cakupan & filter', 'tone' => 'text-ink'],
        ['key' => 'in_progress', 'label' => 'In Progress', 'hint' => 'Status On Process', 'tone' => 'text-amber-700'],
        ['key' => 'overdue', 'label' => 'Overdue', 'hint' => 'Aktif & lewat deadline', 'tone' => 'text-red-700'],
        ['key' => 'due_today', 'label' => 'Due Today', 'hint' => 'Deadline hari ini', 'tone' => 'text-sky-700'],
    ];
@endphp

<section class="mt-5" aria-labelledby="task-summary-title">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 id="task-summary-title" class="sr-only">Ringkasan Tugas</h2>
        <p class="text-xs text-muted">
            Cakupan: <span class="font-semibold text-ink" x-text="scopeLabel"></span><span x-show="hasActiveFilter" x-cloak> · mengikuti filter aktif</span>
        </p>
        <p class="text-xs text-muted" x-show="status === 'ready'" x-cloak>
            Completed <span class="font-semibold text-ink tabular-nums" x-text="summary.completed"></span>
            <span class="text-line">|</span> Unfinished <span class="font-semibold text-ink tabular-nums" x-text="summary.unfinished"></span>
            <span class="text-line">|</span> Urgent <span class="font-semibold text-red-700 tabular-nums" x-text="summary.urgent"></span>
            <span class="text-line">|</span> Completion rate <span class="font-semibold text-ink tabular-nums" x-text="`${summary.completion_rate.toLocaleString('id-ID')}%`"></span>
        </p>
    </div>

    <div class="mt-2 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($primary as $metric)
            <div class="{{ $card }} flex items-center justify-between gap-3 px-4 py-3" data-metric="{{ $metric['key'] }}">
                <div class="min-w-0">
                    <p class="truncate text-xs font-semibold text-label">{{ $metric['label'] }}</p>
                    <p class="truncate text-[11px] text-muted">{{ $metric['hint'] }}</p>
                </div>
                <p class="text-2xl font-bold tabular-nums {{ $metric['tone'] }}" aria-live="polite"
                    x-text="status === 'ready' ? summary.{{ $metric['key'] }} : '—'">—</p>
            </div>
        @endforeach
    </div>

    <p x-show="status === 'error'" x-cloak class="mt-2 text-xs text-red-600">Ringkasan tidak dapat dihitung karena daftar tugas gagal dimuat.</p>
</section>
