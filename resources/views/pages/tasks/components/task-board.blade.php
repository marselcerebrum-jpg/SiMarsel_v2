{{-- Board Kanban (TASK-01 A) dengan drag-and-drop sesuai transition matrix (TASK-05 D). --}}
<section class="{{ $card }} min-w-0 p-3 sm:p-4" aria-label="Board tugas">
    <div class="relative" :aria-busy="loading.toString()">
        <div x-show="status === 'ready' && !emptyKind" x-cloak>
            <div class="-mx-1 grid grid-flow-col auto-cols-[minmax(240px,1fr)] gap-3 overflow-x-auto px-1 pb-2">
                <template x-for="column in statuses" :key="column.key">
                    <div
                        class="relative flex min-h-64 flex-col rounded-panel border-2 bg-soft p-2 transition-colors"
                        :class="{
                            'border-transparent': !dropState(column.key),
                            'border-primary bg-primary-soft': dropState(column.key) === 'allowed' && dropTarget === column.key,
                            'border-dashed border-primary/50': dropState(column.key) === 'allowed' && dropTarget !== column.key,
                            'border-transparent opacity-60': dropState(column.key) === 'denied',
                        }"
                        @dragover="dragOver($event, column.key)"
                        @dragleave.self="dropTarget = null"
                        @drop.prevent="drop(column.key)"
                        :aria-label="`Kolom ${column.label}`"
                    >
                        <div class="flex items-center justify-between px-1 pb-2">
                            <span class="inline-flex items-center gap-2 text-sm font-semibold text-ink">
                                <span class="size-2 rounded-full" :class="column.dot" aria-hidden="true"></span>
                                <span x-text="column.label"></span>
                            </span>
                            <span class="rounded-md bg-white px-2 py-0.5 text-xs font-semibold text-muted tabular-nums" x-text="tasksIn(column.key).length"></span>
                        </div>

                        {{-- Alasan kolom tidak menerima kartu yang sedang diseret --}}
                        <p x-show="dropState(column.key) === 'denied' && dropTarget === column.key" x-cloak
                            class="mb-2 rounded-lg border border-red-200 bg-red-50 px-2.5 py-2 text-xs text-red-700" role="status" x-text="dropReason(column.key)"></p>

                        <div class="flex flex-1 flex-col gap-2">
                            <template x-for="task in tasksIn(column.key)" :key="task.id">
                                <article
                                    class="relative rounded-lg border bg-white p-3 shadow-xs transition hover:border-primary/40 hover:shadow-sm"
                                    :class="{
                                        'border-line': highlightId !== task.id,
                                        'border-primary ring-2 ring-primary/30': highlightId === task.id,
                                        'opacity-50': draggingId === task.id || movingId === task.id,
                                        'cursor-grab active:cursor-grabbing': canDrag(task),
                                    }"
                                    :data-task-card="task.id"
                                    :draggable="canDrag(task).toString()"
                                    @dragstart="dragStart($event, task)"
                                    @dragend="dragEnd()"
                                >
                                    <button type="button" class="block w-full text-left focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary" @click="openTask(task)" :title="task.title">
                                        <span class="line-clamp-2 text-sm font-semibold break-words text-ink" x-text="task.title"></span>
                                    </button>
                                    <p class="mt-0.5 truncate text-[11px] text-label" x-text="task.division?.name ?? 'Tanpa divisi'" :title="task.division?.name"></p>

                                    <div class="mt-2.5 flex items-center justify-between gap-2 text-xs">
                                        <span class="{{ $badge }}" :class="priorityOf(task.priority).badge" x-text="priorityOf(task.priority).label"></span>
                                        <span x-show="task.deadline" class="inline-flex shrink-0 items-center gap-1" :class="isOverdue(task) ? 'font-semibold text-red-700' : 'text-muted'">
                                            <x-icon name="clock" class="size-3.5" />
                                            <span x-text="formatDate(task.deadline)"></span>
                                            <span x-show="isOverdue(task)" class="sr-only">(terlambat)</span>
                                        </span>
                                        <span x-show="!task.deadline" class="text-[11px] text-label">Tanpa deadline</span>
                                    </div>

                                    <p class="mt-2 flex min-w-0 items-center gap-1.5 border-t border-line pt-2 text-xs" :title="task.pic?.fullname">
                                        <x-icon name="user" class="size-3.5 shrink-0 text-label" />
                                        <span class="truncate" :class="task.pic ? 'text-ink' : 'italic text-muted'" x-text="task.pic?.fullname ?? 'Belum ada PIC'"></span>
                                    </p>

                                    <span x-show="movingId === task.id" class="absolute inset-0 grid place-items-center rounded-lg bg-white/70 text-xs font-semibold text-muted">Menyimpan…</span>
                                </article>
                            </template>

                            <p x-show="!tasksIn(column.key).length" class="rounded-lg border border-dashed border-line px-3 py-6 text-center text-xs text-label">Tidak ada tugas</p>
                        </div>
                    </div>
                </template>
            </div>
            <p class="mt-1 text-[11px] text-label">Seret kartu ke kolom lain untuk mengubah status. Hanya kolom dengan perpindahan yang sah yang dapat menerima kartu.</p>
        </div>

        <div x-show="loading && status === 'ready'" x-cloak class="absolute inset-0 grid place-items-center rounded-lg bg-surface/70">
            <span class="rounded-lg border border-line bg-white px-3 py-1.5 text-xs font-semibold text-muted shadow-sm">Memuat tugas…</span>
        </div>

        @include('pages.tasks.components.list-states', ['skeleton' => 'board'])
    </div>
</section>
