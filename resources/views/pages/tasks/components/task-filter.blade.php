@php
    $selectClass = 'h-9 w-full rounded-lg border border-line bg-white pr-8 pl-3 sm:w-auto text-sm text-ink focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:bg-soft disabled:text-muted';
    $periodControlClass = 'h-9 w-full rounded-lg border border-line bg-white pr-8 pl-3 text-sm text-ink focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none';
@endphp

{{-- Toolbar ringkas: pilihan tampilan + pencarian + filter dalam satu baris (desktop). Filter berlaku di semua tampilan. --}}
<div class="flex flex-col gap-2 2xl:flex-row 2xl:items-center 2xl:gap-3">
    <div class="inline-flex shrink-0 self-start overflow-x-auto rounded-lg border border-line bg-soft p-1" role="tablist" aria-label="Pilih tampilan">
        <template x-for="item in views" :key="item.key">
            <button
                type="button"
                role="tab"
                class="flex-1 rounded-md px-3.5 py-1 text-sm font-semibold whitespace-nowrap focus-visible:outline-2 focus-visible:outline-primary sm:flex-none"
                :class="view === item.key ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink'"
                :aria-selected="view === item.key"
                @click="view = item.key"
                x-text="item.label"
            ></button>
        </template>
    </div>

    {{-- Cakupan Papan dan Tabel: seluruh tugas lintas divisi, atau tugas dengan akun ini sebagai PIC aktif. --}}
    <div x-show="view === 'board' || view === 'table'" class="inline-flex shrink-0 self-start rounded-lg border border-line bg-soft p-1" role="group" aria-label="Cakupan tugas" data-task-scope>
        <template x-for="item in scopes" :key="item.key">
            <button
                type="button"
                class="rounded-md px-3.5 py-1 text-sm font-semibold whitespace-nowrap focus-visible:outline-2 focus-visible:outline-primary"
                :class="taskScope === item.key ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink'"
                :aria-pressed="taskScope === item.key"
                @click="taskScope = item.key"
                x-text="item.label"
            ></button>
        </template>
    </div>

    {{-- Lebar tiap filter mengikuti isinya agar teks tidak terpotong; baris membungkus bila tidak muat. --}}
    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
        <label class="relative block min-w-0 basis-full sm:min-w-48 sm:flex-1 sm:basis-auto">
            <span class="sr-only">Cari judul tugas</span>
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-label" />
            <input id="task-search" type="search" x-model.debounce.200ms="filters.search" maxlength="150" placeholder="Cari judul tugas…"
                class="h-9 w-full rounded-lg border border-line bg-white pr-3 pl-9 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none">
        </label>

        <label x-show="view !== 'timeline'" class="block min-w-0 basis-full sm:max-w-60 sm:flex-none sm:basis-auto">
            <span class="sr-only">Divisi</span>
            <select id="task-division" x-model="filters.division" class="{{ $selectClass }}" title="Divisi">
                <option value="">Semua Divisi</option>
                <template x-for="division in divisionOptions" :key="division.code_division">
                    <option :value="division.code_division" x-text="division.name" :selected="filters.division === division.code_division"></option>
                </template>
            </select>
        </label>

        <label class="block min-w-36 flex-1 sm:min-w-0 sm:flex-none">
            <span class="sr-only">Prioritas</span>
            <select id="task-priority" x-model="filters.priority" class="{{ $selectClass }}" title="Prioritas">
                <option value="">Semua Prioritas</option>
                <template x-for="priority in priorities" :key="priority.key">
                    <option :value="priority.key" x-text="priority.label"></option>
                </template>
            </select>
        </label>

        <label class="block min-w-36 flex-1 sm:min-w-0 sm:flex-none">
            <span class="sr-only">Status</span>
            <select id="task-status" x-model="filters.status" class="{{ $selectClass }}" title="Status">
                <option value="">Semua Status</option>
                <template x-for="item in statuses" :key="item.key">
                    <option :value="item.key" x-text="item.label"></option>
                </template>
            </select>
        </label>

        <button type="button" class="{{ $button }} shrink-0 basis-full sm:basis-auto" :disabled="!hasActiveFilter && !hasPeriodConfiguration" @click="resetFilters()">Atur Ulang Filter</button>
    </div>
</div>

<form x-show="view === 'board' || view === 'table'" x-cloak class="mt-3 border-t border-line pt-3" @submit.prevent="confirmPeriodFilter()" aria-labelledby="task-period-filter-title">
    <div class="mb-2 flex flex-wrap items-start justify-between gap-2">
        <div>
            <h2 id="task-period-filter-title" class="text-sm font-semibold text-ink">Filter Periode</h2>
            <p class="mt-0.5 text-xs text-muted">Pilih tanggal mulai atau deadline. Rentang mencakup tanggal awal dan akhir. Filter belum diterapkan ke data karena backend belum tersedia.</p>
        </div>
        <span class="rounded-md bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-800">Tampilan UI saja</span>
    </div>

    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4">
        <label class="block min-w-0">
            <span class="mb-1 block text-xs font-medium text-label">Dasar Tanggal</span>
            <select id="task-period-basis" x-model="periodFilter.basis" class="{{ $periodControlClass }}">
                <option value="start_date">Tanggal Mulai</option>
                <option value="deadline">Deadline</option>
            </select>
        </label>

        <label class="block min-w-0">
            <span class="mb-1 block text-xs font-medium text-label">Jenis Periode</span>
            <select id="task-period-mode" x-model="periodFilter.mode" class="{{ $periodControlClass }}">
                <option value="">Tanpa filter periode</option>
                <option value="date">Tanggal tertentu</option>
                <option value="month">Bulan tertentu</option>
                <option value="year">Tahun tertentu</option>
                <option value="range">Rentang tanggal</option>
            </select>
        </label>

        <label x-show="periodFilter.mode === 'date'" x-cloak class="block min-w-0">
            <span class="mb-1 block text-xs font-medium text-label">Tanggal</span>
            <input id="task-period-date" type="date" max="9999-12-31" x-model="periodFilter.date" class="{{ $periodControlClass }}">
        </label>

        <label x-show="periodFilter.mode === 'month'" x-cloak class="block min-w-0">
            <span class="mb-1 block text-xs font-medium text-label">Bulan dan Tahun</span>
            <input id="task-period-month" type="month" max="9999-12" x-model="periodFilter.month" class="{{ $periodControlClass }}">
        </label>

        <label x-show="periodFilter.mode === 'year'" x-cloak class="block min-w-0">
            <span class="mb-1 block text-xs font-medium text-label">Tahun</span>
            <input id="task-period-year" type="number" inputmode="numeric" min="1000" max="9999" step="1" placeholder="Contoh: 2009"
                x-model="periodFilter.year" :aria-invalid="Boolean(periodYearError)" aria-describedby="task-period-year-error" class="{{ $periodControlClass }}">
            <p id="task-period-year-error" x-show="periodYearError" x-cloak class="mt-1 text-xs text-red-600" role="alert" x-text="periodYearError"></p>
        </label>

        <template x-if="periodFilter.mode === 'range'">
            <div class="grid min-w-0 grid-cols-2 gap-2 sm:col-span-2">
                <label class="block min-w-0">
                    <span class="mb-1 block text-xs font-medium text-label">Dari Tanggal</span>
                    <input id="task-period-start" type="date" max="9999-12-31" x-model="periodFilter.startDate" class="{{ $periodControlClass }}">
                </label>
                <label class="block min-w-0">
                    <span class="mb-1 block text-xs font-medium text-label">Sampai Tanggal</span>
                    <input id="task-period-end" type="date" max="9999-12-31" x-model="periodFilter.endDate" class="{{ $periodControlClass }}">
                </label>
            </div>
        </template>

        <div class="flex flex-col justify-end gap-1">
            <button type="submit" class="{{ $button }} w-full" :disabled="!canConfirmPeriodFilter">Terapkan Periode</button>
            <p x-show="periodRangeError" x-cloak class="text-xs text-red-600" role="alert" x-text="periodRangeError"></p>
        </div>
    </div>
</form>
