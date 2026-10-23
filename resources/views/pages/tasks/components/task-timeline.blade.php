{{-- Timeline (TASK-10): jadwal bersama lintas divisi, hanya baca (tanpa drag-and-drop). --}}
<section x-data="taskTimeline" class="{{ $card }} min-w-0 p-4 sm:p-5" aria-labelledby="timeline-title">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h2 id="timeline-title" class="text-base font-semibold text-ink">Timeline</h2>
            <p class="mt-1 text-sm text-muted"><span x-text="periodLabel"></span> · <span x-text="scopeLabel"></span></p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-lg border border-line bg-soft p-1" role="group" aria-label="Rentang timeline">
                <button type="button" class="rounded-md px-3 py-1 text-xs font-semibold" :class="mode === 'week' ? 'bg-white text-ink shadow-sm' : 'text-muted'" :aria-pressed="mode === 'week'" @click="setMode('week')">1 Minggu</button>
                <button type="button" class="rounded-md px-3 py-1 text-xs font-semibold" :class="mode === 'biweek' ? 'bg-white text-ink shadow-sm' : 'text-muted'" :aria-pressed="mode === 'biweek'" @click="setMode('biweek')">2 Minggu</button>
            </div>
            <button type="button" class="{{ $button }} px-2.5" @click="shift(-1)" aria-label="Periode sebelumnya"><x-icon name="chevron-left" /></button>
            <button type="button" class="{{ $button }}" @click="goToday()">Minggu Ini</button>
            <button type="button" class="{{ $button }} px-2.5" @click="shift(1)" aria-label="Periode berikutnya"><x-icon name="chevron-right" /></button>
        </div>
    </div>

    <div class="mt-4 overflow-x-auto">
        <div class="min-w-[760px]">
            {{-- Kepala tanggal --}}
            <div class="grid grid-cols-[220px_1fr] border-b border-line pb-2">
                <span class="text-xs font-semibold text-label">Kegiatan / Tugas</span>
                <div class="grid" :style="`grid-template-columns: repeat(${length}, minmax(0, 1fr))`">
                    <template x-for="day in days" :key="day.iso">
                        <span class="text-center text-[11px] font-semibold" :class="day.today ? 'text-primary' : (day.weekend ? 'text-label' : 'text-muted')" x-text="day.label"></span>
                    </template>
                </div>
            </div>

            <div x-show="state === 'ready' && rows.length" x-cloak class="divide-y divide-line">
                <template x-for="item in rows" :key="`${item.type}-${item.id}`">
                    <div class="grid grid-cols-[220px_1fr] items-center py-2">
                        <div class="min-w-0 pr-3">
                            <p class="truncate text-sm font-semibold text-ink" x-text="item.title"></p>
                            <p class="truncate text-xs text-muted">
                                <span x-text="item.division?.name ?? 'Lintas divisi'"></span>
                                <span x-show="item.type === 'event'"> · Agenda bersama</span>
                                <span x-show="item.type === 'task' && item.end_date" :class="item.overdue && 'font-semibold text-red-700'" x-text="` · Deadline ${formatDate(item.end_date)}`"></span>
                            </p>
                        </div>
                        <div class="relative h-8">
                            <div class="absolute inset-0 grid" :style="`grid-template-columns: repeat(${length}, minmax(0, 1fr))`" aria-hidden="true">
                                <template x-for="day in days" :key="day.iso">
                                    <span class="border-l border-line/70" :class="day.today && 'bg-primary-soft'"></span>
                                </template>
                            </div>
                            <button
                                type="button"
                                class="absolute top-1 bottom-1 flex min-w-0 items-center gap-1.5 rounded-md px-2 text-left text-[11px] font-semibold text-white shadow-xs focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary"
                                :class="item.type === 'event' ? 'bg-navy' : (item.overdue ? 'bg-red-600' : (item.status === 'done' ? 'bg-emerald-600' : 'bg-primary'))"
                                :style="`left: ${item.left}; width: ${item.width}`"
                                :title="`${item.title} · ${formatDate(item.start_date)} – ${formatDate(item.end_date)}`"
                                @click="select(item)"
                            >
                                <span x-show="item.status" class="size-1.5 shrink-0 rounded-full bg-white/80" aria-hidden="true"></span>
                                <span class="truncate" x-text="item.status ? statusOf(item.status).label : 'Agenda'"></span>
                                <span x-show="item.overdue" class="shrink-0 font-bold">· Terlambat</span>
                                <span x-show="item.partial" class="shrink-0 opacity-80">· 1 hari</span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <div x-show="state === 'loading'" class="mt-3 flex animate-pulse flex-col gap-2" aria-label="Memuat jadwal">
        @foreach (range(1, 4) as $row)
            <div class="h-9 rounded-lg bg-soft"></div>
        @endforeach
    </div>

    <div x-show="state === 'unavailable'" x-cloak class="mt-3">
        @include('pages.tasks.components.pending', ['text' => 'Jadwal tugas dan agenda bersama lintas divisi akan tampil setelah API Timeline tersedia.'])
    </div>

    <div x-show="state === 'error'" x-cloak class="mt-3 flex flex-col items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-8 text-center" role="alert">
        <p class="text-sm text-red-700" x-text="message"></p>
        <button type="button" class="{{ $button }}" @click="loadPeriod()">Try Again</button>
    </div>

    <p x-show="state === 'ready' && !rows.length" x-cloak class="mt-3 rounded-lg border border-dashed border-line bg-soft px-4 py-8 text-center text-sm text-muted">
        Tidak ada jadwal pada periode ini.
    </p>

    <div x-show="state === 'ready'" x-cloak class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-primary"></span> Tugas aktif</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-red-600"></span> Terlambat (aktif, lewat deadline)</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-emerald-600"></span> Tugas selesai</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-navy"></span> Agenda bersama</span>
        <span>"1 hari" = hanya memiliki tanggal mulai atau deadline.</span>
        <span x-show="undatedCount" x-text="`${undatedCount} item tanpa tanggal tidak ditampilkan.`"></span>
    </div>
</section>
