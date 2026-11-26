import { errorMessage } from '../../http';
import { fetchDownloaderTrend } from './api';
import { COMPACT_WIDTH } from './combined-trend';
import {
    completenessLabel,
    downloaderChartConfig,
    downloaderChartLabel,
    downloaderRow,
    downloaderSeries,
    granularityOptions,
} from './downloader-trend';
import { createRequestTracker } from './filter';

/** `data` of GET /api/performance/trend/downloaders for the filter query and the requested grouping. */
async function fetchDownloaderData(query, options) {
    const { data } = await fetchDownloaderTrend(query, options);

    return data;
}

/** Width of the point detail card in px (Tailwind w-64). */
const DETAIL_WIDTH = 256;

const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

/**
 * Downloader section (FIX-08-FE-01): line chart, point detail and the Lihat Rincian table. Reloads whenever the
 * global filter dispatches `performance-filter` and when the grouping changes.
 *
 * `render(canvas, config, previous)` draws the chart (renderChart from ./chart); `resolve(query, options)`
 * returns the `data` of the Downloader trend response.
 */
export default function performanceDownloader({ resolve = fetchDownloaderData, render = null } = {}) {
    const track = createRequestTracker();
    // Kept outside Alpine's reactive proxy: Chart.js breaks when wrapped in a Proxy.
    let chart = null;

    return {
        downloaderSeries: [],

        downloaderQuery: null,
        requestedGranularity: null,
        downloaderGranularity: null,
        availableGranularities: [],
        completeUntil: null,
        downloaderBuckets: [],
        downloaderPoint: null,
        downloaderDetailLeft: 0,
        downloaderLoading: true,
        downloaderError: '',
        detailsOpen: false,

        get hasDownloaderPoints() {
            return this.downloaderBuckets.length > 0;
        },

        get downloaderGranularities() {
            return granularityOptions(this.availableGranularities);
        },

        get downloaderCompleteness() {
            return completenessLabel(this.completeUntil);
        },

        get downloaderPlaceholder() {
            return this.downloaderError || 'Grafik akan tampil setelah data tersedia.';
        },

        get downloaderChartLabel() {
            return downloaderChartLabel(this.downloaderBuckets, this.downloaderGranularity);
        },

        get downloaderRows() {
            return this.downloaderBuckets.map((bucket) => downloaderRow(bucket, this.downloaderGranularity));
        },

        get downloaderDetail() {
            const bucket = this.downloaderPoint === null ? null : this.downloaderBuckets[this.downloaderPoint];

            return bucket ? downloaderRow(bucket, this.downloaderGranularity) : null;
        },

        isDownloaderGranularity(value) {
            return this.downloaderGranularity === value;
        },

        applyDownloaderFilter(query) {
            this.downloaderQuery = query;
            this.requestedGranularity = null;
            this.closeDownloaderPoint();
            this.loadDownloader();
        },

        setDownloaderGranularity(value) {
            if (this.isDownloaderGranularity(value)) return;

            this.requestedGranularity = value;
            this.closeDownloaderPoint();
            this.loadDownloader();
        },

        toggleDetails() {
            this.detailsOpen = !this.detailsOpen;
        },

        async loadDownloader() {
            if (this.downloaderQuery === null) return;

            const isCurrent = track();
            this.downloaderLoading = true;
            this.downloaderError = '';

            try {
                const data = await resolve(this.downloaderQuery, { granularity: this.requestedGranularity });
                if (!isCurrent()) return;

                this.downloaderSeries = downloaderSeries(data.series);
                this.downloaderGranularity = data.granularity;
                this.availableGranularities = data.granularities ?? [];
                this.completeUntil = data.filter?.period?.active?.cutoff_at ?? null;
                this.downloaderBuckets = data.buckets ?? [];
            } catch (error) {
                if (!isCurrent()) return;

                this.downloaderBuckets = [];
                this.downloaderError = errorMessage(error, 'Gagal memuat data Downloader.');
            } finally {
                if (isCurrent()) {
                    this.downloaderLoading = false;
                    this.drawDownloader();
                }
            }
        },

        drawDownloader() {
            if (!this.hasDownloaderPoints || !render) {
                chart?.destroy();
                chart = null;
                return;
            }

            // Wait for Alpine to reveal the canvas so Chart.js can measure it.
            this.$nextTick(() => {
                const canvas = this.$refs.downloaderCanvas;

                chart = render(canvas, downloaderChartConfig({
                    buckets: this.downloaderBuckets,
                    series: this.downloaderSeries,
                    granularity: this.downloaderGranularity,
                    compact: (canvas.parentElement?.clientWidth ?? COMPACT_WIDTH) < COMPACT_WIDTH,
                    onPoint: (index) => this.showDownloaderPoint(index),
                    onLeave: () => this.closeDownloaderPoint(),
                }), chart);
            });
        },

        /** Opens the detail of one point (hover or tap) and keeps the card inside the chart. */
        showDownloaderPoint(index) {
            if (!this.hasDownloaderPoints) return;

            const next = clamp(index, 0, this.downloaderBuckets.length - 1);
            if (next === this.downloaderPoint) return;

            this.downloaderPoint = next;
            if (!chart) return;

            const x = chart.scales.x.getPixelForValue(next);
            this.downloaderDetailLeft = clamp(x - DETAIL_WIDTH / 2, 0, Math.max(chart.width - DETAIL_WIDTH, 0));
            chart.options.plugins.activeGuide.index = next;
            chart.update('none');
        },

        closeDownloaderPoint() {
            if (this.downloaderPoint === null) return;

            this.downloaderPoint = null;
            if (!chart) return;

            chart.options.plugins.activeGuide.index = null;
            chart.update('none');
        },

        destroy() {
            chart?.destroy();
            chart = null;
        },
    };
}
