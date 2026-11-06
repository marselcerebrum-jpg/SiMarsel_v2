import { errorMessage, validationErrors } from '../../http';
import { formatValue } from '../../format';
import { fetchAnalysisCodes } from './api';
import { CODE_COLUMNS, allPages, copyAllSnapshot } from './analysis-copy';

const TEXT_SORTS = ['code', 'platform', 'funnel', 'source'];

const DEFAULT_SORT = { key: 'revenue', dir: 'desc' };

/**
 * Usages and attributed revenue per promo code for the active promo analysis filter.
 * Sorting, search (the shared filter's `search`), pagination, and attribution are done by
 * GET /api/promo-analysis/codes; clicking a row opens its usage detail.
 */
export default function promoAnalysisCode() {
    return {
        filterQuery: null,
        sort: { ...DEFAULT_SORT },
        page: 1,
        result: null,
        loading: true,
        loadError: '',
        requestId: 0,

        get rows() {
            return this.result?.data ?? [];
        },

        get meta() {
            return this.result?.meta ?? null;
        },

        get isEmpty() {
            return this.result?.is_empty === true || (this.result !== null && this.rows.length === 0);
        },

        get emptyMessage() {
            return this.result?.message || 'Data tidak tersedia untuk filter yang dipilih.';
        },

        rowKey(row) {
            return `${row.platform.id}:${row.code}:${row.detail.group}`;
        },

        format(value, format) {
            return formatValue(value ?? null, format);
        },

        /**
         * Unregistered codes are shown with every raw spelling from Markaz.
         */
        rawCodes(row) {
            return (row.raw_codes ?? []).map((code) => `"${code}"`).join(', ');
        },

        ariaSort(key) {
            if (this.sort.key !== key) return 'none';

            return this.sort.dir === 'asc' ? 'ascending' : 'descending';
        },

        sortBy(key) {
            this.sort = this.sort.key === key
                ? { key, dir: this.sort.dir === 'asc' ? 'desc' : 'asc' }
                : { key, dir: TEXT_SORTS.includes(key) ? 'asc' : 'desc' };
            this.page = 1;

            return this.fetch();
        },

        goToPage(page) {
            if (!this.meta || page < 1 || page > this.meta.last_page || page === this.page) return;

            this.page = page;

            return this.fetch();
        },

        load(filterQuery) {
            this.filterQuery = filterQuery;
            this.page = 1;

            return this.fetch();
        },

        retry() {
            return this.fetch();
        },

        provideCopy(detail) {
            if (detail.target !== 'codes') return;

            const query = this.query();
            detail.snapshot = copyAllSnapshot(this, CODE_COLUMNS, this.meta?.total ?? this.rows.length, () => allPages(fetchAnalysisCodes, query));
        },

        provideExportParams({ params }) {
            params.set('sort', this.sort.key);
            params.set('direction', this.sort.dir);
        },

        query() {
            const params = new URLSearchParams(this.filterQuery ?? '');
            params.set('sort', this.sort.key);
            params.set('direction', this.sort.dir);
            params.set('page', String(this.page));

            return params.toString();
        },

        async fetch() {
            const requestId = ++this.requestId;

            this.loading = true;
            this.loadError = '';

            try {
                const result = await fetchAnalysisCodes(this.query());
                if (requestId !== this.requestId) return;

                this.result = result;
            } catch (error) {
                if (requestId !== this.requestId) return;

                // Drop the previous filter's result so the table does not show it as this one.
                this.result = null;

                if (error.status === 422 && error.data?.errors) {
                    this.$dispatch('promo-analysis-errors', validationErrors(error));
                } else {
                    this.loadError = errorMessage(error, 'Gagal memuat rekap kode promo.');
                }
            } finally {
                if (requestId === this.requestId) this.loading = false;
            }
        },

        openDetail(row) {
            this.$dispatch('promo-code-usage-open', { row, filterQuery: this.filterQuery });
        },
    };
}
