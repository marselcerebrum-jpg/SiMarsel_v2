import { errorMessage, validationErrors } from '../../http';
import { formatValue, trendBadge } from '../../format';
import { fetchAnalysisSummary } from './api';
import { SUMMARY_COLUMNS, copySnapshot } from './analysis-copy';

/**
 * Up/down change against the previous period, or null when the previous value is 0 or null:
 * there is no valid base to compare with.
 */
export function comparison(metric) {
    const previous = metric?.previous_value;
    if (previous === null || previous === undefined || Number(previous) === 0) return null;

    return trendBadge(metric);
}

/**
 * KPI cards for the active promo analysis filter, compared with the equivalent previous period.
 * Every value and comparison comes from GET /api/promo-analysis/summary. `metrics` are the cards
 * ({ key, label }) in page order, which Copy also follows.
 */
export default function promoAnalysisSummary(metrics = []) {
    return {
        query: null,
        summary: null,
        loading: true,
        loadError: '',
        requestId: 0,

        get kpis() {
            return this.summary?.kpis ?? {};
        },

        get isEmpty() {
            return this.summary?.is_empty === true;
        },

        get emptyMessage() {
            return this.summary?.message || 'Data tidak tersedia untuk filter yang dipilih.';
        },

        value(key, format) {
            return formatValue(this.kpis[key]?.value ?? null, format);
        },

        comparison(key) {
            return comparison(this.kpis[key]);
        },

        async load(query) {
            const requestId = ++this.requestId;

            this.query = query;
            this.loading = true;
            this.loadError = '';

            try {
                const { data } = await fetchAnalysisSummary(query);
                if (requestId !== this.requestId) return;

                this.summary = data;
            } catch (error) {
                if (requestId !== this.requestId) return;

                // Drop the previous filter's result so the cards do not show it as this one.
                this.summary = null;

                if (error.status === 422 && error.data?.errors) {
                    this.$dispatch('promo-analysis-errors', validationErrors(error));
                } else {
                    this.loadError = errorMessage(error, 'Gagal memuat ringkasan promo.');
                }
            } finally {
                if (requestId === this.requestId) this.loading = false;
            }
        },

        retry() {
            this.load(this.query);
        },

        provideCopy(detail) {
            if (detail.target !== 'summary') return;

            const rows = this.summary
                ? metrics.map(({ key, label }) => ({
                    label,
                    value: this.kpis[key]?.value,
                    change: this.comparison(key) ? this.kpis[key].change_percentage : null,
                }))
                : [];
            detail.snapshot = copySnapshot(this, SUMMARY_COLUMNS, rows);
        },
    };
}
