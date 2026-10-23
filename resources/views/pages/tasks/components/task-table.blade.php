{{-- Table View (TASK-01 B): sumber data dan filter yang sama dengan Board. --}}
@php
    // Urutan kolom sesuai review: Judul | Status | Prioritas | PIC | Deadline | Divisi | Aksi.
    // Lebar kolom tetap (table-fixed) agar sorting/pagination tidak menggeser layout.
    $columns = [
        ['key' => 'title', 'label' => 'Judul Tugas', 'width' => 'w-[30%]'],
        ['key' => 'status', 'label' => 'Status', 'width' => 'w-[13%]'],
        ['key' => 'priority', 'label' => 'Prioritas', 'width' => 'w-[11%]'],
        ['key' => 'pic', 'label' => 'PIC', 'width' => 'w-[14%]'],
        ['key' => 'deadline', 'label' => 'Deadline', 'width' => 'w-[13%]'],
        ['key' => 'division', 'label' => 'Divisi', 'width' => 'w-[11%]'],
    ];
    $pageButton = 'grid h-8 min-w-8 place-items-center rounded-lg border px-2 text-xs font-semibold tabular-nums focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-40';
    $pageIdle = 'border-line bg-white text-ink hover:bg-soft';
@endphp

<section class="{{ $card }} min-w-0 p-4 sm:p-5" aria-label="Tabel tugas">
    <div class="relative" :aria-busy="loading.toString()">
        <div x-show="status === 'ready' && !emptyKind" x-cloak>
            <div class="overflow-hidden rounded-lg border border-line">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] table-fixed text-left text-sm">
                        <thead class="bg-soft text-xs font-semibold text-label">
                            <tr>
                                @foreach ($columns as $column)
                                    <th scope="col" class="{{ $column['width'] }} px-4 py-3" :aria-sort="ariaSort('{{ $column['key'] }}')">
                                        <button type="button" class="inline-flex items-center gap-1 whitespace-nowrap hover:text-ink focus-visible:outline-2 focus-visible:outline-primary" @click="sortBy('{{ $column['key'] }}')">
                                            {{ $column['label'] }}
                                            <x-icon name="chevron-down" class="size-3 transition-transform"
                                                ::class="sort.key !== '{{ $column['key'] }}' ? 'opacity-30' : (sort.dir === 'asc' ? 'rotate-180' : '')" />
                                        </button>
                                    </th>
                                @endforeach
                                <th scope="col" class="w-[8%] px-4 py-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <template x-for="task in pageRows" :key="task.id">
                                <tr class="align-top hover:bg-soft/60">
                                    <td class="px-4 py-3">
                                        <span class="line-clamp-2 font-semibold break-words text-ink" x-text="task.title" :title="task.title"></span>
                                    </td>
                                    <td class="px-4 py-3"><span class="{{ $badge }}" :class="statusOf(task.status).badge" x-text="statusOf(task.status).label"></span></td>
                                    <td class="px-4 py-3"><span class="{{ $badge }}" :class="priorityOf(task.priority).badge" x-text="priorityOf(task.priority).label"></span></td>
                                    <td class="truncate px-4 py-3" :class="task.pic ? 'text-ink' : 'italic text-muted'" x-text="task.pic?.fullname ?? 'Belum ada PIC'" :title="task.pic?.fullname"></td>
                                    <td class="px-4 py-3 tabular-nums" :class="isOverdue(task) ? 'font-semibold text-red-700' : 'text-ink'">
                                        <span class="block truncate" x-text="formatDate(task.deadline)"></span>
                                        <span x-show="isOverdue(task)" class="mt-0.5 inline-block rounded bg-red-50 px-1.5 py-0.5 text-[10px] font-bold uppercase">Terlambat</span>
                                    </td>
                                    <td class="truncate px-4 py-3 text-ink" x-text="task.division?.name ?? '-'" :title="task.division?.name"></td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <button x-show="canOpen(task)" type="button" class="text-xs font-semibold text-primary hover:underline" @click="openTask(task)">Detail</button>
                                        <button x-show="canEdit(task)" type="button" class="ml-3 text-xs font-semibold text-ink hover:underline" @click="openTask(task, { focus: 'edit' })">Edit</button>
                                        <span x-show="!canOpen(task)" class="text-xs text-label">Terbatas</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-muted" aria-live="polite">
                    Menampilkan <span class="font-semibold text-ink" x-text="rangeLabel"></span>
                    dari <span class="font-semibold text-ink" x-text="sortedTasks.length"></span> tugas
                </p>

                <nav x-show="pageCount > 1" class="flex items-center gap-1" aria-label="Task table pagination">
                    <button type="button" class="{{ $pageButton }} {{ $pageIdle }}" :disabled="currentPage === 1" @click="goTo(currentPage - 1)" aria-label="Previous page">
                        <x-icon name="chevron-left" />
                    </button>
                    <template x-for="link in pageLinks" :key="link.key">
                        <span class="contents">
                            <span x-show="link.page === null" class="grid size-8 place-items-center text-xs text-label">…</span>
                            <button x-show="link.page !== null" type="button" class="{{ $pageButton }}"
                                :class="link.page === currentPage ? 'border-primary bg-primary text-white' : @js($pageIdle)"
                                :aria-current="link.page === currentPage ? 'page' : null" :aria-label="`Page ${link.page}`" @click="goTo(link.page)" x-text="link.page"></button>
                        </span>
                    </template>
                    <button type="button" class="{{ $pageButton }} {{ $pageIdle }}" :disabled="currentPage === pageCount" @click="goTo(currentPage + 1)" aria-label="Next page">
                        <x-icon name="chevron-right" />
                    </button>
                </nav>
            </div>
        </div>

        <div x-show="loading && status === 'ready'" x-cloak class="absolute inset-0 grid place-items-center rounded-lg bg-surface/70">
            <span class="rounded-lg border border-line bg-white px-3 py-1.5 text-xs font-semibold text-muted shadow-sm">Memuat tugas…</span>
        </div>

        @include('pages.tasks.components.list-states', ['skeleton' => 'table'])
    </div>
</section>
