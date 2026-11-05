{{-- Usages and attributed revenue per promo code for the active analysis filter. Sorting, search, pagination, and attribution come from the backend. --}}
@php
    $columns = [
        ['key' => 'code', 'label' => 'Kode Promo', 'align' => ''],
        ['key' => 'platform', 'label' => 'Platform', 'align' => ''],
        ['key' => 'funnel', 'label' => 'Kategori/Funnel', 'align' => ''],
        ['key' => 'source', 'label' => 'Source', 'align' => ''],
        ['key' => 'usages', 'label' => 'Jumlah Penggunaan', 'align' => 'text-right'],
        ['key' => 'revenue', 'label' => 'Jumlah Revenue', 'align' => 'text-right'],
        ['key' => 'contribution', 'label' => 'Kontribusi (%)', 'align' => 'text-right'],
    ];
    $pageButton = 'inline-flex h-8 items-center justify-center gap-1 rounded-lg border border-line bg-white px-3 text-xs font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50';
@endphp

<section
    x-data="promoAnalysisCode"
    @promo-analysis-filter.window="load($event.detail)"
    class="mt-5 border-t border-line pt-5"
    aria-labelledby="promo-code-summary-title"
>
    <h3 id="promo-code-summary-title" class="text-sm font-semibold text-ink">Summary by Promo Code</h3>

    <div class="mt-4 overflow-hidden rounded-lg border border-line">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[880px] text-left text-sm" :aria-busy="loading.toString()">
                <thead class="bg-soft text-xs font-semibold text-label">
                    <tr>
                        @foreach ($columns as $column)
                            <th scope="col" class="px-4 py-3 {{ $column['align'] }}" :aria-sort="ariaSort('{{ $column['key'] }}')">
                                <button type="button" class="inline-flex items-center gap-1 whitespace-nowrap hover:text-ink focus-visible:outline-2 focus-visible:outline-primary" :disabled="loading" @click="sortBy('{{ $column['key'] }}')">
                                    {{ $column['label'] }}
                                    <x-icon name="chevron-down" class="size-3 transition-transform"
                                        ::class="sort.key !== '{{ $column['key'] }}' ? 'opacity-30' : (sort.dir === 'asc' ? 'rotate-180' : '')" />
                                </button>
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-line" :class="loading && rows.length && 'opacity-50'">
                    <template x-for="row in rows" :key="rowKey(row)">
                        <tr class="cursor-pointer hover:bg-soft/60" @click="openDetail(row)">
                            <th scope="row" class="px-4 py-3 font-normal">
                                <button
                                    type="button"
                                    class="font-mono font-semibold break-all text-ink hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                                    :aria-label="'Lihat detail penggunaan ' + row.code"
                                    @click.stop="openDetail(row)"
                                    x-text="row.code"
                                ></button>
                                <span
                                    x-show="!row.is_registered"
                                    class="ml-1 inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700"
                                    title="Kode belum terdaftar di master kode promo"
                                >Unregistered</span>
                                <p x-show="!row.is_registered" class="mt-1 text-xs text-muted">
                                    Raw code: <span class="font-mono break-all" x-text="rawCodes(row)"></span>
                                </p>
                            </th>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-md bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary-hover" x-text="row.platform.name"></span>
                            </td>
                            <td class="px-4 py-3 text-ink" x-text="row.funnel?.name ?? '—'"></td>
                            <td class="px-4 py-3 text-ink" x-text="row.source?.name ?? '—'"></td>
                            <td class="px-4 py-3 text-right text-ink tabular-nums" x-text="format(row.usages, 'number')"></td>
                            <td class="px-4 py-3 text-right text-ink tabular-nums" x-text="format(row.revenue, 'currency')"></td>
                            <td class="px-4 py-3 text-right text-ink tabular-nums" x-text="format(row.contribution_percentage, 'percent')"></td>
                        </tr>
                    </template>

                    <tr x-show="loading && !rows.length" x-cloak>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-muted">Memuat rekap kode promo…</td>
                    </tr>

                    <tr x-show="!loading && loadError" x-cloak>
                        <td colspan="7" class="px-4 py-10 text-center" role="alert">
                            <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                            <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="retry()">Try Again</button>
                        </td>
                    </tr>

                    <tr x-show="!loading && !loadError && isEmpty" x-cloak>
                        <td colspan="7" class="px-4 py-10 text-center text-sm font-medium text-ink" role="status" x-text="emptyMessage"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <nav x-show="meta && meta.last_page > 1" x-cloak class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" aria-label="Promo Code Summary Pagination">
        <p class="text-xs text-muted">
            Menampilkan <span class="font-semibold text-ink" x-text="meta?.from ?? 0"></span>–<span class="font-semibold text-ink" x-text="meta?.to ?? 0"></span>
            dari <span x-text="meta?.total ?? 0"></span> kode promo
        </p>

        <div class="flex items-center gap-2">
            <button type="button" class="{{ $pageButton }}" :disabled="loading || page <= 1" @click="goToPage(page - 1)">
                <x-icon name="chevron-left" class="size-3.5" />
                Previous
            </button>
            <span class="text-xs text-muted">
                Halaman <span class="font-semibold text-ink" x-text="meta?.current_page"></span> dari <span x-text="meta?.last_page"></span>
            </span>
            <button type="button" class="{{ $pageButton }}" :disabled="loading || page >= (meta?.last_page ?? 1)" @click="goToPage(page + 1)">
                Next
                <x-icon name="chevron-right" class="size-3.5" />
            </button>
        </div>
    </nav>

    @include('pages.promo.components.promo-analysis-code-usage-modal')
</section>
