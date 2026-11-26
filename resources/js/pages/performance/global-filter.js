import { errorMessage, validationErrors } from '../../http';
import { exportUrl } from './api';
import {
    COMPARISONS,
    CUSTOM_COMPARISON,
    DATE_RANGE_PRESET,
    PRESETS,
    comparisonLabel,
    defaultFilter,
    filterErrorField,
    filterFromUrl,
    presetLabel,
    syncUrl,
    toQueryString,
    validateFilter,
} from './filter';
import { describePeriod } from './period';

export const FILTER_EVENT = 'performance-filter';
export const PERIOD_EVENT = 'performance-period';

export const STATUS = Object.freeze({
    invalid: 'Filter belum diterapkan. Perbaiki isian yang ditandai.',
    failed: 'Filter gagal diterapkan.',
    periodError: 'Gagal memuat periode filter.',
});

export const PERIOD_PENDING = 'Memperbarui periode…';

/**
 * Period panel text while the latest request is pending or after it failed. The previous period is never
 * shown in the meantime, so it cannot be read as the result of the new filter.
 */
function periodNotice(text) {
    return {
        active: text,
        provisional: false,
        provisionalLabel: '',
        comparison: text,
        completeUntil: text,
        snapshot: text,
        change: null,
        durations: null,
    };
}

/**
 * Global Dashboard filter (FIX-04-FE-01). Owns the filter state, the URL and the resolved period, and tells
 * every section the new query through the `performance-filter` window event. Section state (series
 * visibility, chart type, Daftar Transaksi filters) stays in each section and is never touched here.
 *
 * The backend has no separate period endpoint: every performance response carries the resolver result in
 * `data.filter.period`. The page summary request reports it back through `performance-period`
 * ({ query, period } or { query, error }), so the filter adds no request of its own.
 */
export default function dashboardFilter(platforms = [], {
    search = window.location.search,
    writeUrl = syncUrl,
} = {}) {
    return {
        platforms,
        presets: PRESETS,
        comparisons: COMPARISONS,

        filter: filterFromUrl(search),
        filterQuery: null,
        appliedFilter: null,
        filterErrors: {},
        filterStatus: '',
        filterFailed: false,

        period: null,
        periodLoading: true,
        periodFailed: false,

        init() {
            this.applyGlobalFilter();
        },

        get filterInvalid() {
            return Object.keys(this.filterErrors).length > 0;
        },

        get isDateRange() {
            return this.filter.preset === DATE_RANGE_PRESET;
        },

        get isCustomComparison() {
            return this.filter.comparison === CUSTOM_COMPARISON;
        },

        get allPlatformsSelected() {
            return this.filter.platforms.length === 0;
        },

        get platformSummary() {
            const selected = this.platforms.filter(({ value }) => this.filter.platforms.includes(value));
            if (selected.length === 0) return 'Semua Platform';

            return selected.length <= 2 ? selected.map(({ label }) => label).join(', ') : `${selected.length} platform`;
        },

        get periodInfo() {
            if (this.periodLoading) return periodNotice(PERIOD_PENDING);
            if (this.periodFailed) return periodNotice(STATUS.periodError);

            return this.period ? describePeriod(this.period) : null;
        },

        get activeRangeLabel() {
            return this.periodInfo?.active ?? '';
        },

        isPlatformSelected(slug) {
            return this.filter.platforms.includes(slug);
        },

        togglePlatform(slug) {
            const selected = this.filter.platforms;
            this.filter.platforms = selected.includes(slug) ? selected.filter((value) => value !== slug) : [...selected, slug];
            this.applyGlobalFilter();
        },

        selectAllPlatforms() {
            this.filter.platforms = [];
            this.applyGlobalFilter();
        },

        /**
         * Rentang Tanggal waits for its start and end inputs; other presets apply right away.
         */
        changePreset() {
            if (this.isDateRange) return;

            Object.assign(this.filter, { date_from: '', date_to: '' });
            this.applyGlobalFilter();
        },

        /**
         * Only the comparison changes; the active period and its dates stay as they are.
         * Rentang Pembanding Khusus waits for its start and end inputs.
         */
        changeComparison() {
            if (this.isCustomComparison) return;

            Object.assign(this.filter, { comparison_from: '', comparison_to: '' });
            this.applyGlobalFilter();
        },

        resetFilter() {
            this.filter = defaultFilter();
            this.applyGlobalFilter();
        },

        exportLink(format) {
            return exportUrl(format, this.filterQuery);
        },

        /**
         * Validates the filter, keeps every typed value, then sends the new query to every section. The
         * previous period is dropped; the resolved one arrives with the summary response (receivePeriod).
         */
        applyGlobalFilter() {
            this.filterErrors = validateFilter(this.filter);

            if (this.filterInvalid) {
                this.filterStatus = STATUS.invalid;
                this.filterFailed = true;
                return;
            }

            this.filterQuery = toQueryString(this.filter);
            this.appliedFilter = { ...this.filter };
            this.period = null;
            this.periodLoading = true;
            this.periodFailed = false;
            writeUrl(this.filterQuery);
            this.$nextTick(() => this.$dispatch(FILTER_EVENT, this.filterQuery));
        },

        /**
         * A response for an older query is ignored, so it never replaces the latest selection.
         */
        receivePeriod({ query, period = null, error = null }) {
            if (query !== this.filterQuery) return;

            this.periodLoading = false;

            if (!error) {
                this.period = period;
                this.filterFailed = false;
                this.filterStatus = `Filter diterapkan: ${presetLabel(this.appliedFilter.preset)}, pembanding ${comparisonLabel(this.appliedFilter.comparison)}, ${this.platformSummary}.`;
                return;
            }

            this.period = null;
            this.periodFailed = true;
            this.filterFailed = true;

            if (error.status === 422 && error.data?.errors) {
                this.filterErrors = Object.fromEntries(
                    Object.entries(validationErrors(error)).map(([field, message]) => [filterErrorField(field), message]),
                );
                this.filterStatus = STATUS.invalid;
            } else {
                this.filterStatus = `${STATUS.failed} ${errorMessage(error, STATUS.periodError)}`;
            }
        },
    };
}
