import { errorMessage } from '../../http';
import { fetchSummary } from './api';
import { createRequestTracker } from './filter';
import { salesSummaryCards } from './sales-summary-cards';

/** `data.sales_summary` of GET /api/performance/summary for the filter query (null without a preset). */
async function fetchSalesSummaryData(query) {
    const { data } = await fetchSummary(query);

    return data?.sales_summary ?? null;
}

/**
 * Ringkasan Sales (FIX-05-FE-01): Hasil Penjualan, Aktivitas Transaksi and the supporting row. Reloads whenever the
 * global filter dispatches `performance-filter`; Coba Lagi repeats the request with the same filter.
 *
 * `resolve(query)` returns `data.sales_summary` of the summary endpoint.
 */
export default function performanceSalesSummary({ resolve = fetchSalesSummaryData } = {}) {
    const track = createRequestTracker();

    return {
        salesSummaryQuery: null,
        salesSummary: null,
        salesSummaryLoading: true,
        salesSummaryError: '',

        get salesCards() {
            return salesSummaryCards(this.salesSummary);
        },

        applySalesSummaryFilter(query) {
            this.salesSummaryQuery = query;
            this.loadSalesSummary();
        },

        retrySalesSummary() {
            this.loadSalesSummary();
        },

        async loadSalesSummary() {
            if (this.salesSummaryQuery === null) return;

            const isCurrent = track();
            this.salesSummaryLoading = true;
            this.salesSummaryError = '';

            try {
                const data = await resolve(this.salesSummaryQuery);
                if (!isCurrent()) return;

                this.salesSummary = data;
            } catch (error) {
                if (!isCurrent()) return;

                // Drop the previous filter's numbers so they are not read as this filter's result.
                this.salesSummary = null;
                this.salesSummaryError = errorMessage(error, 'Gagal memuat Ringkasan Sales.');
            } finally {
                if (isCurrent()) this.salesSummaryLoading = false;
            }
        },
    };
}
