import { errorMessage } from '../../http';
import { formatDateTime } from '../../format';
import { fetchSummary } from './api';
import { PERIOD_EVENT } from './global-filter';

/**
 * Page-level summary of the Dashboard: one GET /api/performance/summary per filter. The header reads its loaded
 * time, empty notice and Muat Ulang; the Ringkasan Sales cards (./sales-summary, nested in it) read
 * `data.sales_summary`. The global filter (./global-filter) owns the filter; this reloads whenever it dispatches
 * `performance-filter` and hands the resolved period of its response (`data.filter.period`) back through
 * `performance-period`.
 */
export default function performanceSummary() {
    return {
        query: null,
        summary: null,
        loading: true,
        loadError: '',
        loadedAt: null,
        requestId: 0,

        get isEmpty() {
            return this.summary?.is_empty === true;
        },

        get emptyMessage() {
            if (this.loading || !this.isEmpty) return '';

            return this.summary.message || 'Data tidak ditemukan untuk filter yang dipilih.';
        },

        get loadedLabel() {
            return this.loadedAt ? formatDateTime(this.loadedAt) : '';
        },

        loadSummary(query) {
            this.query = query;
            this.load();
        },

        async load() {
            const requestId = ++this.requestId;

            this.loading = true;
            this.loadError = '';

            try {
                const { data } = await fetchSummary(this.query);
                if (requestId !== this.requestId) return;

                this.summary = data;
                this.loadedAt = new Date();
                this.$dispatch(PERIOD_EVENT, { query: this.query, period: data?.filter?.period ?? null });
            } catch (error) {
                if (requestId !== this.requestId) return;

                // Drop the previous filter's result so the loaded time does not show it as this one.
                this.summary = null;
                this.loadedAt = null;
                this.loadError = errorMessage(error, 'Gagal memuat ringkasan performa.');
                this.$dispatch(PERIOD_EVENT, { query: this.query, error });
            } finally {
                if (requestId === this.requestId) this.loading = false;
            }
        },
    };
}
