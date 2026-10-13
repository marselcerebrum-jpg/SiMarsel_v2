import Alpine from 'alpinejs';
import { errorMessage } from '../../http';
import { fetchTrend } from './api';
import { chartConfig, renderChart } from './chart';
import { formatPeriod } from './format';
import { trendRows } from './table';

/**
 * Trend & performance analysis. Reloads whenever the Performance Summary filter
 * dispatches `performance-filter`, or when the metric or period changes.
 */
export default (metrics = []) => {
    // Kept outside Alpine's reactive proxy: Chart.js breaks when wrapped in a Proxy.
    let chart = null;

    return {
        trendMetrics: metrics,
        metric: metrics[0]?.value ?? 'revenue',
        chartType: 'bar',
        granularity: 'daily',

        trendQuery: null,
        points: [],
        trendLoading: true,
        trendError: '',
        trendRequestId: 0,

        get metricMeta() {
            return this.trendMetrics.find(({ value }) => value === this.metric) ?? this.trendMetrics[0];
        },

        get hasPoints() {
            return this.points.length > 0;
        },

        get trendTableRows() {
            return trendRows(this.points, this.metricMeta.format, this.granularity);
        },

        get chartLabel() {
            const first = this.points[0];
            const last = this.points[this.points.length - 1];
            if (!first) return `Grafik ${this.metricMeta.label}`;

            return `Grafik ${this.metricMeta.label} dari ${formatPeriod(first, this.granularity, { long: true })} sampai ${formatPeriod(last, this.granularity, { long: true })}`;
        },

        setMetric(value) {
            if (this.metric === value) return;

            this.metric = value;
            this.fetchTrend();
        },

        setGranularity(value) {
            if (this.granularity === value) return;

            this.granularity = value;
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
                const { data } = await fetchTrend({ ...this.trendQuery, metric: this.metric, granularity: this.granularity });
                if (requestId !== this.trendRequestId) return;

                this.points = data.points ?? [];
            } catch (error) {
                if (requestId !== this.trendRequestId) return;

                this.points = [];
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
                    points: Alpine.raw(this.points),
                    metric: this.metricMeta,
                    granularity: this.granularity,
                }), chart);
            });
        },

        destroy() {
            chart?.destroy();
            chart = null;
        },
    };
};
