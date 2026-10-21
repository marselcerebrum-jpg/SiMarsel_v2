{{-- Calendar (TASK-11): agenda bersama lintas divisi + deadline tugas sesuai hak akses.
     Memilih item menampilkan ringkasan; "Lihat Detail" pada tugas membuka Board dan panel detailnya. --}}
@php
    $summaryRow = 'flex gap-3 text-sm';
    $summaryLabel = 'w-28 shrink-0 text-xs font-semibold text-label';
@endphp

<section x-data="taskCalendar" class="{{ $card }} min-w-0 p-4 sm:p-5" aria-labelledby="calendar-title">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 id="calendar-title" class="text-base font-semibold text-ink capitalize" x-text="monthLabel"></h2>
            <p class="mt-0.5 text-sm text-muted" x-text="scopeLabel"></p>
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
                <span class="flex w-full flex-col gap-0.5" x-show="state === 'ready'">
                    <span x-show="cell.events" class="inline-flex max-w-full items-center gap-1 truncate rounded bg-navy px-1 text-[10px] font-semibold text-white">
                        <span x-text="cell.events"></span><span class="hidden sm:inline">agenda</span>
                    </span>
                    <span x-show="cell.deadlines" class="inline-flex max-w-full items-center gap-1 truncate rounded px-1 text-[10px] font-semibold text-white"
                        :class="cell.overdue ? 'bg-red-600' : 'bg-primary'">
                        <span x-text="cell.deadlines"></span><span class="hidden sm:inline">deadline</span>
                    </span>
                </span>
            </button>
        </template>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-navy"></span> Agenda bersama</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-primary"></span> Deadline tugas</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-red-600"></span> Ada deadline terlewat (masih aktif)</span>
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
            <p class="mt-0.5 text-xs text-muted">Informasi umum kegiatan lintas divisi. Pilih agenda untuk melihat ringkasannya.</p>
            <ul class="mt-3 flex flex-col gap-2">
                <template x-for="event in selectedEvents" :key="event.id">
                    <li>
                        <button type="button" class="w-full rounded-lg border bg-white p-3 text-left hover:border-navy/40 focus-visible:outline-2 focus-visible:outline-primary"
                            :class="isPicked('event', event) ? 'border-navy ring-1 ring-navy' : 'border-line'" :aria-expanded="isPicked('event', event)" @click="pick('event', event)">
                            <span class="block text-sm font-semibold break-words text-ink" x-text="event.title"></span>
                            <span class="mt-0.5 block text-xs text-muted" x-text="event.division?.name ?? 'Lintas divisi'"></span>
                        </button>
                    </li>
                </template>
            </ul>
            <p x-show="!selectedEvents.length" class="mt-3 rounded-lg border border-dashed border-line bg-soft px-3 py-4 text-center text-sm text-muted">Tidak ada agenda bersama pada tanggal ini.</p>
        </div>

        <div>
            <h3 class="text-sm font-semibold text-ink">Deadline Tugas · <span x-text="formatDate(selected)"></span></h3>
            <p class="mt-0.5 text-xs text-muted">Hanya tugas yang dapat Anda akses. Pilih tugas untuk melihat ringkasannya.</p>
            <ul class="mt-3 flex flex-col gap-2">
                <template x-for="task in selectedDeadlines" :key="task.id">
                    <li>
                        <button type="button" class="w-full rounded-lg border bg-white p-3 text-left hover:border-primary/40 focus-visible:outline-2 focus-visible:outline-primary"
                            :class="isPicked('task', task) ? 'border-primary ring-1 ring-primary' : (isOverdue(task) ? 'border-red-200' : 'border-line')"
                            :aria-expanded="isPicked('task', task)" @click="pick('task', task)">
                            <span class="flex items-start justify-between gap-2">
                                <span class="text-sm font-semibold break-words text-ink" x-text="task.title"></span>
                                <span x-show="isOverdue(task)" class="shrink-0 rounded bg-red-50 px-1.5 py-0.5 text-[10px] font-bold text-red-700 uppercase">Terlambat</span>
                            </span>
                            <span class="mt-1 block truncate text-xs text-muted">
                                <span x-text="task.division?.name ?? '-'"></span> · PIC <span x-text="task.pic?.fullname ?? '-'"></span>
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

        {{-- Ringkasan item yang dipilih --}}
        <template x-if="picked">
            <div class="rounded-lg border p-4 lg:col-span-2" :class="picked.kind === 'task' ? 'border-primary/40 bg-primary-soft/40' : 'border-navy/30 bg-soft'" data-testid="calendar-summary" role="region" aria-label="Ringkasan item terpilih">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[11px] font-bold tracking-wide text-label uppercase" x-text="picked.kind === 'task' ? 'Ringkasan Tugas' : 'Ringkasan Agenda Bersama'"></p>
                        <h4 class="mt-0.5 text-base font-semibold break-words text-ink" x-text="picked.item.title"></h4>
                    </div>
                    <button type="button" class="grid size-7 shrink-0 place-items-center rounded-lg text-muted hover:bg-white hover:text-ink" @click="picked = null" aria-label="Tutup ringkasan">
                        <x-icon name="close" class="size-4" />
                    </button>
                </div>

                <dl class="mt-3 flex flex-col gap-1.5">
                    <div class="{{ $summaryRow }}"><dt class="{{ $summaryLabel }}">Divisi</dt><dd class="text-ink" x-text="picked.item.division?.name ?? 'Lintas divisi'"></dd></div>
                    <div class="{{ $summaryRow }}"><dt class="{{ $summaryLabel }}">Start Date</dt><dd class="text-ink" x-text="formatDate(picked.item.start_date ?? picked.item.date)"></dd></div>
                    <div class="{{ $summaryRow }}">
                        <dt class="{{ $summaryLabel }}" x-text="picked.kind === 'task' ? 'Deadline' : 'End Date'"></dt>
                        <dd :class="picked.kind === 'task' && isOverdue(picked.item) ? 'font-semibold text-red-700' : 'text-ink'"
                            x-text="formatDate(picked.kind === 'task' ? picked.item.deadline : (picked.item.end_date ?? picked.item.start_date ?? picked.item.date))"></dd>
                    </div>
                    <div class="{{ $summaryRow }}" x-show="picked.kind === 'task'">
                        <dt class="{{ $summaryLabel }}">Status</dt>
                        <dd><span class="{{ $badge }}" :class="statusOf(picked.item.status).badge" x-text="statusOf(picked.item.status).label"></span></dd>
                    </div>
                    <div class="{{ $summaryRow }}" x-show="picked.item.description">
                        <dt class="{{ $summaryLabel }}">Deskripsi</dt><dd class="line-clamp-3 text-ink" x-text="picked.item.description"></dd>
                    </div>
                </dl>

                <div class="mt-4 flex flex-wrap items-center gap-3" x-show="picked.kind === 'task'">
                    <button x-show="canOpen(picked.item)" type="button" class="{{ $primaryButton }}" @click="focusTask(picked.item)">Lihat Detail</button>
                    <p x-show="!canOpen(picked.item)" class="text-xs text-muted">Detail tugas ini hanya dapat dibuka oleh pengguna yang berwenang.</p>
                </div>
                <div class="mt-4" x-show="picked.kind === 'event' && picked.item.task_id">
                    <button type="button" class="{{ $primaryButton }}" @click="focusTask({ id: picked.item.task_id, can_view_detail: picked.item.can_view_detail })">Lihat Detail Tugas Terkait</button>
                </div>
                <p x-show="picked.kind === 'event' && !picked.item.task_id" class="mt-3 text-xs text-muted">Agenda bersama ini tidak terhubung ke tugas, sehingga tidak dibuka di Board.</p>
            </div>
        </template>
    </div>
</section>
