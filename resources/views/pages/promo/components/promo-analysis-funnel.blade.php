{{-- Transactions and revenue per main funnel for the active analysis filter. Grouping and contribution come from the backend. --}}
<section
    x-data="promoAnalysisFunnel"
    @promo-analysis-filter.window="load($event.detail)"
    class="mt-5 border-t border-line pt-5"
    aria-labelledby="promo-funnel-title"
>
    <h3 id="promo-funnel-title" class="text-sm font-semibold text-ink">Analisis Promo per Funnel</h3>

    <div class="mt-4 overflow-hidden rounded-lg border border-line">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm" :aria-busy="loading.toString()">
                <thead class="bg-soft text-xs font-semibold text-label">
                    <tr>
                        <th scope="col" class="px-4 py-3">Funnel</th>
                        <th scope="col" class="px-4 py-3 text-right">Jumlah Transaksi</th>
                        <th scope="col" class="px-4 py-3 text-right">Jumlah Revenue</th>
                        <th scope="col" class="px-4 py-3 text-right">Kontribusi Funnel (%)</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line" :class="loading && rows.length && 'opacity-50'">
                    <template x-for="row in visibleRows" :key="rowKey(row)">
                        <tr class="hover:bg-soft/60">
                            <th scope="row" class="px-4 py-3 font-medium text-ink" x-text="row.name"></th>
                            <td class="px-4 py-3 text-right text-ink tabular-nums" x-text="format(row.transactions, 'number')"></td>
                            <td class="px-4 py-3 text-right text-ink tabular-nums" x-text="format(row.revenue, 'currency')"></td>
                            <td class="px-4 py-3 text-right text-ink tabular-nums" x-text="format(row.contribution_percentage, 'percent')"></td>
                        </tr>
                    </template>

                    <tr x-show="loading && !rows.length" x-cloak>
                        <td colspan="4" class="px-4 py-10 text-center text-sm text-muted">Memuat rekap funnel…</td>
                    </tr>

                    <tr x-show="!loading && loadError" x-cloak>
                        <td colspan="4" class="px-4 py-10 text-center" role="alert">
                            <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                            <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="retry()">Coba Lagi</button>
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
