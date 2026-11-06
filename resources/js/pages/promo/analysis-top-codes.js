import { errorMessage, validationErrors } from '../../http';
import { TOP_CODE_COLUMNS, copySnapshot } from './analysis-copy';
import { formatValue } from '../../format';
import { fetchAnalysisTopCodes } from './api';

/**
 * Five promo codes with the most usages for the active promo analysis filter.
 * Ranking, usages, and revenue come from GET /api/promo-analysis/top-codes.
 */
export default function promoAnalysisTopCodes() {
    return {
        query: null,
        result: null,
        loading: true,
        loadError: '',
        requestId: 0,

        get codes() {
            return this.result?.codes ?? [];
        },

        get isEmpty() {
            return this.result?.is_empty === true;
        },

        get emptyMessage() {
            return this.result?.message || 'Tidak ada penggunaan kode promo pada filter yang dipilih.';
        },

        rowKey(row) {
            return `${row.platform.id}:${row.code}`;
        },

        /**
         * An unregistered code has no master yet, so it is shown with its raw code(s) from Markaz.
         */
        displayCode(row) {
            const raw = row.is_registered ? [] : (row.raw_codes ?? []).map((code) => code.trim()).filter(Boolean);

            return raw.length ? [...new Set(raw)].join(', ') : row.code;
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
                const { data } = await fetchAnalysisTopCodes(query);
                if (requestId !== this.requestId) return;

                this.result = data;
            } catch (error) {
                if (requestId !== this.requestId) return;

                // Drop the previous filter's result so the widget does not show it as this one.
                this.result = null;

                if (error.status === 422 && error.data?.errors) {
                    this.$dispatch('promo-analysis-errors', validationErrors(error));
                } else {
                    this.loadError = errorMessage(error, 'Gagal memuat Top 5 kode promo.');
                }
            } finally {
                if (requestId === this.requestId) this.loading = false;
            }
        },

        retry() {
            return this.load(this.query);
        },

        provideCopy(detail) {
            if (detail.target === 'top-codes') detail.snapshot = copySnapshot(this, TOP_CODE_COLUMNS, this.codes.map((row) => ({ ...row, display_code: this.displayCode(row) })));
        },
    };
}
