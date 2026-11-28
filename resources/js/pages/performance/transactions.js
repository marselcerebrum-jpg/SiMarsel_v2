import { errorMessage, http, validationErrors } from '../../http';
import { createRequestTracker } from './filter';
import {
    DATE_BASES,
    EMPTY_MESSAGE,
    MORE_FILTERS,
    PROMO_OPTIONS,
    STATUS_OPTIONS,
    defaultLocalFilter,
    hasLocalFilters,
    isUnsuccessfulSelected,
    listQuery,
    moreFilterCount,
    optionsQuery,
    pageSummary,
    periodLabel,
    statusSummary,
    toggleUnsuccessful,
    toggleValue,
    toneClass,
    transactionDetail,
    transactionRow,
    withPromoChoice,
    withPromoCodes,
} from './transaction-list';

const BASE = '/api/performance/transactions';

const withQuery = (path, query) => (query ? `${path}?${query}` : path);

/** Default endpoints (docs/api/performance-transactions.md); each returns the parsed JSON body. */
export const transactionApi = Object.freeze({
    list: (query) => http(withQuery(BASE, query)),
    options: (query) => http(withQuery(`${BASE}/options`, query)),
    detail: (id) => http(`${BASE}/${encodeURIComponent(id)}`),
});

/** Tells the Dashboard export (FIX-14) the list query actually shown, without the page, as `{ query }`. */
export const LIST_EVENT = 'performance-transaction-filter';

/**
 * Daftar Transaksi (FIX-13-FE-01). Period and platforms come from the global filter (`performance-filter`); the
 * local filters only select list rows, survive a main filter change, and go back to their defaults with Reset
 * Filter Daftar. Aggregate figures elsewhere on the Dashboard never open or filter this list: the list reloads only
 * on `performance-filter` and on its own controls.
 */
export default function performanceTransactions({ api = transactionApi } = {}) {
    const trackList = createRequestTracker();
    const trackOptions = createRequestTracker();
    const trackDetail = createRequestTracker();

    return {
        statusOptions: STATUS_OPTIONS,
        dateBases: DATE_BASES,
        promoOptions: PROMO_OPTIONS,
        moreFilters: MORE_FILTERS,

        mainQuery: null,
        local: defaultLocalFilter(),
        page: 1,

        items: [],
        meta: null,
        listLoading: true,
        listError: '',
        listErrors: {},

        options: { products: [], payment_methods: [], product_types: [], promo_codes: [] },
        optionsLoading: false,
        optionsError: '',

        moreOpen: false,

        detailOpen: false,
        detailId: null,
        detailData: null,
        detailLoading: false,
        detailError: '',

        get rows() {
            return this.items.map(transactionRow);
        },

        get hasRows() {
            return this.rows.length > 0;
        },

        get isEmpty() {
            return !this.listLoading && !this.listError && this.meta !== null && !this.hasRows;
        },

        get emptyMessage() {
            return EMPTY_MESSAGE;
        },

        get pageSummary() {
            return pageSummary(this.meta);
        },

        get periodLabel() {
            return periodLabel(this.meta);
        },

        get lastPage() {
            return this.meta?.last_page ?? 1;
        },

        get hasLocalFilters() {
            return hasLocalFilters(this.local);
        },

        get moreFilterCount() {
            return moreFilterCount(this.local);
        },

        get statusSummary() {
            return statusSummary(this.local.statuses);
        },

        get unsuccessfulSelected() {
            return isUnsuccessfulSelected(this.local.statuses);
        },

        get dateBasisLabel() {
            return DATE_BASES.find(({ value }) => value === this.local.date_basis)?.label ?? '';
        },

        get detail() {
            return this.detailData ? transactionDetail(this.detailData) : null;
        },

        /** The list query without the page, for the export (FIX-14). */
        get exportQuery() {
            return listQuery(this.mainQuery, this.local);
        },

        /** Values of one Filter Lainnya list, plus selected values the new options no longer have, so they stay visible. */
        optionValues(key) {
            const values = this.options[key] ?? [];

            return [...values, ...this.local[key].filter((value) => !values.includes(value))];
        },

        toneClass,

        isSelected(key, value) {
            return this.local[key].includes(value);
        },

        /** A new main filter: the list follows the new period and platforms; the local filters stay as they are. */
        applyFilter(query) {
            this.mainQuery = query;
            this.page = 1;
            this.loadOptions();
            this.load();
        },

        applyLocal() {
            this.page = 1;
            this.load();
        },

        toggleStatus(value) {
            this.local.statuses = toggleValue(this.local.statuses, value);
            this.applyLocal();
        },

        toggleUnsuccessful() {
            this.local.statuses = toggleUnsuccessful(this.local.statuses);
            this.applyLocal();
        },

        setDateBasis(value) {
            if (value === this.local.date_basis) return;

            this.local.date_basis = value;
            this.loadOptions();
            this.applyLocal();
        },

        toggleMore(key, value) {
            const values = toggleValue(this.local[key], value);
            this.local = key === 'promo_codes' ? withPromoCodes(this.local, values) : { ...this.local, [key]: values };
            this.applyLocal();
        },

        setPromo(value) {
            this.local = withPromoChoice(this.local, value);
            this.applyLocal();
        },

        /** Reset Filter Daftar: local defaults (Tanggal Dibuat); the main period and platforms stay. */
        resetLocal() {
            const basisChanged = this.local.date_basis !== defaultLocalFilter().date_basis;
            this.local = defaultLocalFilter();
            if (basisChanged) this.loadOptions();
            this.applyLocal();
        },

        goToPage(page) {
            if (this.listLoading || page < 1 || page > this.lastPage || page === this.page) return;

            this.page = page;
            this.load();
        },

        openMore() {
            this.moreOpen = true;
        },

        closeMore() {
            this.moreOpen = false;
        },

        async load() {
            if (this.mainQuery === null) return;

            const isCurrent = trackList();
            this.listLoading = true;
            this.listError = '';
            this.listErrors = {};
            this.$dispatch(LIST_EVENT, { query: this.exportQuery });

            try {
                const body = await api.list(listQuery(this.mainQuery, this.local, { page: this.page }));
                if (!isCurrent()) return;

                this.items = body.data ?? [];
                this.meta = body.meta ?? null;
                this.page = this.meta?.current_page ?? this.page;
            } catch (error) {
                if (!isCurrent()) return;

                // The filters stay; only the rows of the failed request are dropped.
                this.items = [];
                this.meta = null;
                this.listErrors = error?.status === 422 ? validationErrors(error) : {};
                this.listError = errorMessage(error, 'Gagal memuat daftar transaksi.');
            } finally {
                if (isCurrent()) this.listLoading = false;
            }
        },

        retry() {
            this.load();
        },

        async loadOptions() {
            if (this.mainQuery === null) return;

            const isCurrent = trackOptions();
            this.optionsLoading = true;
            this.optionsError = '';

            try {
                const body = await api.options(optionsQuery(this.mainQuery, this.local));
                if (!isCurrent()) return;

                this.options = { products: [], payment_methods: [], product_types: [], promo_codes: [], ...(body.data ?? {}) };
            } catch (error) {
                if (!isCurrent()) return;

                this.optionsError = errorMessage(error, 'Gagal memuat pilihan filter.');
            } finally {
                if (isCurrent()) this.optionsLoading = false;
            }
        },

        /** Opens the detail panel of one row. A failed detail never touches the list or its filters. */
        openDetail(id) {
            this.detailOpen = true;
            this.detailId = id;
            this.loadDetail();
        },

        async loadDetail() {
            if (this.detailId === null) return;

            const isCurrent = trackDetail();
            this.detailLoading = true;
            this.detailError = '';
            this.detailData = null;

            try {
                const body = await api.detail(this.detailId);
                if (!isCurrent()) return;

                this.detailData = body.data ?? null;
            } catch (error) {
                if (!isCurrent()) return;

                this.detailError = errorMessage(error, 'Gagal memuat detail transaksi.');
            } finally {
                if (isCurrent()) this.detailLoading = false;
            }
        },

        retryDetail() {
            this.loadDetail();
        },

        /** Opens the detail of a group buyer or member from inside the panel. */
        openRelated(id) {
            if (id === null || id === this.detailId) return;

            this.detailId = id;
            this.loadDetail();
        },

        closeDetail() {
            trackDetail();
            this.detailOpen = false;
            this.detailId = null;
            this.detailData = null;
            this.detailError = '';
            this.detailLoading = false;
        },
    };
}
