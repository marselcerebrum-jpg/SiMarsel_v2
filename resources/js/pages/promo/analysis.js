import { errorMessage } from '../../http';
import { fetchAnalysisFilters } from './api';
import {
    MONTHS,
    PERIOD_MODES,
    SEARCH_MAX_LENGTH,
    cloneFilter,
    defaultFilter,
    filterErrorField,
    isFutureMonth,
    periodLabel,
    selectionSummary,
    toDateString,
    toQueryString,
    validateFilter,
    withMode,
    yearOptions,
} from './analysis-filter';

/**
 * Filter panel shared by every promo analysis component. Edits stay in the form until "Terapkan filter";
 * each applied filter is broadcast as `promo-analysis-filter` (detail: query string). Components report
 * backend 422 errors back with a bubbling `promo-analysis-errors` event.
 */
export default function promoAnalysis() {
    return {
        modes: PERIOD_MODES,
        months: MONTHS,
        years: yearOptions(),
        searchMaxLength: SEARCH_MAX_LENGTH,

        options: { platforms: [], payment_methods: [] },
        optionsLoading: true,
        optionsError: '',

        filter: defaultFilter(),
        applied: null,
        query: null,
        errors: {},
        advancedOpen: false,

        init() {
            this.loadOptions();
            this.apply();
        },

        get maxDate() {
            return toDateString(new Date());
        },

        get platformOptions() {
            return this.options.platforms.map(({ slug, name }) => ({ value: slug, label: name }));
        },

        get paymentMethodOptions() {
            return this.options.payment_methods.map((name) => ({ value: name, label: name }));
        },

        get platformSummary() {
            return selectionSummary(this.platformOptions, this.filter.platforms, 'Semua Platform', 'platforms');
        },

        get paymentMethodSummary() {
            return selectionSummary(this.paymentMethodOptions, this.filter.payment_methods, 'Semua Metode Pembayaran', 'methods');
        },

        get filterInvalid() {
            return Object.keys(this.errors).length > 0;
        },

        /**
         * The scope of the data on the page, from the last applied filter.
         */
        get activeFilters() {
            const filter = this.applied;
            if (!filter) return [];

            const search = filter.search.trim();

            return [
                { label: 'Periode', value: periodLabel(filter) },
                { label: 'Platform', value: selectionSummary(this.platformOptions, filter.platforms, 'Semua Platform', 'platform') },
                { label: 'Metode pembayaran', value: selectionSummary(this.paymentMethodOptions, filter.payment_methods, 'Semua Metode Pembayaran', 'metode') },
                ...(search ? [{ label: 'Kode promo', value: search }] : []),
            ];
        },

        isFutureMonth(month) {
            return isFutureMonth(this.filter.year, month);
        },

        async loadOptions() {
            this.optionsLoading = true;
            this.optionsError = '';

            try {
                const { data } = await fetchAnalysisFilters();
                this.options = { platforms: data.platforms ?? [], payment_methods: data.payment_methods ?? [] };
            } catch (error) {
                this.optionsError = errorMessage(error, 'Gagal memuat pilihan filter.');
            } finally {
                this.optionsLoading = false;
            }
        },

        toggle(field, value) {
            const selected = this.filter[field];
            this.filter[field] = selected.includes(value) ? selected.filter((item) => item !== value) : [...selected, value];
        },

        changeMode() {
            this.filter = withMode(this.filter, this.filter.mode);
        },

        changeYear() {
            // A month later than today in the newly picked year has no transactions yet.
            if (this.isFutureMonth(this.filter.month)) this.filter.month = String(new Date().getMonth() + 1);
        },

        resetFilter() {
            this.filter = defaultFilter();
            this.apply();
        },

        apply() {
            this.errors = validateFilter(this.filter);
            if (this.filterInvalid) {
                // An invalid advanced field must be visible to be fixed.
                if (this.errors.search) this.advancedOpen = true;
                return;
            }

            const query = toQueryString(this.filter);
            if (query === this.query) return;

            this.query = query;
            this.applied = cloneFilter(this.filter);
            this.$nextTick(() => this.$dispatch('promo-analysis-filter', query));
        },

        showServerErrors(errors) {
            this.errors = Object.fromEntries(
                Object.entries(errors).map(([field, message]) => [filterErrorField(field), message]),
            );
            if (this.errors.search || this.errors.payment_methods) this.advancedOpen = true;
        },
    };
}
