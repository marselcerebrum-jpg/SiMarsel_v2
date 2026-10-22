@php
    $notSet = 'inline-flex items-center rounded-md bg-soft px-2 py-0.5 text-xs font-semibold text-muted';
    $pageButton = 'inline-flex h-8 items-center justify-center gap-1 rounded-lg border border-line bg-white px-3 text-xs font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50';
@endphp

<div class="mt-4 overflow-hidden rounded-lg border border-line">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-left text-sm" :aria-busy="loading">
            <thead class="bg-soft text-xs font-semibold text-label">
                <tr>
                    <th scope="col" class="px-4 py-3">Promo Code</th>
                    <th scope="col" class="px-4 py-3">Platform</th>
                    <th scope="col" class="px-4 py-3">Funnel</th>
                    <th scope="col" class="px-4 py-3">Source</th>
                    <th scope="col" class="px-4 py-3">Usage</th>
                    <th scope="col" class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-line" :class="loading && items.length && 'opacity-50'">
                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-soft/60">
                        <td class="px-4 py-3 font-mono font-semibold break-all text-ink" x-text="item.code"></td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-md bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary-hover" x-text="item.platform.name"></span>
                        </td>
                        <td class="px-4 py-3">
                            <span x-show="item.funnel" class="text-ink" x-text="item.funnel?.name"></span>
                            <span x-show="!item.funnel" class="{{ $notSet }}" title="Funnel belum diisi">Not Set</span>
                        </td>
                        <td class="px-4 py-3">
                            <span x-show="item.source" class="text-ink" x-text="item.source?.name"></span>
                            <span x-show="!item.source" class="{{ $notSet }}" title="Source belum diisi">Not Set</span>
                        </td>
                        <td class="px-4 py-3">
                            <span x-show="item.usages_count" class="text-muted" x-text="item.usages_count + ' transaksi'"></span>
                            <span x-show="!item.usages_count" class="{{ $notSet }}">Unused</span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <button
                                type="button"
                                class="rounded-md px-2 py-1 font-semibold text-primary hover:text-primary-hover focus-visible:outline-2 focus-visible:outline-primary"
                                :aria-label="'Edit ' + item.code"
                                @click="openEdit(item)"
                            >Edit</button>
                            <button
                                type="button"
                                class="ml-2 rounded-md px-2 py-1 font-semibold text-red-600 hover:text-red-700 focus-visible:outline-2 focus-visible:outline-red-600"
                                :aria-label="'Delete ' + item.code"
                                @click="openDelete(item)"
                            >Delete</button>
                        </td>
                    </tr>
                </template>

                <tr x-show="loading && !items.length" x-cloak>
                    <td colspan="6" class="px-4 py-10 text-center text-sm text-muted">Memuat daftar kode promo…</td>
                </tr>

                <tr x-show="!loading && loadError" x-cloak>
                    <td colspan="6" class="px-4 py-10 text-center">
                        <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                        <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="fetchItems()">Retry</button>
                    </td>
                </tr>

                <tr x-show="!loading && !loadError && !items.length && !hasFilter" x-cloak>
                    <td colspan="6" class="px-4 py-10 text-center">
                        <p class="text-sm font-medium text-ink">Belum ada kode promo terdaftar.</p>
                        <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="openCreate()">Add First Promo Code</button>
                    </td>
                </tr>

                <tr x-show="!loading && !loadError && !items.length && hasFilter" x-cloak>
                    <td colspan="6" class="px-4 py-10 text-center">
                        <p class="text-sm font-medium text-ink">Tidak ada kode promo yang cocok.</p>
                        <p class="mt-1 text-xs text-muted">Coba ubah kata kunci atau filter platform.</p>
                        <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="resetFilter()">Reset Filter</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<nav x-show="meta && meta.last_page > 1" x-cloak class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" aria-label="Promo Code Pagination">
    <p class="text-xs text-muted">
        Menampilkan <span class="font-semibold text-ink" x-text="meta?.from ?? 0"></span>–<span class="font-semibold text-ink" x-text="meta?.to ?? 0"></span>
        dari <span x-text="meta?.total ?? 0"></span> kode promo
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
