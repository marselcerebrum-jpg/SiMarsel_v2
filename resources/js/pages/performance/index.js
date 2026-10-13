import '../../../css/pages/performance.css';
import Alpine from 'alpinejs';
import { errorMessage, validationErrors } from '../../http';
import { fetchFilterOptions, fetchSummary } from './api';
import { PERIOD_PRESETS, defaultFilter, filterFromUrl, presetRange, syncUrl, toQuery, today, validateRange } from './filter';
import { formatDateTime, formatRange, formatValue, trendBadge } from './format';
import { comparisonRows } from './table';
import performanceTrend from './trend';

const COUNT_DURATION = 900;
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

Alpine.data('performanceSummary', (definitions = []) => ({
    definitions,
    presets: PERIOD_PRESETS,
    platforms: [],

    filter: filterFromUrl(),
    errors: {},

    summary: null,
    display: {},
    loading: true,
    loadError: '',
    loadedAt: null,
    requestId: 0,
    frame: null,

    init() {
        this.loadPlatforms();
        this.load();
    },

    get maxDate() {
        return today();
    },

    get metrics() {
        return this.summary?.metrics ?? {};
    },

    get periodLabel() {
        return formatRange(this.summary?.period);
    },

    get previousPeriodLabel() {
        return formatRange(this.summary?.previous_period);
    },

    get loadedLabel() {
        return this.loadedAt ? formatDateTime(this.loadedAt) : '';
    },

    get platformLabel() {
        return this.platforms.find(({ value }) => value === this.filter.platform)?.label ?? 'All Platforms';
    },

    get rows() {
        return comparisonRows(this.definitions, this.metrics);
    },

    get skeleton() {
        return this.loading && !this.summary;
    },

    value(key, format, compact = false) {
        return this.summary ? formatValue(this.display[key], format, { compact }) : '—';
    },

    isUnavailable(key) {
        return !this.loading && (this.metrics[key]?.value ?? null) === null;
    },

    trend(key) {
        return trendBadge(this.metrics[key]);
    },

    /**
     * Extra context under a KPI value, built only from values the backend returned.
     */
    detail(key, fallback) {
        const value = (name) => this.metrics[name]?.value;

        if (key === 'total_revenue' && value('total_revenue') != null) {
            return `${formatValue(value('total_revenue'), 'currency')} · ${formatValue(value('transactions'), 'number')} transaksi`;
        }
        if (key === 'conversion_rate' && value('unique_buyers') != null && value('registered_users') != null) {
            return `${formatValue(value('unique_buyers'), 'number')} pembeli / ${formatValue(value('registered_users'), 'number')} downloader`;
        }

        return this.isUnavailable(key) ? 'Data tidak tersedia' : fallback;
    },

    /**
     * Bar width for percentage KPIs, clamped to 0–100.
     */
    progress(key) {
        return `${Math.min(Math.max(Number(this.display[key]) || 0, 0), 100)}%`;
    },

    async loadPlatforms() {
        try {
            const { data } = await fetchFilterOptions();
            this.platforms = data.platforms ?? [];
        } catch {
            // The filter still works with "Semua platform" when options fail to load.
            this.platforms = [];
        }
    },

    changePreset() {
        this.errors = {};

        if (this.filter.preset !== 'custom') {
            Object.assign(this.filter, presetRange(this.filter.preset));
            this.load();
        }
    },

    changeDate() {
        this.filter.preset = 'custom';
        this.applyCustomRange();
    },

    applyCustomRange() {
        this.errors = validateRange(this.filter);
        if (Object.keys(this.errors).length === 0) this.load();
    },

    resetFilter() {
        this.filter = defaultFilter();
        this.load();
    },

    /**
     * Counts every KPI from its currently displayed value to the new one.
     */
    countTo(metrics) {
        cancelAnimationFrame(this.frame);

        const targets = Object.fromEntries(this.definitions.map(({ key }) => [key, metrics?.[key]?.value ?? null]));
        const starts = Object.fromEntries(Object.keys(targets).map((key) => [key, Number(this.display[key]) || 0]));

        if (reducedMotion.matches) {
            this.display = targets;
            return;
        }

        const startedAt = performance.now();
        const step = (now) => {
            const progress = Math.min((now - startedAt) / COUNT_DURATION, 1);
            const eased = 1 - (1 - progress) ** 3;

            this.display = Object.fromEntries(Object.entries(targets).map(([key, target]) => [
                key,
                target === null ? null : starts[key] + (target - starts[key]) * eased,
            ]));

            if (progress < 1) this.frame = requestAnimationFrame(step);
        };

        this.frame = requestAnimationFrame(step);
    },

    async load() {
        const requestId = ++this.requestId;

        this.loading = true;
        this.loadError = '';
        this.errors = {};
        syncUrl(this.filter);

        // Let the trend section (and any other widget) reload with the same filter.
        const query = toQuery(this.filter);
        this.$nextTick(() => this.$dispatch('performance-filter', query));

        try {
            const { data } = await fetchSummary(query);
            if (requestId !== this.requestId) return;

            this.summary = data;
            this.loadedAt = new Date();
            this.countTo(data.metrics);
        } catch (error) {
            if (requestId !== this.requestId) return;

            if (error.status === 422 && error.data?.errors) {
                this.errors = validationErrors(error);
            } else {
                this.loadError = errorMessage(error, 'Gagal memuat ringkasan performa.');
            }
        } finally {
            if (requestId === this.requestId) this.loading = false;
        }
    },
}));

Alpine.data('performanceTrend', performanceTrend);
