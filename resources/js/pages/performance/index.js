import '../../../css/pages/performance.css';
import Alpine from 'alpinejs';
import { errorMessage } from '../../http';
import { fetchSummary } from './api';
import { formatDateTime, formatValue } from '../../format';
import { renderChart } from './chart';
import performanceContribution from './contribution';
import dashboardFilter, { PERIOD_EVENT } from './global-filter';
import performancePaymentMethod from './payment-method';
import performancePurchaseTime from './purchase-time';
import performanceTransactions from './transactions';
import performanceTrend from './trend';
import dashboardShell from './shell';

/**
 * Ringkasan Sales cards. The global filter (./global-filter) owns the filter; this reloads whenever it
 * dispatches `performance-filter` and hands the resolved period of its response (`data.filter.period`) back
 * through `performance-period`.
 */
Alpine.data('performanceSummary', () => ({
    query: null,
    summary: null,
    loading: true,
    loadError: '',
    loadedAt: null,
    requestId: 0,

    get metrics() {
        return this.summary?.kpis ?? {};
    },

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

    value(key, format, compact = false) {
        return this.summary ? formatValue(this.metrics[key]?.value ?? null, format, { compact }) : '—';
    },

    isUnavailable(key) {
        return !this.loading && (this.metrics[key]?.value ?? null) === null;
    },

    /**
     * Extra context under a KPI value, built only from values the backend returned.
     */
    detail(key, fallback) {
        const value = (name) => this.metrics[name]?.value;

        if (this.isEmpty || this.isUnavailable(key)) return 'Data tidak tersedia';

        if (key === 'total_revenue') {
            return `${formatValue(value('total_revenue'), 'currency')} · ${formatValue(value('total_transactions'), 'number')} transaksi`;
        }
        if (key === 'conversion_rate') {
            return `${formatValue(value('total_transactions'), 'number')} transaksi / ${formatValue(value('total_downloaders'), 'number')} downloader`;
        }

        return fallback;
    },

    /**
     * Bar width for percentage KPIs, clamped to 0–100.
     */
    progress(key) {
        return `${Math.min(Math.max(Number(this.metrics[key]?.value) || 0, 0), 100)}%`;
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

            // Drop the previous filter's result so the cards and loaded time do not show it as this one.
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
Alpine.data('performanceTransactions', performanceTransactions);
