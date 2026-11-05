import Alpine from 'alpinejs';
import { errorMessage, validationErrors } from '../../http';
import { fetchAnalysisTrend } from './api';
import { renderChart, trendChartConfig } from './chart';

// The backend limits daily and weekly buckets to this many days.
const MAX_SHORT_PERIOD_DAYS = 366;

/**
 * Days covered by the filter query; 0 for the month modes, which are always within the limit.
 */
export function rangeDays(query) {
    const params = new URLSearchParams(query);
    const from = params.get('date_from');
    const to = params.get('date_to');
    if (!from || !to) return 0;

    return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000) + 1;
}

export function withPeriod(query, period) {
    const params = new URLSearchParams(query);
    params.set('period', period);

    return params.toString();
}

/**
 * Promo transactions and revenue over time with the total sales overlay (GET /api/promo-analysis/trend).
 * Reloads on every `promo-analysis-filter` change and when the daily/weekly/monthly bucket changes.
 */
export default function promoAnalysisTrend() {
    // Kept outside Alpine's reactive proxy: Chart.js breaks when wrapped in a Proxy.
    let chart = null;

    return {
        period: 'daily',
        query: null,
        result: null,
        loading: true,
        loadError: '',
        requestId: 0,

        /**
         * A range longer than the limit only allows monthly; the chosen bucket comes back once the range fits.
         */
        get activePeriod() {
            return this.isPeriodDisabled(this.period) ? 'monthly' : this.period;
        },

        get hasPoints() {
            return Boolean(this.result) && !this.isEmpty && (this.result.labels ?? []).length > 0;
        },

        get isEmpty() {
            return this.result?.is_empty === true;
        },

        get placeholder() {
            if (this.loadError) return this.loadError;
            if (this.isEmpty) return this.result.message || 'Data tidak tersedia untuk filter yang dipilih.';

            return 'Grafik akan tampil setelah data tersedia.';
        },

        get chartLabel() {
            if (!this.hasPoints) return 'Grafik tren penggunaan promo';

            const { labels } = this.result;

            return `Grafik transaksi promo, revenue promo, dan total penjualan dari ${labels[0]} sampai ${labels[labels.length - 1]}`;
        },

        isPeriodDisabled(value) {
            return value !== 'monthly' && this.query !== null && rangeDays(this.query) > MAX_SHORT_PERIOD_DAYS;
        },

        setPeriod(value) {
            if (this.activePeriod === value || this.isPeriodDisabled(value)) return;

            this.period = value;

            return this.fetch();
        },

        load(query) {
            this.query = query;

            return this.fetch();
        },

        retry() {
            return this.fetch();
        },

        async fetch() {
            if (this.query === null) return;

            const requestId = ++this.requestId;
            this.loading = true;
            this.loadError = '';

            try {
                const { data } = await fetchAnalysisTrend(withPeriod(this.query, this.activePeriod));
                if (requestId !== this.requestId) return;

                this.result = data;
            } catch (error) {
                if (requestId !== this.requestId) return;

                // Drop the previous filter's chart so it is not shown as this one.
                this.result = null;

                if (error.status === 422 && error.data?.errors) {
                    this.$dispatch('promo-analysis-errors', validationErrors(error));
                } else {
                    this.loadError = errorMessage(error, 'Gagal memuat grafik tren promo.');
                }
            } finally {
                if (requestId === this.requestId) {
                    this.loading = false;
                    this.draw();
                }
            }
        },

        draw() {
            if (!this.hasPoints) {
                chart?.destroy();
                chart = null;
                return;
            }

            this.$nextTick(() => {
                const { labels, datasets } = Alpine.raw(this.result);
                chart = renderChart(this.$refs.canvas, trendChartConfig({ labels, datasets }), chart);
            });
        },

        destroy() {
            chart?.destroy();
            chart = null;
        },
    };
}
