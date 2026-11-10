import { errorMessage, validationErrors } from '../../http';
import { FUNNEL_COLUMNS, copySnapshot } from './analysis-copy';
import { formatValue } from '../../format';
import { fetchAnalysisFunnels } from './api';

/**
 * Transactions and revenue per main funnel for the active promo analysis filter.
 * Grouping and contribution come from GET /api/promo-analysis/funnels.
 */
export default function promoAnalysisFunnel() {
    return {
        query: null,
        result: null,
        loading: true,
        loadError: '',
        requestId: 0,

        get rows() {
            return this.result?.funnels ?? [];
        },

        /**
         * An empty result still lists every group at 0; show the empty message instead.
         */
        get visibleRows() {
            return this.isEmpty ? [] : this.rows;
        },

        get isEmpty() {
            return this.result?.is_empty === true;
        },

        get emptyMessage() {
            return this.result?.message || 'Data tidak tersedia untuk filter yang dipilih.';
        },

        rowKey(row) {
            return `${row.key}:${row.funnel?.id ?? row.name}`;
        },

        format(value, format) {
            return formatValue(value ?? null, format);
        },

        async load(query) {
            const requestId = ++this.requestId;

            this.query = query;
            this.loading = true;
            this.loadError = '';

            try {
                const { data } = await fetchAnalysisFunnels(query);
                if (requestId !== this.requestId) return;

                this.result = data;
            } catch (error) {
                if (requestId !== this.requestId) return;

                // Drop the previous filter's result so the table does not show it as this one.
                this.result = null;

                if (error.status === 422 && error.data?.errors) {
                    this.$dispatch('promo-analysis-errors', validationErrors(error));
                } else {
                    this.loadError = errorMessage(error, 'Gagal memuat rekap funnel.');
                }
            } finally {
                if (requestId === this.requestId) this.loading = false;
            }
        },

        retry() {
            return this.load(this.query);
        },

        provideCopy(detail) {
            if (detail.target === 'funnel') detail.snapshot = copySnapshot(this, FUNNEL_COLUMNS, this.rows);
        },
    };
}
