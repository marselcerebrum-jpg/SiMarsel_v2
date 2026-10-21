import { errorMessage } from '../../http';
import { fetchPurchaseTimes } from './api';
import { distributionChartConfig, highlightSlice, renderChart } from './chart';
import { formatValue } from './format';
import { purchaseTimeRows } from './table';

const EMPTY_MESSAGE = 'Tidak ada data waktu pembelian untuk filter yang dipilih.';

const METRIC = { label: 'Transactions', format: 'number' };

const TOOLTIP = {
    title: (row) => row.title,
    label: (row) => `Transactions: ${row.transactions}`,
    details: (row) => [`Share: ${row.percentage}`],
};

/**
 * Purchase time distribution (GET /api/performance/purchase-times). Uses the same filter as the
 * Performance Summary: it reloads whenever the main filter dispatches `performance-filter`.
 */
export default (labels = {}) => {
    // Kept outside Alpine's reactive proxy: Chart.js breaks when wrapped in a Proxy.
    let chart = null;

    return {
        purchaseTime: null,
        purchaseTimeLoading: true,
        purchaseTimeError: '',
        purchaseTimeRequestId: 0,
        purchaseTimeQuery: null,

        get purchaseTimeNoData() {
            return this.purchaseTime?.is_empty === true || !(Number(this.purchaseTime?.totals?.transactions) > 0);
        },

        get purchaseTimeState() {
            if (this.purchaseTime !== null && !this.purchaseTimeError && !this.purchaseTimeNoData) return 'ready';
            if (this.purchaseTimeLoading) return 'loading';
            if (this.purchaseTimeError) return 'error';

            return 'empty';
        },

        get purchaseTimeMessage() {
            if (this.purchaseTimeError) return this.purchaseTimeError;

            return this.purchaseTime?.message || EMPTY_MESSAGE;
        },

        get purchaseTimeRows() {
            return purchaseTimeRows(this.purchaseTime?.time_ranges ?? [], labels);
        },

        get purchaseTimeTotal() {
            return formatValue(this.purchaseTime?.totals?.transactions, 'number');
        },

        get purchaseTimeChartLabel() {
            const slices = this.purchaseTimeRows.map((row) => `${row.title} ${row.transactions} transactions`).join(', ');

            return `Purchase time distribution pie chart: ${slices}`;
        },

        async loadPurchaseTime(query) {
            const requestId = ++this.purchaseTimeRequestId;
            this.purchaseTimeQuery = query;
            this.purchaseTimeLoading = true;
            this.purchaseTimeError = '';

            try {
                const { data } = await fetchPurchaseTimes(query);
                if (requestId !== this.purchaseTimeRequestId) return;

                this.purchaseTime = data;
            } catch (error) {
                if (requestId !== this.purchaseTimeRequestId) return;

                this.purchaseTime = null;
                this.purchaseTimeError = errorMessage(error, 'Gagal memuat distribusi waktu pembelian.');
            } finally {
                if (requestId === this.purchaseTimeRequestId) {
                    this.purchaseTimeLoading = false;
                    this.drawPurchaseTime();
                }
            }
        },

        retryPurchaseTime() {
            if (this.purchaseTimeQuery !== null) this.loadPurchaseTime(this.purchaseTimeQuery);
        },

        drawPurchaseTime() {
            if (this.purchaseTimeState !== 'ready') {
                chart?.destroy();
                chart = null;
                return;
            }

            // Wait for x-show to reveal the canvas so Chart.js can measure it.
            this.$nextTick(() => {
                chart = renderChart(this.$refs.purchaseTimeCanvas, distributionChartConfig({
                    rows: this.purchaseTimeRows,
                    metric: METRIC,
                    tooltip: TOOLTIP,
                }), chart);
            });
        },

        highlightPurchaseTime(index) {
            highlightSlice(chart, index);
        },

        destroy() {
            chart?.destroy();
            chart = null;
        },
    };
};
