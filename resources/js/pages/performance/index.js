import '../../../css/pages/performance.css';
import Alpine from 'alpinejs';
import { errorMessage, validationErrors } from '../../http';
import { exportUrl, fetchSummary } from './api';
import {
    MAX_YEARS,
    MONTHS,
    PERIOD_MODES,
    canPickMonth,
    defaultFilter,
    filterErrorField,
    filterFromUrl,
    syncUrl,
    toQueryString,
    today,
    validateFilter,
} from './filter';
import { formatDateRange, formatDateTime, formatValue } from './format';
import performanceContribution from './contribution';
import performancePaymentMethod from './payment-method';
import performancePurchaseTime from './purchase-time';
import performanceTrend from './trend';

Alpine.data('performanceSummary', (platforms = [], years = []) => ({
    platforms,
    years,
    modes: PERIOD_MODES,
    months: MONTHS,
    maxYears: MAX_YEARS,

    filter: filterFromUrl(),
    query: '',
    errors: {},

    summary: null,
    loading: true,
    loadError: '',
    loadedAt: null,
    requestId: 0,

    init() {
        this.apply();
    },

    get maxDate() {
        return today();
    },

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

    /**
     * The period actually counted, from the backend's filter block. Several ranges appear when the
     * selected years are not consecutive.
     */
    get periodLabel() {
        return (this.summary?.filter?.ranges ?? []).map(formatDateRange).join(', ');
    },

    get loadedLabel() {
        return this.loadedAt ? formatDateTime(this.loadedAt) : '';
    },

    get monthEnabled() {
        return canPickMonth(this.filter);
    },

    get filterInvalid() {
        return Object.keys(this.errors).length > 0;
    },

    get platformSummary() {
        const selected = this.platforms.filter(({ value }) => this.filter.platforms.includes(value));
        if (selected.length === 0) return 'All Platforms';

        return selected.length <= 2 ? selected.map(({ label }) => label).join(', ') : `${selected.length} platforms`;
    },

    get yearSummary() {
        if (this.filter.years.length === 0) return 'Select year';

        return [...this.filter.years].sort().join(', ');
    },

    exportLink(format) {
        return exportUrl(format, this.query);
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

    togglePlatform(slug) {
        const selected = this.filter.platforms;
        this.filter.platforms = selected.includes(slug) ? selected.filter((value) => value !== slug) : [...selected, slug];
        this.apply();
    },

    toggleYear(year) {
        const selected = this.filter.years;

        if (selected.includes(year)) {
            this.filter.years = selected.filter((value) => value !== year);
        } else if (selected.length < MAX_YEARS) {
            this.filter.years = [...selected, year];
        }

        // A month only applies to a single year; the API rejects a month with several years.
        if (!canPickMonth(this.filter)) this.filter.month = '';
        this.apply();
    },

    isYearDisabled(year) {
        return !this.filter.years.includes(year) && this.filter.years.length >= MAX_YEARS;
    },

    changeMode() {
        const { platforms, mode } = this.filter;
        this.filter = { ...defaultFilter(), platforms, mode };

        // Start each mode on a valid period so the change shows data right away.
        const latest = this.years[0] ?? new Date().getFullYear();
        if (mode === 'years') this.filter.years = [latest];
        if (mode === 'year_range') Object.assign(this.filter, { year_from: this.years[this.years.length - 1] ?? latest, year_to: latest });
        if (mode === 'date_range') Object.assign(this.filter, { date_from: `${today().slice(0, 8)}01`, date_to: today() });
        this.apply();
    },

    resetFilter() {
        this.filter = defaultFilter();
        this.apply();
    },

    /**
     * Validates the filter, then reloads the summary and tells every other card the new query.
     */
    apply() {
        this.errors = validateFilter(this.filter);
        if (this.filterInvalid) return;

        this.query = toQueryString(this.filter);
        syncUrl(this.query);
        this.$nextTick(() => this.$dispatch('performance-filter', this.query));
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
        } catch (error) {
            if (requestId !== this.requestId) return;

            if (error.status === 422 && error.data?.errors) {
                this.errors = Object.fromEntries(
                    Object.entries(validationErrors(error)).map(([field, message]) => [filterErrorField(field), message]),
                );
            } else {
                this.loadError = errorMessage(error, 'Gagal memuat ringkasan performa.');
            }
        } finally {
            if (requestId === this.requestId) this.loading = false;
        }
    },
}));

Alpine.data('performanceTrend', performanceTrend);
Alpine.data('performanceContribution', performanceContribution);
Alpine.data('performancePaymentMethod', performancePaymentMethod);
Alpine.data('performancePurchaseTime', performancePurchaseTime);
