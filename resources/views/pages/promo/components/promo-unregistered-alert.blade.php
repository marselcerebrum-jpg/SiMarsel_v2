{{-- Codes from Markaz transactions without a master for their platform. Not based on any active/inactive status. --}}
@php
    $pageButton = 'inline-flex h-8 items-center justify-center gap-1 rounded-lg border border-line bg-white px-3 text-xs font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50';
@endphp

<section
    x-data="promoUnregisteredCodes"
    @promo-codes-changed.window="refresh()"
    class="{{ $card }} min-w-0 p-5"
    :class="total > 0 && 'border-amber-300!'"
    aria-labelledby="unregistered-title"
>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h2 id="unregistered-title" class="text-base font-semibold text-ink">Unregistered Promo Codes</h2>
                <span
                    x-show="!loading || meta"
                    x-cloak
                    class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold"
                    :class="total > 0 ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-700'"
                    x-text="total > 0 ? total + ' Unregistered' : 'All Registered'"
                ></span>
            </div>
            <p class="mt-1 text-sm text-muted">Kode promo dari transaksi Markaz yang belum punya master untuk platformnya.</p>
        </div>

        <label class="relative block w-full sm:max-w-xs" x-show="total > 0 || hasFilter" x-cloak>
            <span class="sr-only">Search Unregistered Codes</span>
            <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-label" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7" />
                <path d="M20 20l-3.5-3.5" />
            </svg>
            <input
                type="search"
                x-model.debounce.400ms="filter.search"
                placeholder="Cari kode atau platform…"
                maxlength="100"
                class="h-9 w-full rounded-lg border border-line bg-white pr-3 pl-9 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
            >
        </label>
    </div>

    <p x-show="total > 0" x-cloak class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
        Transaksi dengan kode ini tetap tersimpan dan tetap dihitung dalam analisis. Mendaftarkan kode dari daftar ini juga menghubungkan
        penggunaan historis yang belum terhubung dan melengkapi klasifikasi yang belum tersedia dengan funnel/source yang didaftarkan.
        Snapshot penggunaan yang sudah terhubung tetap dipertahankan, termasuk nilai kosong.
    </p>

    <div x-show="loading || loadError || items.length || hasFilter" x-cloak class="mt-4 overflow-hidden rounded-lg border border-line">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-left text-sm" :aria-busy="loading">
                <thead class="bg-soft text-xs font-semibold text-label">
                    <tr>
                        <th scope="col" class="px-4 py-3">Raw Code</th>
                        <th scope="col" class="px-4 py-3">Platform</th>
                        <th scope="col" class="px-4 py-3">Usage</th>
                        <th scope="col" class="px-4 py-3">Last Used</th>
                        <th scope="col" class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line" :class="loading && items.length && 'opacity-50'">
                    <template x-for="item in items" :key="item.platform.id + ':' + item.code">
                        <tr class="hover:bg-soft/60">
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1" title="Penulisan asli dari transaksi Markaz">
                                    <template x-for="raw in item.raw_codes" :key="raw">
                                        <span class="rounded-md bg-soft px-1.5 py-0.5 font-mono text-xs font-semibold whitespace-pre text-ink" x-text="raw"></span>
                                    </template>
                                </div>
                                <p x-show="item.raw_codes.some((raw) => raw !== item.code)" class="mt-1 text-xs text-muted">
                                    Didaftarkan sebagai <span class="font-mono font-semibold text-ink" x-text="item.code"></span>
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-md bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary-hover" x-text="item.platform.name"></span>
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-semibold text-ink" x-text="item.usages_count + ' penggunaan'"></p>
                                <p class="text-xs text-muted" x-text="item.transactions_count + ' transaksi'"></p>
                            </td>
                            <td class="px-4 py-3 text-muted">
                                <span x-text="formatUsedOn(item.last_used_on)"></span>
                                <span x-show="item.first_used_on && item.first_used_on !== item.last_used_on" class="block text-xs" x-text="'Sejak ' + formatUsedOn(item.first_used_on)"></span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button
                                    type="button"
                                    class="inline-flex h-8 items-center justify-center rounded-lg bg-primary px-3 text-xs font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                    :aria-label="'Register Code ' + item.code + ' ' + item.platform.name"
                                    @click="register(item)"
                                >Register Code</button>
                            </td>
                        </tr>
                    </template>

                    <tr x-show="loading && !items.length" x-cloak>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-muted">Memeriksa kode promo yang belum terdaftar…</td>
                    </tr>

                    <tr x-show="!loading && loadError" x-cloak>
                        <td colspan="5" class="px-4 py-8 text-center">
                            <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                            <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="fetchItems()">Retry</button>
                        </td>
                    </tr>

                    <tr x-show="!loading && !loadError && !items.length && hasFilter" x-cloak>
                        <td colspan="5" class="px-4 py-8 text-center">
                            <p class="text-sm font-medium text-ink">Tidak ada kode belum terdaftar yang cocok.</p>
                            <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="filter.search = ''">Reset Search</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <p x-show="!loading && !loadError && !items.length && !hasFilter" x-cloak class="mt-4 rounded-lg border border-dashed border-line px-4 py-6 text-center text-sm text-muted">
        Semua kode promo di transaksi Markaz sudah terdaftar di master.
    </p>

    <nav x-show="meta && meta.last_page > 1" x-cloak class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" aria-label="Unregistered Code Pagination">
        <p class="text-xs text-muted">
            Menampilkan <span class="font-semibold text-ink" x-text="meta?.from ?? 0"></span>–<span class="font-semibold text-ink" x-text="meta?.to ?? 0"></span>
            dari <span x-text="meta?.total ?? 0"></span> kode
        </p>

        <div class="flex items-center gap-2">
            <button type="button" class="{{ $pageButton }}" :disabled="loading || filter.page <= 1" @click="goToPage(filter.page - 1)">
                <x-icon name="chevron-left" class="size-3.5" />
                Previous
            </button>
            <span class="text-xs text-muted">
                Halaman <span class="font-semibold text-ink" x-text="meta?.current_page"></span> dari <span x-text="meta?.last_page"></span>
            </span>
            <button type="button" class="{{ $pageButton }}" :disabled="loading || filter.page >= (meta?.last_page ?? 1)" @click="goToPage(filter.page + 1)">
                Next
                <x-icon name="chevron-right" class="size-3.5" />
            </button>
        </div>
    </nav>
</section>
