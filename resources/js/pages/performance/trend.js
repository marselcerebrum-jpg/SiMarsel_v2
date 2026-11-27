import { errorMessage } from '../../http';
import { fetchSalesTrend } from './api';
import {
    COMPACT_WIDTH,
    GRANULARITIES,
    chartLabel,
    combinedTrendConfig,
    defaultVisibility,
    pointDetail,
    trendSeries,
} from './combined-trend';
import { createRequestTracker } from './filter';

/** `data` of GET /api/performance/trend/sales for the filter query and the requested grouping. */
async function fetchTrendData(query, options) {
    const { data } = await fetchSalesTrend(query, options);

    return data;
}

/** Tells the Dashboard export (FIX-14) the grouping actually shown, as `{ granularity }`. */
export const GRANULARITY_EVENT = 'performance-trend-granularity';

/** Width of the point detail card in px (Tailwind w-64). */
const DETAIL_WIDTH = 256;

const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

/**
 * Combined trend chart (FIX-06-FE-01). Reloads whenever the global filter dispatches `performance-filter`
 * and when the grouping changes. Series visibility is local state: it survives grouping and filter changes.
 *
 * `render(canvas, config, previous)` draws the chart (renderChart from ./chart); `resolve(query, options)`
 * returns the `data` of the trend response.
 */
export default function performanceTrend({ resolve = fetchTrendData, render = null } = {}) {
    const track = createRequestTracker();
    // Kept outside Alpine's reactive proxy: Chart.js breaks when wrapped in a Proxy.
    let chart = null;

    return {
        granularities: GRANULARITIES,
        series: [],

        trendQuery: null,
        requestedGranularity: null,
        granularity: null,
        allowedGranularities: [],
        buckets: [],
        visibility: {},
        pointIndex: null,
        detailLeft: 0,
        trendLoading: true,
        trendError: '',

        get hasPoints() {
            return this.buckets.length > 0;
        },

        get trendPlaceholder() {
            return this.trendError || 'Grafik akan tampil setelah data tersedia.';
        },

        get chartLabel() {
            return chartLabel(this.buckets, this.granularity);
        },

        get detail() {
            const bucket = this.pointIndex === null ? null : this.buckets[this.pointIndex];

            return bucket ? pointDetail(bucket, this.series, this.visibility) : null;
        },

        isGranularityActive(value) {
            return this.granularity === value;
        },

        /** Groupings the backend does not accept for the active range (`granularities`). */
        isGranularityDisabled(value) {
            return this.granularity !== null && !this.allowedGranularities.includes(value);
        },

        isSeriesVisible(key) {
            return this.visibility[key] === true;
        },

        applyFilter(query) {
            this.trendQuery = query;
            this.requestedGranularity = null;
            this.closePoint();
            this.load();
        },

        setGranularity(value) {
            if (this.isGranularityActive(value) || this.isGranularityDisabled(value)) return;

            this.requestedGranularity = value;
            this.closePoint();
            this.load();
        },

        /**
         * Shows or hides one series. Hiding every series is allowed; the legend stays to turn them back on.
         */
        toggleSeries(key) {
            this.visibility[key] = !this.visibility[key];

            if (!chart) return;
            chart.data.datasets.forEach((dataset, index) => {
                dataset.hidden = !this.visibility[this.series[index].key];
            });
            chart.update('none');
        },

        async load() {
            if (this.trendQuery === null) return;

            const isCurrent = track();
            this.trendLoading = true;
            this.trendError = '';

            try {
                const data = await resolve(this.trendQuery, { granularity: this.requestedGranularity });
                if (!isCurrent()) return;

                this.series = trendSeries(data.series);
                // Only series seen for the first time take the response default; the rest keep the user's choice.
                this.visibility = { ...defaultVisibility(this.series), ...this.visibility };
                this.granularity = data.granularity;
                this.allowedGranularities = data.granularities ?? [];
                this.buckets = data.buckets ?? [];
                this.$dispatch(GRANULARITY_EVENT, { granularity: this.granularity });
            } catch (error) {
                if (!isCurrent()) return;

                this.buckets = [];
                this.trendError = errorMessage(error, 'Gagal memuat data tren.');
            } finally {
                if (isCurrent()) {
                    this.trendLoading = false;
                    this.draw();
                }
            }
        },

        draw() {
            if (!this.hasPoints || !render) {
                chart?.destroy();
                chart = null;
                return;
            }

            // Wait for Alpine to reveal the canvas so Chart.js can measure it.
            this.$nextTick(() => {
                const canvas = this.$refs.canvas;

                chart = render(canvas, combinedTrendConfig({
                    buckets: this.buckets,
                    series: this.series,
                    granularity: this.granularity,
                    visibility: this.visibility,
                    compact: (canvas.parentElement?.clientWidth ?? COMPACT_WIDTH) < COMPACT_WIDTH,
                    onPoint: (index) => this.showPoint(index),
                    onLeave: () => this.closePoint(),
                }), chart);
            });
        },

        /**
         * Opens the detail of one bucket (hover, tap or keyboard) and keeps the card inside the chart.
         */
        showPoint(index) {
            if (!this.hasPoints) return;

            const next = clamp(index, 0, this.buckets.length - 1);
            if (next === this.pointIndex) return;

            this.pointIndex = next;
            if (!chart) return;

            const x = chart.scales.x.getPixelForValue(next);
            this.detailLeft = clamp(x - DETAIL_WIDTH / 2, 0, Math.max(chart.width - DETAIL_WIDTH, 0));
            chart.options.plugins.activeGuide.index = next;
            chart.update('none');
        },

        closePoint() {
            if (this.pointIndex === null) return;

            this.pointIndex = null;
            if (!chart) return;

            chart.options.plugins.activeGuide.index = null;
            chart.update('none');
        },

        /** Arrow keys move the open point; focusing the chart opens the first one. */
        movePoint(step) {
            this.showPoint(this.pointIndex === null ? 0 : this.pointIndex + step);
        },

        firstPoint() {
            this.showPoint(0);
        },

        lastPoint() {
            this.showPoint(this.buckets.length - 1);
        },

        destroy() {
            chart?.destroy();
            chart = null;
        },
    };
}
