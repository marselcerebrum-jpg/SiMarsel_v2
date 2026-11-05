import { errorMessage, validationErrors } from '../../http';
import { formatValue } from '../../format';
import { fetchAnalysisProductTypes } from './api';

/**
 * Unique promo transactions and their revenue per product type for the active promo analysis filter.
 * Grouping (Perdana, Perpanjangan, other types, Tidak diketahui) comes from GET /api/promo-analysis/product-types.
 */
export default function promoAnalysisProductType() {
    return {
        query: null,
        result: null,
        loading: true,
        loadError: '',
        requestId: 0,

        get rows() {
            return this.result?.product_types ?? [];
        },

        /**
         * An empty result still lists Perdana and Perpanjangan at 0; show the empty message instead.
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
            return `${row.key}:${row.name}`;
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
                const { data } = await fetchAnalysisProductTypes(query);
                if (requestId !== this.requestId) return;

                this.result = data;
            } catch (error) {
                if (requestId !== this.requestId) return;

                // Drop the previous filter's result so the table does not show it as this one.
                this.result = null;

                if (error.status === 422 && error.data?.errors) {
                    this.$dispatch('promo-analysis-errors', validationErrors(error));
                } else {
                    this.loadError = errorMessage(error, 'Gagal memuat rekap tipe produk.');
                }
            } finally {
                if (requestId === this.requestId) this.loading = false;
            }
        },

        retry() {
            return this.load(this.query);
        },
    };
}
