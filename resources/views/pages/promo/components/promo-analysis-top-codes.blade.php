{{-- Five promo codes with the most usages for the active analysis filter. Ranking and revenue come from the backend. --}}
<section
    x-data="promoAnalysisTopCodes"
    @promo-analysis-filter.window="load($event.detail)"
    class="mt-5 border-t border-line pt-5"
    aria-labelledby="promo-top-codes-title"
>
    <h3 id="promo-top-codes-title" class="text-sm font-semibold text-ink">Top 5 Promo Codes</h3>
    <p class="mt-1 text-xs text-muted">Jumlah penggunaan dapat melebihi jumlah transaksi unik karena satu transaksi dapat memakai dua kode promo.</p>

    <div class="mt-4 overflow-hidden rounded-lg border border-line">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[560px] text-left text-sm" :aria-busy="loading.toString()">
                <thead class="bg-soft text-xs font-semibold text-label">
                    <tr>
                        <th scope="col" class="w-24 px-4 py-3">Peringkat</th>
                        <th scope="col" class="px-4 py-3">Kode Promo</th>
                        <th scope="col" class="px-4 py-3 text-right">Jumlah Penggunaan</th>
                        <th scope="col" class="px-4 py-3 text-right">Revenue</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line" :class="loading && codes.length && 'opacity-50'">
                    <template x-for="row in codes" :key="rowKey(row)">
                        <tr class="hover:bg-soft/60">
                            <td class="px-4 py-3 font-semibold text-ink tabular-nums" x-text="row.rank"></td>
                            <th scope="row" class="px-4 py-3 font-mono font-semibold break-words text-ink" x-text="displayCode(row)"></th>
                            <td class="px-4 py-3 text-right text-ink tabular-nums" x-text="format(row.usages, 'number')"></td>
                            <td class="px-4 py-3 text-right whitespace-nowrap text-ink tabular-nums" x-text="format(row.revenue, 'currency')"></td>
                        </tr>
                    </template>

                    <tr x-show="loading && !codes.length" x-cloak>
                        <td colspan="4" class="px-4 py-10 text-center text-sm text-muted">Memuat Top 5 kode promo…</td>
                    </tr>

                    <tr x-show="!loading && loadError" x-cloak>
                        <td colspan="4" class="px-4 py-10 text-center" role="alert">
                            <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                            <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="retry()">Try Again</button>
                        </td>
                    </tr>

                    <tr x-show="!loading && isEmpty" x-cloak>
                        <td colspan="4" class="px-4 py-10 text-center text-sm font-medium text-ink" role="status" x-text="emptyMessage"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>
