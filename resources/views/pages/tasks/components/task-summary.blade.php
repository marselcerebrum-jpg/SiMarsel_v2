{{-- Ringkasan TASK-09: dihitung dari daftar tugas yang sama dengan Board/Table dan mengikuti filter aktif. --}}
@php
    $headline = [
        ['key' => 'total', 'label' => 'Total Tasks', 'tone' => 'text-ink'],
        ['key' => 'completed', 'label' => 'Completed', 'tone' => 'text-emerald-700'],
        ['key' => 'unfinished', 'label' => 'Unfinished', 'tone' => 'text-ink'],
        ['key' => 'overdue', 'label' => 'Overdue', 'tone' => 'text-red-700'],
        ['key' => 'due_today', 'label' => 'Due Today', 'tone' => 'text-amber-700'],
        ['key' => 'urgent', 'label' => 'Urgent', 'tone' => 'text-red-700'],
    ];
    $byStatus = [
        ['key' => 'backlog', 'label' => 'Back Log'],
        ['key' => 'todo', 'label' => 'To Do'],
        ['key' => 'in_progress', 'label' => 'On Process'],
        ['key' => 'ready_for_qa', 'label' => 'Ready for QA'],
        ['key' => 'on_qa', 'label' => 'On QA'],
    ];
@endphp

<section class="mt-6" aria-labelledby="task-summary-title">
    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
        <h2 id="task-summary-title" class="text-base font-semibold text-ink">Ringkasan Tugas</h2>
        <p class="text-xs text-muted">
            Cakupan: <span class="font-semibold text-ink" x-text="scopeLabel"></span>
            <span x-show="hasActiveFilter" x-cloak>· mengikuti filter aktif</span>
        </p>
    </div>

    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
        @foreach ($headline as $metric)
            <div class="{{ $card }} p-4">
                <p class="text-xs font-semibold text-label">{{ $metric['label'] }}</p>
                <p class="mt-2 text-2xl font-bold tabular-nums {{ $metric['tone'] }}" aria-live="polite"
                    x-text="status === 'ready' ? summary.{{ $metric['key'] }} : '—'">—</p>
                @if ($metric['key'] === 'completed')
                    <p class="mt-1 text-xs text-muted">
                        Completion rate <span class="font-semibold text-ink" x-text="status === 'ready' ? `${summary.completion_rate.toLocaleString('id-ID')}%` : '—'"></span>
                    </p>
                @elseif ($metric['key'] === 'overdue')
                    <p class="mt-1 text-xs text-muted">Aktif & lewat deadline</p>
                @elseif ($metric['key'] === 'urgent')
                    <p class="mt-1 text-xs text-muted">Aktif berprioritas Urgent</p>
                @elseif ($metric['key'] === 'unfinished')
                    <p class="mt-1 text-xs text-muted">Status selain Done</p>
                @elseif ($metric['key'] === 'due_today')
                    <p class="mt-1 text-xs text-muted">Deadline hari ini</p>
                @else
                    <p class="mt-1 text-xs text-muted">Dalam cakupan data</p>
                @endif
            </div>
        @endforeach
    </div>

    <div class="{{ $card }} mt-3 grid grid-cols-2 divide-line sm:grid-cols-5 sm:divide-x">
        @foreach ($byStatus as $metric)
            <div class="flex items-center justify-between gap-2 px-4 py-3">
                <span class="inline-flex items-center gap-2 text-xs font-semibold text-label">
                    <span class="size-2 rounded-full" :class="statusOf('{{ $metric['key'] }}').dot" aria-hidden="true"></span>
                    {{ $metric['label'] }}
                </span>
                <span class="text-base font-bold text-ink tabular-nums" x-text="status === 'ready' ? summary.{{ $metric['key'] }} : '—'">—</span>
            </div>
        @endforeach
    </div>

    <p x-show="status === 'error'" x-cloak class="mt-2 text-xs text-red-600">Ringkasan tidak dapat dihitung karena daftar tugas gagal dimuat.</p>
</section>
