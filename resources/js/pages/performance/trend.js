import Alpine from 'alpinejs';
import { errorMessage } from '../../http';
import { fetchTrend } from './api';
import { chartConfig, renderChart } from './chart';
import { trendRows } from './table';

// The backend limits daily and weekly periods to this many days.
const MAX_SHORT_PERIOD_DAYS = 366;

function rangeDays({ date_from, date_to }) {
    return Math.round((new Date(date_to) - new Date(date_from)) / 86_400_000) + 1;
}

/**
 * Trend & performance analysis (GET /api/performance/trend). Reloads whenever the Performance Summary filter
 * dispatches `performance-filter`, or when the metric or period changes. Several selected years come back as
 * one dataset per year over Jan–Dec.
 */
export default (metrics = []) => {
    // Kept outside Alpine's reactive proxy: Chart.js breaks when wrapped in a Proxy.
    let chart = null;

    return {
        trendMetrics: metrics,
        metric: metrics[0]?.value ?? 'revenue',
        chartType: 'bar',
        // Null lets the backend pick the filter's default: daily for a month or date range, monthly for years.
        period: null,
        activePeriod: null,

        trendQuery: null,
        trendFilter: null,
        labels: [],
        datasets: [],
        rows: [],
        trendEmpty: false,
        trendMessage: '',
        trendLoading: true,
        trendError: '',
        trendRequestId: 0,

        get metricMeta() {
            return this.trendMetrics.find(({ value }) => value === this.metric) ?? this.trendMetrics[0];
        },

        get hasPoints() {
            return this.labels.length > 0 && this.datasets.length > 0 && !this.trendEmpty;
        },

        get comparesYears() {
            return this.datasets.length > 1;
        },

        get trendPlaceholder() {
            return this.trendError || this.trendMessage || 'Grafik akan tampil setelah data tersedia.';
        },

        get trendTableRows() {
            return this.hasPoints ? trendRows(this.rows, this.metricMeta.format) : [];
        },

        get chartLabel() {
            if (!this.hasPoints) return `Grafik ${this.metricMeta.label}`;
            if (this.comparesYears) return `Grafik ${this.metricMeta.label} per bulan untuk tahun ${this.datasets.map(({ label }) => label).join(', ')}`;

            return `Grafik ${this.metricMeta.label} dari ${this.labels[0]} sampai ${this.labels[this.labels.length - 1]}`;
        },

        /**
         * Daily and weekly are only allowed for a single period of at most 366 days.
         */
        isPeriodDisabled(value) {
            if (value === 'monthly' || !this.trendFilter) return false;

            const years = this.trendFilter.mode === 'years' && this.trendFilter.years.length > 1;
            const days = (this.trendFilter.ranges ?? []).reduce((total, range) => total + rangeDays(range), 0);

            return years || days > MAX_SHORT_PERIOD_DAYS;
        },

        isChartDisabled(value) {
            return value === 'pie' && this.comparesYears;
        },

        setMetric(value) {
            if (this.metric === value) return;

            this.metric = value;
            this.fetchTrend();
        },

        setPeriod(value) {
            if (this.activePeriod === value || this.isPeriodDisabled(value)) return;

            this.period = value;
            this.fetchTrend();
        },

        applyFilter(query) {
            this.trendQuery = query;
            this.period = null;
            this.fetchTrend();
        },

        async fetchTrend() {
            if (this.trendQuery === null) return;

            const requestId = ++this.trendRequestId;
            this.trendLoading = true;
            this.trendError = '';

            try {
                const { data } = await fetchTrend(this.trendQuery, { metric: this.metric, period: this.period });
                if (requestId !== this.trendRequestId) return;

                this.trendFilter = data.filter ?? null;
                this.activePeriod = data.period ?? this.period;
                this.labels = data.labels ?? [];
                this.datasets = data.datasets ?? [];
                this.rows = data.rows ?? [];
                this.trendEmpty = data.is_empty === true;
                this.trendMessage = data.message ?? '';
                if (this.isChartDisabled(this.chartType)) this.chartType = 'bar';
            } catch (error) {
                if (requestId !== this.trendRequestId) return;

                this.labels = [];
                this.datasets = [];
                this.rows = [];
                this.trendEmpty = false;
                this.trendMessage = '';
                this.trendError = errorMessage(error, 'Gagal memuat data tren.');
            } finally {
                if (requestId === this.trendRequestId) {
                    this.trendLoading = false;
                    this.draw();
                }
            }
        },

        draw() {
            if (this.chartType === 'table' || !this.hasPoints) {
                chart?.destroy();
                chart = null;
                return;
            }

            // Wait for x-show to reveal the canvas so Chart.js can measure it.
            this.$nextTick(() => {
                chart = renderChart(this.$refs.canvas, chartConfig({
                    type: this.chartType,
                    labels: Alpine.raw(this.labels),
                    datasets: Alpine.raw(this.datasets),
                    metric: this.metricMeta,
                }), chart);
            });
        },

        destroy() {
            chart?.destroy();
            chart = null;
        },
    };
};
