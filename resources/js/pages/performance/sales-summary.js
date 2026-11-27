import { salesSummaryCards } from './sales-summary-cards';

/**
 * Ringkasan Sales (FIX-05-FE-01): Hasil Penjualan, Aktivitas Transaksi and the supporting row. It is nested in the
 * page summary (./page-summary) and reads its `summary`, `loading`, `loadError` and `load()`, so the cards and the
 * header share one GET /api/performance/summary per filter. Coba Lagi repeats that request with the same filter.
 */
export default function performanceSalesSummary() {
    return {
        get salesCards() {
            return salesSummaryCards(this.summary?.sales_summary ?? null);
        },

        get salesSummaryLoading() {
            return this.loading;
        },

        get salesSummaryError() {
            return this.loading ? '' : this.loadError;
        },

        retrySalesSummary() {
            this.load();
        },
    };
}
