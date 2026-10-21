{{-- Calendar (TASK-11): agenda bersama lintas divisi + daftar deadline tugas sesuai hak akses. --}}
<section x-data="taskCalendar" class="{{ $card }} min-w-0 p-4 sm:p-5" aria-labelledby="calendar-title">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 id="calendar-title" class="text-base font-semibold capitalize text-ink" x-text="monthLabel"></h2>
            <p class="mt-1 text-sm text-muted" x-text="scopeLabel"></p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" class="{{ $button }} px-2.5" @click="shift(-1)" aria-label="Bulan sebelumnya"><x-icon name="chevron-left" /></button>
            <button type="button" class="{{ $button }}" @click="goToday()">Hari Ini</button>
            <button type="button" class="{{ $button }} px-2.5" @click="shift(1)" aria-label="Bulan berikutnya"><x-icon name="chevron-right" /></button>
        </div>
    </div>

    <div class="mt-4 grid grid-cols-7 gap-px overflow-hidden rounded-lg border border-line bg-line" role="grid" aria-labelledby="calendar-title">
        <template x-for="weekday in weekdays" :key="weekday">
            <span class="bg-soft py-2 text-center text-[11px] font-semibold text-label" role="columnheader" x-text="weekday"></span>
        </template>
        <template x-for="cell in cells" :key="cell.iso">
            <button
                type="button"
                role="gridcell"
                class="flex min-h-14 flex-col items-start gap-1 p-1.5 text-left focus-visible:relative focus-visible:outline-2 focus-visible:outline-primary sm:min-h-20 sm:p-2"
                :class="[
                    cell.inMonth ? 'bg-white' : 'bg-soft text-label',
                    selected === cell.iso ? 'ring-2 ring-primary ring-inset' : 'hover:bg-primary-soft/50',
                ]"
                :aria-selected="selected === cell.iso"
                :aria-label="`${formatDate(cell.iso)}: ${cell.events} agenda, ${cell.deadlines} deadline`"
                @click="selected = cell.iso"
            >
                <span class="grid size-6 place-items-center rounded-full text-xs font-semibold"
                    :class="cell.today ? 'bg-primary text-white' : (cell.inMonth ? 'text-ink' : 'text-label')" x-text="cell.day"></span>
                <span class="flex flex-wrap gap-1" x-show="state === 'ready'">
                    <span x-show="cell.events" class="inline-flex items-center gap-1 rounded bg-navy/10 px-1 text-[10px] font-semibold text-navy">
                        <span class="size-1.5 rounded-full bg-navy"></span><span x-text="cell.events"></span>
                    </span>
                    <span x-show="cell.deadlines" class="inline-flex items-center gap-1 rounded px-1 text-[10px] font-semibold"
                        :class="cell.overdue ? 'bg-red-50 text-red-700' : 'bg-primary-soft text-primary-hover'">
                        <span class="size-1.5 rounded-full" :class="cell.overdue ? 'bg-red-600' : 'bg-primary'"></span><span x-text="cell.deadlines"></span>
                    </span>
                </span>
            </button>
        </template>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-navy"></span> Agenda bersama</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-primary"></span> Deadline tugas</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-red-600"></span> Deadline terlewat (masih aktif)</span>
    </div>

    <div x-show="state === 'loading'" class="mt-4 h-24 animate-pulse rounded-lg bg-soft" aria-label="Memuat kalender"></div>

    <div x-show="state === 'unavailable'" x-cloak class="mt-4">
        @include('pages.tasks.components.pending', ['text' => 'Agenda bersama dan deadline tugas akan tampil setelah API Calendar tersedia.'])
    </div>

    <div x-show="state === 'error'" x-cloak class="mt-4 flex flex-col items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-8 text-center" role="alert">
        <p class="text-sm text-red-700" x-text="message"></p>
        <button type="button" class="{{ $button }}" @click="loadPeriod()">Try Again</button>
    </div>

    <div x-show="state === 'ready'" x-cloak class="mt-5 grid gap-4 lg:grid-cols-2">
        <div>
            <h3 class="text-sm font-semibold text-ink">Agenda Bersama · <span x-text="formatDate(selected)"></span></h3>
            <p class="mt-0.5 text-xs text-muted">Informasi umum kegiatan lintas divisi.</p>
            <ul class="mt-3 flex flex-col gap-2">
                <template x-for="event in selectedEvents" :key="event.id">
                    <li class="rounded-lg border border-line bg-white p-3">
                        <p class="text-sm font-semibold text-ink" x-text="event.title"></p>
                        <p class="mt-0.5 text-xs text-muted">
                            <span x-text="event.division?.name ?? 'Lintas divisi'"></span> ·
                            <span x-text="formatDate(event.start_date ?? event.date)"></span><span x-show="event.end_date && event.end_date !== event.start_date" x-text="` – ${formatDate(event.end_date)}`"></span>
                        </p>
                        <p x-show="event.description" class="mt-1.5 text-sm text-ink" x-text="event.description"></p>
                    </li>
                </template>
            </ul>
            <p x-show="!selectedEvents.length" class="mt-3 rounded-lg border border-dashed border-line bg-soft px-3 py-4 text-center text-sm text-muted">Tidak ada agenda bersama pada tanggal ini.</p>
        </div>

        <div>
            <h3 class="text-sm font-semibold text-ink">Deadline Tugas · <span x-text="formatDate(selected)"></span></h3>
            <p class="mt-0.5 text-xs text-muted">Hanya tugas yang dapat Anda akses.</p>
            <ul class="mt-3 flex flex-col gap-2">
                <template x-for="task in selectedDeadlines" :key="task.id">
                    <li>
                        <button type="button" class="w-full rounded-lg border bg-white p-3 text-left hover:border-primary/40 focus-visible:outline-2 focus-visible:outline-primary"
                            :class="isOverdue(task) ? 'border-red-200' : 'border-line'" @click="openTask(task)">
                            <span class="flex items-start justify-between gap-2">
                                <span class="text-sm font-semibold text-ink" x-text="task.title"></span>
                                <span x-show="isOverdue(task)" class="shrink-0 rounded bg-red-50 px-1.5 py-0.5 text-[10px] font-bold text-red-700 uppercase">Terlambat</span>
                            </span>
                            <span class="mt-1 block text-xs text-muted">
                                <span x-text="task.division?.name ?? '-'"></span> · PIC <span x-text="task.pic?.fullname ?? '-'"></span> · Deadline <span x-text="formatDate(task.deadline)"></span>
                            </span>
                            <span class="mt-2 flex flex-wrap gap-1.5">
                                <span class="{{ $badge }}" :class="priorityOf(task.priority).badge" x-text="priorityOf(task.priority).label"></span>
                                <span class="{{ $badge }}" :class="statusOf(task.status).badge" x-text="statusOf(task.status).label"></span>
                            </span>
                        </button>
                    </li>
                </template>
            </ul>
            <p x-show="!selectedDeadlines.length" class="mt-3 rounded-lg border border-dashed border-line bg-soft px-3 py-4 text-center text-sm text-muted">Tidak ada deadline tugas pada tanggal ini.</p>
        </div>
    </div>
</section>
