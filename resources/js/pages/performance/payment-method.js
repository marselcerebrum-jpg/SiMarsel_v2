import { errorMessage } from '../../http';
import { fetchPaymentMethods } from './api';
import { distributionChartConfig, highlightSlice, renderChart } from './chart';
import { formatValue } from './format';
import { paymentMethodRows } from './table';

const EMPTY_MESSAGE = 'Tidak ada data metode pembayaran untuk filter yang dipilih.';

// Backend percentage field for each parameter.
const SHARE_FIELDS = { revenue: 'revenue_percentage', transactions: 'transaction_percentage' };

/**
 * Payment method distribution (GET /api/performance/payment-methods). Uses the same filter as the
 * Performance Summary: it reloads whenever the main filter dispatches `performance-filter`.
 * Switching the parameter only redraws, because the response already holds both shares.
 */
export default (parameters = []) => {
    // Kept outside Alpine's reactive proxy: Chart.js breaks when wrapped in a Proxy.
    let chart = null;

    return {
        paymentParameters: parameters,
        paymentParameter: parameters[0]?.value ?? 'revenue',
        payment: null,
        paymentLoading: true,
        paymentError: '',
        paymentUnavailable: false,
        paymentRequestId: 0,
        paymentQuery: null,

        get paymentMeta() {
            return this.paymentParameters.find(({ value }) => value === this.paymentParameter) ?? this.paymentParameters[0];
        },

        get paymentMethods() {
            return this.payment?.payment_methods ?? [];
        },

        get paymentNoData() {
            return this.payment?.is_empty === true || this.paymentMethods.length === 0;
        },

        /**
         * True when there are transactions but the selected parameter totals zero (e.g. only free
         * transactions), so a pie chart cannot be drawn for it.
         */
        get paymentZeroTotal() {
            return !this.paymentNoData && !(Number(this.payment?.totals?.[this.paymentParameter]) > 0);
        },

        get paymentState() {
            if (this.payment !== null && !this.paymentError && !this.paymentNoData && !this.paymentZeroTotal) return 'ready';
            if (this.paymentLoading) return 'loading';
            if (this.paymentUnavailable) return 'unavailable';
            if (this.paymentError) return 'error';

            return 'empty';
        },

        get paymentMessage() {
            if (this.paymentError) return this.paymentError;
            if (this.paymentZeroTotal) {
                return `Total ${this.paymentMeta.label} bernilai 0 pada filter yang dipilih, sehingga distribusinya tidak dapat ditampilkan.`;
            }

            return this.payment?.message || EMPTY_MESSAGE;
        },

        get paymentRows() {
            return paymentMethodRows(this.paymentMethods, {
                value: this.paymentParameter,
                share: SHARE_FIELDS[this.paymentParameter],
            });
        },

        get paymentTotals() {
            return {
                transactions: formatValue(this.payment?.totals?.transactions, 'number'),
                revenue: formatValue(this.payment?.totals?.revenue, 'currency'),
            };
        },

        get paymentChartLabel() {
            const slices = this.paymentRows.map((row) => `${row.name} ${row.percentage}`).join(', ');

            return `Pie chart distribusi ${this.paymentMeta.label} per metode pembayaran: ${slices}`;
        },

        setPaymentParameter(value) {
            if (this.paymentParameter === value) return;

            this.paymentParameter = value;
            this.drawPayment();
        },

        async loadPayment(query) {
            const requestId = ++this.paymentRequestId;
            this.paymentQuery = query;
            this.paymentLoading = true;
            this.paymentError = '';
            this.paymentUnavailable = false;

            try {
                const { data } = await fetchPaymentMethods(query);
                if (requestId !== this.paymentRequestId) return;

                this.payment = data;
            } catch (error) {
                if (requestId !== this.paymentRequestId) return;

                this.payment = null;
                if (error?.unavailable) this.paymentUnavailable = true;
                else this.paymentError = errorMessage(error, 'Gagal memuat distribusi metode pembayaran.');
            } finally {
                if (requestId === this.paymentRequestId) {
                    this.paymentLoading = false;
                    this.drawPayment();
                }
            }
        },

        retryPayment() {
            if (this.paymentQuery) this.loadPayment(this.paymentQuery);
        },

        drawPayment() {
            if (this.paymentState !== 'ready') {
                chart?.destroy();
                chart = null;
                return;
            }

            // Wait for x-show to reveal the canvas so Chart.js can measure it.
            this.$nextTick(() => {
                chart = renderChart(this.$refs.paymentCanvas, distributionChartConfig({
                    rows: this.paymentRows,
                    metric: this.paymentMeta,
                }), chart);
            });
        },

        highlightPayment(index) {
            highlightSlice(chart, index);
        },

        destroy() {
            chart?.destroy();
            chart = null;
        },
    };
};
