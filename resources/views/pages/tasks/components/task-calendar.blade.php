{{-- Calendar (TASK-11): agenda bersama lintas divisi + deadline tugas sesuai hak akses.
     Klik tanggal menampilkan agenda dan deadline tanggal itu; klik tugas (di sel atau daftar) membuka pop-up ringkasan
     dari data terbaru (TASK-15); klik agenda menampilkan ringkasan agenda. "Lihat Detail" membuka Board dan panel detailnya. --}}
@php
    $summaryRow = 'flex gap-3 text-sm';
    $summaryLabel = 'w-28 shrink-0 text-xs font-semibold text-label';
@endphp

<section x-data="taskCalendar" class="{{ $card }} min-w-0 p-4 sm:p-5" aria-labelledby="calendar-title">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 id="calendar-title" class="text-base font-semibold text-ink capitalize" x-text="monthLabel"></h2>
            <p class="mt-0.5 text-sm text-muted" x-text="calendarScopeLabel"></p>
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
        {{-- Tombol tanggal menutupi seluruh sel; item tugas berada di atasnya, sehingga klik tanggal dan klik tugas tidak saling mengganggu. --}}
        <template x-for="cell in cells" :key="cell.iso">
            <div
                role="gridcell"
                class="relative flex min-h-14 min-w-0 flex-col items-start gap-1 p-1.5 sm:min-h-24 sm:p-2"
                :class="[
                    cell.inMonth ? 'bg-white' : 'bg-soft text-label',
                    selected === cell.iso ? 'ring-2 ring-primary ring-inset' : 'hover:bg-primary-soft/50',
                ]"
                :aria-selected="selected === cell.iso"
            >
                <button type="button" class="absolute inset-0 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary"
                    :aria-label="cell.inMonth ? `${formatDate(cell.iso)}: ${cell.events} agenda, ${cell.deadlines} deadline` : `${formatDate(cell.iso)}: buka bulan ini`"
                    @click="choose(cell.iso)"></button>
                <span class="pointer-events-none relative grid size-6 place-items-center rounded-full text-xs font-semibold"
                    :class="cell.today ? 'bg-primary text-white' : (cell.inMonth ? 'text-ink' : 'text-label')" x-text="cell.day"></span>
                <span class="pointer-events-none relative flex w-full min-w-0 flex-col gap-0.5" x-show="state === 'ready'">
                    <span x-show="cell.events" class="inline-flex max-w-full items-center gap-1 truncate rounded bg-navy px-1 text-[10px] font-semibold text-white">
                        <span x-text="cell.events"></span><span class="hidden sm:inline">agenda</span>
                    </span>
                    {{-- Mobile: jumlah deadline; tugasnya dipilih dari daftar tanggal. --}}
                    <span x-show="cell.deadlines" class="inline-flex max-w-full items-center truncate rounded px-1 text-[10px] font-semibold text-white sm:hidden!"
                        :class="cell.overdue ? 'bg-red-600' : 'bg-primary'" x-text="cell.deadlines"></span>
                    {{-- Desktop: judul tugas dapat diklik langsung; sisanya dipilih dari daftar tanggal. --}}
                    <template x-for="task in cell.tasks.slice(0, 2)" :key="task.id">
                        <button type="button" data-calendar-task aria-haspopup="dialog"
                            class="pointer-events-auto hidden w-full truncate rounded px-1 text-left text-[10px] font-semibold text-white hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary sm:block"
                            :class="isOverdue(task) ? 'bg-red-600' : 'bg-primary'"
                            :aria-label="`Ringkasan tugas ${task.title}`" @click="openPreview(task)" x-text="task.title"></button>
                    </template>
                    <span x-show="cell.tasks.length > 2" class="hidden text-[10px] font-semibold text-muted sm:block" x-text="`+${cell.tasks.length - 2} tugas lainnya`"></span>
                </span>
            </div>
        </template>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-navy"></span> Agenda (bersama atau internal divisi)</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-primary"></span> Deadline tugas</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-red-600"></span> Ada deadline terlewat (masih aktif)</span>
    </div>

    <div x-show="state === 'loading'" class="mt-4 h-24 animate-pulse rounded-lg bg-soft" aria-label="Memuat kalender"></div>

    <div x-show="state === 'unavailable'" x-cloak class="mt-4">
        @include('pages.tasks.components.pending', ['text' => 'Agenda bersama dan deadline tugas akan tampil setelah API Calendar tersedia.'])
    </div>

    <div x-show="state === 'error'" x-cloak class="mt-4 flex flex-col items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-8 text-center" role="alert">
        <p class="text-sm text-red-700" x-text="message"></p>
        <button type="button" class="{{ $button }}" @click="loadPeriod()">Coba Lagi</button>
    </div>

    <div x-show="state === 'ready'" x-cloak class="mt-5 grid gap-4 lg:grid-cols-2">
        <div>
            <h3 class="text-sm font-semibold text-ink">Agenda · <span x-text="formatDate(selected)"></span></h3>
            <p class="mt-0.5 text-xs text-muted">Informasi umum kegiatan lintas divisi. Pilih agenda untuk melihat ringkasannya.</p>
            <ul class="mt-3 flex flex-col gap-2">
                <template x-for="event in selectedEvents" :key="event.id">
                    <li>
                        <button type="button" class="w-full rounded-lg border bg-white p-3 text-left hover:border-navy/40 focus-visible:outline-2 focus-visible:outline-primary"
                            :class="isPicked('event', event) ? 'border-navy ring-1 ring-navy' : 'border-line'" :aria-expanded="isPicked('event', event)" @click="pick('event', event)">
                            <span class="block text-sm font-semibold break-words text-ink" x-text="event.title"></span>
                            <span class="mt-0.5 block text-xs text-muted">
                                <span x-text="event.division?.name ?? 'Lintas divisi'"></span>
                                <span x-text="event.target_divisions?.length ? ` · Agenda internal ${event.target_divisions.map(({ name }) => name).join(', ')}` : ' · Agenda bersama'"></span>
                            </span>
                        </button>
                    </li>
                </template>
            </ul>
            <p x-show="!selectedEvents.length" class="mt-3 rounded-lg border border-dashed border-line bg-soft px-3 py-4 text-center text-sm text-muted">Tidak ada agenda pada tanggal ini.</p>
        </div>

        <div>
            <h3 class="text-sm font-semibold text-ink">Deadline Tugas · <span x-text="formatDate(selected)"></span></h3>
            <p class="mt-0.5 text-xs text-muted">Pilih tugas untuk melihat ringkasan terbarunya.</p>
            <ul class="mt-3 flex flex-col gap-2">
                <template x-for="task in selectedDeadlines" :key="task.id">
                    <li>
                        <button type="button" class="w-full rounded-lg border bg-white p-3 text-left hover:border-primary/40 focus-visible:outline-2 focus-visible:outline-primary"
                            :class="taskPreview?.id === task.id ? 'border-primary ring-1 ring-primary' : (isOverdue(task) ? 'border-red-200' : 'border-line')"
                            aria-haspopup="dialog" @click="openPreview(task)">
                            <span class="flex items-start justify-between gap-2">
                                <span class="text-sm font-semibold break-words text-ink" x-text="task.title"></span>
                                <span x-show="isOverdue(task)" class="shrink-0 rounded bg-red-50 px-1.5 py-0.5 text-[10px] font-bold text-red-700 uppercase">Terlambat</span>
                            </span>
                            <span class="mt-1 block truncate text-xs text-muted">
                                <span x-text="task.division?.name ?? '-'"></span> · PIC <span x-text="picNames(task) || '-'"></span>
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

        {{-- Ringkasan agenda yang dipilih (mekanisme agenda Calendar yang sudah ada) --}}
        <template x-if="pickedVisible">
            <div class="rounded-lg border border-navy/30 bg-soft p-4 lg:col-span-2" data-testid="calendar-summary" role="region" aria-label="Ringkasan agenda terpilih">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[11px] font-bold tracking-wide text-label uppercase">Ringkasan Agenda</p>
                        <h4 class="mt-0.5 text-base font-semibold break-words text-ink" x-text="picked.item.title"></h4>
                    </div>
                    <button type="button" class="grid size-7 shrink-0 place-items-center rounded-lg text-muted hover:bg-white hover:text-ink" @click="picked = null" aria-label="Tutup ringkasan">
                        <x-icon name="close" class="size-4" />
                    </button>
                </div>

                <dl class="mt-3 flex flex-col gap-1.5">
                    <div class="{{ $summaryRow }}"><dt class="{{ $summaryLabel }}">Divisi</dt><dd class="text-ink" x-text="picked.item.division?.name ?? 'Lintas divisi'"></dd></div>
                    <div class="{{ $summaryRow }}"><dt class="{{ $summaryLabel }}">Tanggal Mulai</dt><dd class="text-ink" x-text="formatDate(picked.item.start_date ?? picked.item.date)"></dd></div>
                    <div class="{{ $summaryRow }}"><dt class="{{ $summaryLabel }}">Tanggal Akhir</dt><dd class="text-ink" x-text="formatDate(picked.item.end_date ?? picked.item.start_date ?? picked.item.date)"></dd></div>
                    <div class="{{ $summaryRow }}" x-show="picked.item.description">
                        <dt class="{{ $summaryLabel }}">Keterangan</dt><dd class="line-clamp-3 text-ink" x-text="picked.item.description"></dd>
                    </div>
                </dl>

                <div class="mt-4" x-show="canOpenEventTask(picked.item)">
                    <button type="button" class="{{ $primaryButton }}" @click="focusTask({ id: picked.item.task_id, can_view_detail: true })">Lihat Detail Tugas Terkait</button>
                </div>
                <p x-show="picked.item.task_id && !canOpenEventTask(picked.item)" class="mt-3 text-xs text-muted">Agenda ini terkait tugas yang detailnya hanya dapat dibuka oleh pengguna yang berwenang.</p>
                <p x-show="!picked.item.task_id" class="mt-3 text-xs text-muted">Agenda ini tidak terhubung ke tugas, sehingga tidak dibuka di Papan.</p>
            </div>
        </template>
    </div>

    {{-- Pop-up ringkasan tugas (TASK-15): hanya-baca, dari GET /api/tasks/{id}/preview. Menutupnya tidak mengubah bulan atau tanggal terpilih. --}}
    <div x-show="taskPreview" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true"
        aria-labelledby="task-preview-title" data-task-preview @keydown.escape.window="taskPreview && closePreview()">
        <div class="absolute inset-0 bg-slate-900/50" @click="closePreview()"></div>

        <template x-if="taskPreview">
            <div class="relative max-h-[85vh] w-full overflow-y-auto rounded-t-panel border border-line bg-surface p-5 shadow-lg sm:max-w-md sm:rounded-panel"
                x-init="$nextTick(() => $el.querySelector('[data-preview-close]')?.focus())">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[11px] font-bold tracking-wide text-label uppercase">Ringkasan Tugas</p>
                        <h2 id="task-preview-title" class="mt-0.5 text-base font-semibold break-words text-ink" x-text="taskPreview.task?.title ?? 'Tugas'"></h2>
                    </div>
                    <button type="button" data-preview-close class="grid size-8 shrink-0 place-items-center rounded-lg text-muted hover:bg-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-primary"
                        @click="closePreview()" aria-label="Tutup ringkasan tugas">
                        <x-icon name="close" class="size-4" />
                    </button>
                </div>

                <div x-show="taskPreview.state === 'loading'" class="mt-4 flex flex-col gap-2" role="status">
                    <span class="sr-only">Memuat ringkasan tugas…</span>
                    <div class="h-4 w-2/3 animate-pulse rounded bg-soft"></div>
                    <div class="h-4 w-1/2 animate-pulse rounded bg-soft"></div>
                    <div class="h-4 w-3/4 animate-pulse rounded bg-soft"></div>
                </div>

                <div x-show="taskPreview.state === 'error' || taskPreview.state === 'missing'" class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-3 text-sm text-red-700" role="alert">
                    <p x-text="taskPreview.message"></p>
                    <button x-show="taskPreview.state === 'error'" type="button" class="{{ $button }} mt-3" @click="retryPreview()">Coba Lagi</button>
                </div>

                <template x-if="taskPreview.state === 'ready'">
                    <dl class="mt-4 flex flex-col gap-2">
                        <div class="{{ $summaryRow }}">
                            <dt class="{{ $summaryLabel }}">Status</dt>
                            <dd><span class="{{ $badge }}" :class="statusOf(taskPreview.task.status).badge" x-text="statusOf(taskPreview.task.status).label"></span></dd>
                        </div>
                        <div class="{{ $summaryRow }}">
                            <dt class="{{ $summaryLabel }}">Tanggal Mulai</dt>
                            <dd :class="taskPreview.task.start_date ? 'text-ink' : 'italic text-muted'" x-text="previewDate(taskPreview.task.start_date)"></dd>
                        </div>
                        <div class="{{ $summaryRow }}">
                            <dt class="{{ $summaryLabel }}">Deadline</dt>
                            <dd :class="!taskPreview.task.deadline ? 'italic text-muted' : (taskPreview.task.is_overdue ? 'font-semibold text-red-700' : 'text-ink')">
                                <span x-text="previewDate(taskPreview.task.deadline)"></span>
                                <span x-show="taskPreview.task.is_overdue" class="ml-1 rounded bg-red-50 px-1.5 py-0.5 text-[10px] font-bold text-red-700 uppercase">Terlambat</span>
                            </dd>
                        </div>
                        <div class="{{ $summaryRow }}">
                            <dt class="{{ $summaryLabel }}">PIC</dt>
                            <dd class="min-w-0">
                                <ul x-show="taskPreview.task.pics?.length" class="flex flex-col gap-0.5 text-ink" data-preview-pics>
                                    <template x-for="pic in taskPreview.task.pics" :key="pic.id"><li class="break-words" x-text="pic.fullname"></li></template>
                                </ul>
                                <span x-show="!taskPreview.task.pics?.length" class="italic text-muted">Belum ada PIC</span>
                            </dd>
                        </div>
                        <div class="{{ $summaryRow }}"><dt class="{{ $summaryLabel }}">Divisi</dt><dd class="text-ink" x-text="taskPreview.task.division?.name ?? '-'"></dd></div>
                        <div class="{{ $summaryRow }}">
                            <dt class="{{ $summaryLabel }}">Prioritas</dt>
                            <dd><span class="{{ $badge }}" :class="priorityOf(taskPreview.task.priority).badge" x-text="priorityOf(taskPreview.task.priority).label"></span></dd>
                        </div>
                        <div class="{{ $summaryRow }}" x-show="taskPreview.task.description">
                            <dt class="{{ $summaryLabel }}">Deskripsi</dt><dd class="line-clamp-3 break-words text-ink" x-text="taskPreview.task.description"></dd>
                        </div>
                    </dl>
                </template>

                <div class="mt-5 flex flex-wrap items-center justify-end gap-2">
                    <p x-show="taskPreview.state === 'ready' && !canOpen(taskPreview.task)" class="mr-auto text-xs text-muted">Detail tugas ini hanya dapat dibuka oleh pengguna yang berwenang.</p>
                    <button type="button" class="{{ $button }}" @click="closePreview()">Tutup</button>
                    <button x-show="taskPreview.state === 'ready' && canOpen(taskPreview.task)" type="button" class="{{ $primaryButton }}" @click="openPreviewDetail()">Lihat Detail</button>
                </div>
            </div>
        </template>
    </div>
</section>
