@php
    $exportButton = 'inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-line bg-soft px-4 text-xs font-bold tracking-wider text-ink uppercase hover:border-brand-indigo hover:text-brand-indigo disabled:pointer-events-none disabled:opacity-50';
@endphp

<section x-data="packagePerformance" class="{{ $card }} min-w-0 p-5 sm:p-8" aria-labelledby="package-performance-title">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        @include('pages.performance.components.card-header', [
            'id' => 'package-performance-title',
            'eyebrow' => 'Performa Paket',
            'title' => 'Detail Performa Paket',
            'icon' => 'table',
        ])

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <label class="relative block sm:w-64">
                <span class="sr-only">Cari nama paket</span>
                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-label" />
                <input
                    type="search"
                    x-model="search"
                    :disabled="status !== 'ready'"
                    placeholder="Cari nama paket…"
                    class="h-11 w-full rounded-xl border border-line bg-soft pr-4 pl-11 text-sm text-ink placeholder:text-label focus:border-brand-indigo focus:bg-white focus:ring-3 focus:ring-brand-indigo/15 focus:outline-none disabled:opacity-60"
                >
            </label>

            <div class="grid grid-cols-2 gap-2">
                <button type="button" class="{{ $exportButton }}" :disabled="!rows.length || exporting" @click="exportAs('excel')">
                    <x-icon name="file" class="size-4" />
                    <span x-text="exporting === 'excel' ? 'Memproses…' : 'Excel'">Excel</span>
                </button>
                <button type="button" class="{{ $exportButton }}" :disabled="!rows.length || exporting" @click="exportAs('csv')">
                    <x-icon name="file" class="size-4" />
                    <span x-text="exporting === 'csv' ? 'Memproses…' : 'CSV'">CSV</span>
                </button>
            </div>
        </div>
    </div>

    <div class="relative mt-6" :aria-busy="loading.toString()">
        <div x-show="status === 'ready'" x-cloak class="relative">
            <div class="overflow-x-auto rounded-2xl border border-line">
                <table class="w-full min-w-[960px] text-left text-sm">
                    <thead class="bg-soft text-[11px] font-bold tracking-wider text-label uppercase">
                        <tr>
                            <template x-for="column in columns" :key="column.key">
                                <th scope="col" class="px-4 py-3 whitespace-nowrap" :class="column.type === 'number' && 'text-right'" x-text="column.header"></th>
                            </template>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <template x-for="row in rows" :key="row.name">
                            <tr class="hover:bg-soft/60">
                                <template x-for="column in columns" :key="column.key">
                                    <td
                                        class="px-4 py-3 whitespace-nowrap"
                                        :class="column.type === 'number' ? 'text-right tabular-nums text-ink' : 'font-semibold text-ink'"
                                        x-text="cell(row, column)"
                                    ></td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p x-show="!rows.length" class="py-8 text-center text-sm text-muted">
                Tidak ada paket yang cocok dengan "<span class="font-semibold text-ink" x-text="search"></span>".
            </p>

            <p x-show="rows.length" class="mt-3 text-xs text-muted" aria-live="polite">
                Menampilkan <span class="font-semibold text-ink" x-text="rows.length"></span> dari <span x-text="items.length"></span> paket
            </p>

            <div x-show="loading" x-transition.opacity class="absolute inset-0 grid place-items-center rounded-2xl bg-surface/70 backdrop-blur-[1px]">
                <span class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-xs font-semibold text-muted shadow">
                    <span class="size-3.5 animate-spin rounded-full border-2 border-brand-indigo border-t-transparent"></span>
                    Memuat data…
                </span>
            </div>
        </div>

        @include('pages.performance.components.card-states', ['placeholder' => 'table', 'emptyText' => 'Tidak ada data performa paket'])
    </div>
</section>
