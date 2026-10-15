import Alpine from 'alpinejs';
import { errorMessage } from '../../http';
import { fetchTrend } from './api';
import { chartConfig, renderChart } from './chart';
import { trendRows } from './table';

/**
 * Trend & performance analysis (GET /api/performance/trend). Reloads whenever the Performance Summary filter
 * dispatches `performance-filter`, or when the metric or period changes.
 */
export default (metrics = []) => {
    // Kept outside Alpine's reactive proxy: Chart.js breaks when wrapped in a Proxy.
    let chart = null;

    return {
        trendMetrics: metrics,
        metric: metrics[0]?.value ?? 'revenue',
        chartType: 'bar',
        period: 'daily',

        trendQuery: null,
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
            return this.rows.length > 0 && !this.trendEmpty;
        },

        get trendPlaceholder() {
            return this.trendError || this.trendMessage || 'Grafik akan tampil setelah data tersedia.';
        },

        get trendTableRows() {
            return this.hasPoints ? trendRows(this.rows, this.metricMeta.format) : [];
        },

        get chartLabel() {
            const first = this.rows[0];
            const last = this.rows[this.rows.length - 1];
            if (!this.hasPoints) return `Grafik ${this.metricMeta.label}`;

            return `Grafik ${this.metricMeta.label} dari ${first.label} sampai ${last.label}`;
        },

        setMetric(value) {
            if (this.metric === value) return;

            this.metric = value;
            this.fetchTrend();
        },

        setPeriod(value) {
            if (this.period === value) return;

            this.period = value;
            this.fetchTrend();
        },

        applyFilter(query) {
            this.trendQuery = query;
            this.fetchTrend();
        },

        async fetchTrend() {
            if (!this.trendQuery) return;

            const requestId = ++this.trendRequestId;
            this.trendLoading = true;
            this.trendError = '';

            try {
                const { data } = await fetchTrend({ ...this.trendQuery, metric: this.metric, period: this.period });
                if (requestId !== this.trendRequestId) return;

                this.rows = data.rows ?? [];
                this.trendEmpty = data.is_empty === true;
                this.trendMessage = data.message ?? '';
            } catch (error) {
                if (requestId !== this.trendRequestId) return;

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
                    rows: Alpine.raw(this.rows),
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
