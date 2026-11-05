{{-- Unique promo transactions and revenue per product type for the active analysis filter. Grouping comes from the backend. --}}
<section
    x-data="promoAnalysisProductType"
    @promo-analysis-filter.window="load($event.detail)"
    class="mt-5 border-t border-line pt-5"
    aria-labelledby="promo-product-type-title"
>
    <h3 id="promo-product-type-title" class="text-sm font-semibold text-ink">First-Time vs Renewal Promo Analysis</h3>
    <p class="mt-1 text-xs text-muted">Dihitung per transaksi unik yang memakai promo, bukan per penggunaan kode; transaksi dengan dua kode promo dihitung satu kali.</p>

    <div class="mt-4 overflow-hidden rounded-lg border border-line">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[560px] text-left text-sm" :aria-busy="loading.toString()">
                <thead class="bg-soft text-xs font-semibold text-label">
                    <tr>
                        <th scope="col" class="px-4 py-3">Tipe Produk</th>
                        <th scope="col" class="px-4 py-3 text-right">Jumlah Transaksi</th>
                        <th scope="col" class="px-4 py-3 text-right">Revenue</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line" :class="loading && rows.length && 'opacity-50'">
                    <template x-for="row in visibleRows" :key="rowKey(row)">
                        <tr class="hover:bg-soft/60">
                            <th scope="row" class="px-4 py-3 font-medium text-ink" x-text="row.name"></th>
                            <td class="px-4 py-3 text-right text-ink tabular-nums" x-text="format(row.transactions, 'number')"></td>
                            <td class="px-4 py-3 text-right text-ink tabular-nums" x-text="format(row.revenue, 'currency')"></td>
                        </tr>
                    </template>

                    <tr x-show="loading && !rows.length" x-cloak>
                        <td colspan="3" class="px-4 py-10 text-center text-sm text-muted">Memuat rekap tipe produk…</td>
                    </tr>

                    <tr x-show="!loading && loadError" x-cloak>
                        <td colspan="3" class="px-4 py-10 text-center" role="alert">
                            <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                            <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="retry()">Try Again</button>
                        </td>
                    </tr>

                    <tr x-show="!loading && isEmpty" x-cloak>
                        <td colspan="3" class="px-4 py-10 text-center text-sm font-medium text-ink" role="status" x-text="emptyMessage"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>
