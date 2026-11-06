import { errorMessage, validationErrors } from '../../http';
import { formatDate, formatValue } from '../../format';
import { fetchAnalysisCodeUsages } from './api';
import { USAGE_COLUMNS, allPages, copyAllSnapshot, runCopy } from './analysis-copy';
import { showToast } from '../../components/toast';

/**
 * Usage detail of one promo code recap row, read-only (no import). Uses the analysis filter
 * that was active when the row was clicked, plus the row's detail params, against
 * GET /api/promo-analysis/codes/usages. Totals always match the clicked row.
 */
export default function promoAnalysisCodeUsage() {
    return {
        open: false,
        row: null,
        filterQuery: '',
        page: 1,
        result: null,
        loading: false,
        loadError: '',
        requestId: 0,
        copying: false,
        copyError: '',

        /**
         * The backend groups transactions per date; the table lists one row per transaction.
         */
        get transactions() {
            return (this.result?.data ?? []).flatMap((day) => day.transactions ?? []);
        },

        get meta() {
            return this.result?.meta ?? null;
        },

        get totals() {
            return this.result?.totals ?? null;
        },

        get isEmpty() {
            return this.result !== null && (this.result.is_empty === true || this.transactions.length === 0);
        },

        get emptyMessage() {
            return this.result?.message || 'Tidak ada penggunaan kode ini untuk filter yang dipilih.';
        },

        format(value, format) {
            return formatValue(value ?? null, format);
        },

        formatDate(value) {
            return formatDate(value);
        },

        show({ row, filterQuery }) {
            this.row = row;
            this.filterQuery = filterQuery ?? '';
            this.page = 1;
            this.result = null;
            this.copyError = '';
            this.open = true;

            return this.fetch();
        },

        close() {
            this.open = false;
            this.row = null;
            this.result = null;
            this.loadError = '';
            this.copyError = '';
            // Ignore a response that arrives after the panel is closed.
            this.requestId++;
            this.loading = false;
        },

        goToPage(page) {
            if (!this.meta || page < 1 || page > this.meta.last_page || page === this.page) return;

            this.page = page;

            return this.fetch();
        },

        retry() {
            return this.fetch();
        },

        /**
         * Copies every transaction of this code for the active filter, not only the page on screen.
         */
        async copy() {
            if (this.copying || !this.row) return;

            const query = this.query();
            const snapshot = copyAllSnapshot(this, USAGE_COLUMNS, this.meta?.total ?? this.transactions.length, () => allPages(
                fetchAnalysisCodeUsages,
                query,
                (result) => (result.data ?? []).flatMap((day) => day.transactions ?? []),
            ));

            this.copying = true;
            this.copyError = '';

            try {
                const { message, error } = await runCopy(snapshot, `detail penggunaan ${this.row.code}`);

                this.copyError = error ?? '';
                if (message) showToast({ title: 'Data Disalin', text: message });
            } finally {
                this.copying = false;
            }
        },

        query() {
            const params = new URLSearchParams(this.filterQuery);
            const { platform_id, code, group } = this.row.detail;

            params.set('platform_id', String(platform_id));
            params.set('code', code);
            if (group) params.set('group', group);
            params.set('page', String(this.page));

            return params.toString();
        },

        async fetch() {
            if (!this.row) return;

            const requestId = ++this.requestId;

            this.loading = true;
            this.loadError = '';

            try {
                const result = await fetchAnalysisCodeUsages(this.query());
                if (requestId !== this.requestId) return;

                this.result = result;
            } catch (error) {
                if (requestId !== this.requestId) return;

                this.result = null;

                const errors = error.status === 422 ? Object.values(validationErrors(error)) : [];
                this.loadError = errors[0] ?? errorMessage(error, 'Gagal memuat detail penggunaan kode promo.');
            } finally {
                if (requestId === this.requestId) this.loading = false;
            }
        },
    };
}
