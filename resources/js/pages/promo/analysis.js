import { errorMessage } from '../../http';
import { fetchAnalysisFilters } from './api';
import {
    MONTHS,
    PERIOD_MODES,
    SEARCH_MAX_LENGTH,
    defaultFilter,
    filterErrorField,
    isFutureMonth,
    selectionSummary,
    toDateString,
    toQueryString,
    validateFilter,
    withMode,
    yearOptions,
} from './analysis-filter';

/**
 * Filter panel shared by every promo analysis component. Each valid change is broadcast as
 * `promo-analysis-filter` (detail: query string); components report backend 422 errors back
 * with a bubbling `promo-analysis-errors` event.
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
        query: null,
        errors: {},

        init() {
            this.loadOptions();
            this.$watch('filter.search', () => this.apply());
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
            return selectionSummary(this.platformOptions, this.filter.platforms, 'All Platforms', 'platforms');
        },

        get paymentMethodSummary() {
            return selectionSummary(this.paymentMethodOptions, this.filter.payment_methods, 'All Payment Methods', 'methods');
        },

        get filterInvalid() {
            return Object.keys(this.errors).length > 0;
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
            this.apply();
        },

        changeMode() {
            this.filter = withMode(this.filter, this.filter.mode);
            this.apply();
        },

        changeYear() {
            // A month later than today in the newly picked year has no transactions yet.
            if (this.isFutureMonth(this.filter.month)) this.filter.month = String(new Date().getMonth() + 1);
            this.apply();
        },

        resetFilter() {
            this.filter = defaultFilter();
            this.apply();
        },

        apply() {
            this.errors = validateFilter(this.filter);
            if (this.filterInvalid) return;

            // Reset also clears the search, which fires the search watcher with the same query.
            const query = toQueryString(this.filter);
            if (query === this.query) return;

            this.query = query;
            this.$nextTick(() => this.$dispatch('promo-analysis-filter', query));
        },

        showServerErrors(errors) {
            this.errors = Object.fromEntries(
                Object.entries(errors).map(([field, message]) => [filterErrorField(field), message]),
            );
        },
    };
}
