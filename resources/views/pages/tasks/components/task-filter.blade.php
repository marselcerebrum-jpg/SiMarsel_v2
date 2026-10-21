@php
    $labelClass = 'mb-1.5 block text-xs font-semibold text-label';
    $selectClass = 'h-9 w-full rounded-lg border border-line bg-white px-3 text-sm text-ink focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:bg-soft disabled:text-muted';
@endphp

<div class="flex flex-col gap-4">
    {{-- Pilihan tampilan: filter tetap dipakai saat berpindah tampilan --}}
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="inline-flex w-full overflow-x-auto rounded-lg border border-line bg-soft p-1 sm:w-auto" role="tablist" aria-label="Pilih tampilan">
            <template x-for="item in views" :key="item.key">
                <button
                    type="button"
                    role="tab"
                    class="flex-1 rounded-md px-4 py-1.5 text-sm font-semibold whitespace-nowrap focus-visible:outline-2 focus-visible:outline-primary sm:flex-none"
                    :class="view === item.key ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink'"
                    :aria-selected="view === item.key"
                    @click="view = item.key"
                    x-text="item.label"
                ></button>
            </template>
        </div>

        <p class="text-xs text-muted" x-show="view === 'timeline' || view === 'calendar'" x-cloak>
            Timeline dan Calendar memakai filter divisi; jadwal bersama lintas divisi tetap terlihat.
        </p>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))_auto] lg:items-end">
        <div>
            <label for="task-search" class="{{ $labelClass }}">Search</label>
            <label class="relative block">
                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-label" />
                <input id="task-search" type="search" x-model.debounce.200ms="filters.search" placeholder="Cari judul tugas…"
                    class="h-9 w-full rounded-lg border border-line bg-white pr-3 pl-9 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none">
            </label>
        </div>

        <div x-show="user.role !== 'staff'">
            <label for="task-division" class="{{ $labelClass }}">Division</label>
            <select id="task-division" x-model="filters.division" class="{{ $selectClass }}" :disabled="user.role === 'manager'">
                <template x-if="user.role === 'admin'"><option value="">Semua Divisi</option></template>
                <template x-if="user.role === 'manager' && !divisionOptions.length"><option value="">Divisi belum ditetapkan</option></template>
                <template x-for="division in divisionOptions" :key="division.code_division">
                    <option :value="division.code_division" x-text="division.name" :selected="filters.division === division.code_division"></option>
                </template>
            </select>
        </div>

        <div>
            <label for="task-priority" class="{{ $labelClass }}">Priority</label>
            <select id="task-priority" x-model="filters.priority" class="{{ $selectClass }}">
                <option value="">All Priorities</option>
                <template x-for="priority in priorities" :key="priority.key">
                    <option :value="priority.key" x-text="priority.label"></option>
                </template>
            </select>
        </div>

        <div>
            <label for="task-status" class="{{ $labelClass }}">Status</label>
            <select id="task-status" x-model="filters.status" class="{{ $selectClass }}">
                <option value="">All Statuses</option>
                <template x-for="item in statuses" :key="item.key">
                    <option :value="item.key" x-text="item.label"></option>
                </template>
            </select>
        </div>

        <button type="button" class="{{ $button }}" :disabled="!hasActiveFilter" @click="resetFilters()">Reset Filter</button>
    </div>
</div>
