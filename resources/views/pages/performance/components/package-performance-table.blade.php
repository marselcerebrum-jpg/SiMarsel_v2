@php
    $exportButton = 'inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50';
    $pageButton = 'grid h-8 min-w-8 place-items-center rounded-lg border border-line bg-white px-2 text-xs font-semibold text-ink tabular-nums hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-40';
@endphp

<section x-data="packagePerformance" class="{{ $card }} min-w-0 p-5" aria-labelledby="package-performance-title">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        @include('pages.performance.components.card-header', [
            'id' => 'package-performance-title',
            'title' => 'Package Performance Details',
        ])

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <label class="relative block sm:w-64">
                <span class="sr-only">Search packages</span>
                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-label" />
                <input
                    type="search"
                    x-model="search"
                    :disabled="status !== 'ready'"
                    placeholder="Cari nama paket…"
                    class="h-9 w-full rounded-lg border border-line bg-white pr-3 pl-9 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:opacity-50"
                >
            </label>

            <div class="grid grid-cols-2 gap-2">
                <button type="button" class="{{ $exportButton }}" :disabled="!rows.length || exporting" @click="exportAs('excel')">
                    <span x-text="exporting === 'excel' ? 'Exporting…' : 'Excel'">Excel</span>
                </button>
                <button type="button" class="{{ $exportButton }}" :disabled="!rows.length || exporting" @click="exportAs('csv')">
                    <span x-text="exporting === 'csv' ? 'Exporting…' : 'CSV'">CSV</span>
                </button>
            </div>
        </div>
    </div>

    <div class="relative mt-5" :aria-busy="loading.toString()">
        <div x-show="status === 'ready'" x-cloak class="relative">
            <div class="overflow-hidden rounded-lg border border-line">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[960px] text-left text-sm">
                        <thead class="bg-soft text-xs font-semibold text-label">
                            <tr>
                                <template x-for="column in columns" :key="column.key">
                                    <th scope="col" class="cursor-help px-4 py-3 whitespace-nowrap" :class="column.type === 'number' && 'text-right'" :title="column.hint" x-text="column.header"></th>
                                </template>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <template x-for="row in pageRows" :key="row.name">
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
            </div>

            <p x-show="!rows.length" class="py-8 text-center text-sm text-muted">
                Tidak ada paket yang cocok dengan "<span class="font-semibold text-ink" x-text="search"></span>".
            </p>

            <div x-show="rows.length" class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-muted" aria-live="polite">
                    Menampilkan <span class="font-semibold text-ink" x-text="`${rangeStart}–${rangeEnd}`"></span>
                    dari <span class="font-semibold text-ink" x-text="rows.length"></span> paket
                    <span x-show="rows.length !== items.length">(hasil pencarian dari <span x-text="items.length"></span> paket)</span>
                </p>

                <nav x-show="pageCount > 1" class="flex items-center gap-1" aria-label="Package table pagination">
                    <button type="button" class="{{ $pageButton }}" :disabled="currentPage === 1" @click="goTo(currentPage - 1)" aria-label="Previous page">
                        <x-icon name="chevron-left" />
                    </button>

                    <template x-for="link in pageLinks" :key="link.key">
                        <span class="contents">
                            <span x-show="link.page === null" class="grid size-8 place-items-center text-xs text-label">…</span>
                            <button
                                x-show="link.page !== null"
                                type="button"
                                class="{{ $pageButton }}"
                                :class="link.page === currentPage && 'border-primary bg-primary text-white hover:bg-primary-hover'"
                                :aria-current="link.page === currentPage ? 'page' : null"
                                :aria-label="`Page ${link.page}`"
                                @click="goTo(link.page)"
                                x-text="link.page"
                            ></button>
                        </span>
                    </template>

                    <button type="button" class="{{ $pageButton }}" :disabled="currentPage === pageCount" @click="goTo(currentPage + 1)" aria-label="Next page">
                        <x-icon name="chevron-right" />
                    </button>
                </nav>
            </div>

            <p class="mt-1 text-xs text-muted">
                Hanya transaksi berhasil yang dihitung. <span class="font-semibold text-ink">Price</span> dihitung per transaksi,
                <span class="font-semibold text-ink">Trx</span> dihitung per hari (hanya hari yang ada penjualannya).
                Arahkan kursor ke judul kolom untuk definisi lengkap.
            </p>

            <div x-show="loading" x-transition.opacity class="absolute inset-0 grid place-items-center rounded-lg bg-surface/80">
                <span class="inline-flex items-center gap-2 rounded-lg border border-line bg-white px-3 py-1.5 text-xs font-semibold text-muted shadow-sm">
                    <span class="size-3.5 animate-spin rounded-full border-2 border-primary border-t-transparent"></span>
                    Memuat data…
                </span>
            </div>
        </div>

        @include('pages.performance.components.card-states', ['placeholder' => 'table', 'emptyText' => 'Tidak ada data performa paket'])
    </div>
</section>
