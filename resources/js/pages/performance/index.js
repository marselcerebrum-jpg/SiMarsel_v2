import '../../../css/pages/performance.css';
import Alpine from 'alpinejs';
import { errorMessage } from '../../http';
import { fetchSummary } from './api';
import { formatDateTime } from '../../format';
import { renderChart } from './chart';
import performanceContribution from './contribution';
import dashboardFilter, { PERIOD_EVENT } from './global-filter';
import performancePaymentMethod from './payment-method';
import performancePurchaseTime from './purchase-time';
import performanceSalesSummary from './sales-summary';
import performanceTrend from './trend';
import dashboardShell from './shell';

/**
 * Page-level load state of the Dashboard header (loaded time, empty notice, Muat Ulang). The global filter
 * (./global-filter) owns the filter; this reloads whenever it dispatches `performance-filter` and hands the
 * resolved period of its response (`data.filter.period`) back through `performance-period`. The Ringkasan Sales
 * cards live in ./sales-summary.
 */
Alpine.data('performanceSummary', () => ({
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
}));

Alpine.data('dashboardFilter', dashboardFilter);
Alpine.data('dashboardShell', dashboardShell);
Alpine.data('performanceTrend', () => performanceTrend({ render: renderChart }));
Alpine.data('performanceContribution', performanceContribution);
Alpine.data('performancePaymentMethod', performancePaymentMethod);
Alpine.data('performancePurchaseTime', performancePurchaseTime);
Alpine.data('performanceSalesSummary', () => performanceSalesSummary());
