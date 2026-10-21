{{-- Board Kanban (TASK-01 A) dengan drag-and-drop sesuai workflow (TASK-05 D). --}}
<section class="{{ $card }} min-w-0 p-4 sm:p-5" aria-label="Board tugas">
    <div class="relative" :aria-busy="loading.toString()">
        <div x-show="status === 'ready' && !emptyKind" x-cloak>
            <p class="mb-3 text-xs text-muted">
                Seret kartu ke kolom lain untuk mengubah status. Perpindahan yang tidak sesuai alur atau kewenangan akan ditolak.
            </p>

            <div class="-mx-1 grid grid-flow-col auto-cols-[minmax(250px,1fr)] gap-3 overflow-x-auto px-1 pb-2">
                <template x-for="column in statuses" :key="column.key">
                    <div
                        class="flex min-h-72 flex-col rounded-panel border-2 bg-soft p-2.5 transition-colors"
                        :class="{
                            'border-transparent': !dropState(column.key),
                            'border-primary bg-primary-soft': dropState(column.key) === 'allowed' && dropTarget === column.key,
                            'border-dashed border-primary/50': dropState(column.key) === 'allowed' && dropTarget !== column.key,
                            'border-dashed border-red-300 bg-red-50/60': dropState(column.key) === 'denied' && dropTarget === column.key,
                        }"
                        @dragover.prevent="dropTarget = column.key"
                        @dragleave.self="dropTarget = null"
                        @drop.prevent="drop(column.key)"
                        :aria-label="`Kolom ${column.label}`"
                    >
                        <div class="flex items-center justify-between px-1 pb-2.5">
                            <span class="inline-flex items-center gap-2 text-sm font-semibold text-ink">
                                <span class="size-2 rounded-full" :class="column.dot" aria-hidden="true"></span>
                                <span x-text="column.label"></span>
                            </span>
                            <span class="rounded-md bg-white px-2 py-0.5 text-xs font-semibold text-muted tabular-nums" x-text="tasksIn(column.key).length"></span>
                        </div>

                        <div class="flex flex-1 flex-col gap-2">
                            <template x-for="task in tasksIn(column.key)" :key="task.id">
                                <article
                                    class="group relative rounded-lg border border-line bg-white p-3 shadow-xs transition hover:border-primary/40 hover:shadow-sm"
                                    :class="{ 'opacity-50': draggingId === task.id || movingId === task.id, 'cursor-grab active:cursor-grabbing': canDrag(task) }"
                                    :draggable="canDrag(task).toString()"
                                    @dragstart="dragStart($event, task)"
                                    @dragend="dragEnd()"
                                >
                                    <button type="button" class="block w-full text-left focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary" @click="openTask(task)">
                                        <span class="line-clamp-2 text-sm font-semibold text-ink" x-text="task.title"></span>
                                    </button>

                                    <p class="mt-1 truncate text-xs text-muted" x-text="task.division?.name ?? 'Tanpa divisi'"></p>

                                    <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                                        <span class="{{ $badge }}" :class="priorityOf(task.priority).badge" x-text="priorityOf(task.priority).label"></span>
                                        <span class="{{ $badge }}" :class="statusOf(task.status).badge" x-text="statusOf(task.status).label"></span>
                                    </div>

                                    <div class="mt-2.5 flex items-center justify-between gap-2 border-t border-line pt-2 text-xs">
                                        <span class="inline-flex min-w-0 items-center gap-1.5 text-ink">
                                            <x-icon name="user" class="size-3.5 text-label" />
                                            <span class="truncate" x-text="task.pic?.fullname ?? 'Belum ada PIC'" :class="!task.pic && 'text-muted italic'"></span>
                                        </span>
                                        <span x-show="task.deadline" class="inline-flex shrink-0 items-center gap-1" :class="isOverdue(task) ? 'font-semibold text-red-700' : 'text-muted'">
                                            <x-icon name="clock" class="size-3.5" />
                                            <span x-text="formatDate(task.deadline)"></span>
                                            <span x-show="isOverdue(task)" class="sr-only">(terlambat)</span>
                                        </span>
                                    </div>

                                    <span x-show="movingId === task.id" class="absolute inset-0 grid place-items-center rounded-lg bg-white/70 text-xs font-semibold text-muted">Menyimpan…</span>
                                </article>
                            </template>

                            <p x-show="!tasksIn(column.key).length" class="rounded-lg border border-dashed border-line px-3 py-6 text-center text-xs text-label">Tidak ada tugas</p>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div x-show="loading && status === 'ready'" x-cloak class="absolute inset-0 grid place-items-center rounded-lg bg-surface/70">
            <span class="rounded-lg border border-line bg-white px-3 py-1.5 text-xs font-semibold text-muted shadow-sm">Memuat tugas…</span>
        </div>

        @include('pages.tasks.components.list-states', ['skeleton' => 'board'])
    </div>
</section>
