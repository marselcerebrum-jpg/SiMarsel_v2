import Alpine from 'alpinejs';
import { BarController, BarElement, CategoryScale, Chart, LinearScale, Tooltip } from 'chart.js';
import { exportCsv, exportExcel } from '../../export';
import { errorMessage } from '../../http';
import {
    fetchPackagePerformance,
    fetchProductFilters,
    fetchTopPackagesByRevenue,
    fetchTopPackagesByVolume,
} from '../../services/products';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip);

const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

const rupiahFormat = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
const numberFormat = new Intl.NumberFormat('id-ID');

const rupiah = (value) => rupiahFormat.format(value ?? 0);
const number = (value) => numberFormat.format(value ?? 0);

// 1500000000 → "1.500 Juta"
const millions = (value) => `${(value / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} Juta`;

const truncate = (text, max) => (text.length > max ? `${text.slice(0, max - 1)}…` : text);

// "Bimbel CPNS 2026… (1 Tahun)": nama dipotong lebih dulu agar masa aktif tetap terlihat,
// karena satu paket bisa dijual dengan beberapa masa aktif.
const packageLabel = (item, max) => {
    if (!item.active_period) return truncate(item.name, max);

    const suffix = ` (${item.active_period})`;

    return `${truncate(item.name, Math.max(8, max - suffix.length))}${suffix}`;
};

const activePeriod = (item) => item.active_period ?? '-';

const currentYear = new Date().getFullYear();

/**
 * Induk halaman Ringkasan Produk: menyimpan filter yang dipakai bersama oleh semua kartu.
 */
Alpine.data('productOverview', () => ({
    months: MONTHS,
    platforms: [],
    // Dipakai sampai daftar tahun dari API tersedia.
    years: [currentYear, currentYear - 1, currentYear - 2],
    optionsError: '',

    filters: {
        platform: '',
        year: currentYear,
        month: '',
    },

    async init() {
        try {
            const { data } = await fetchProductFilters();
            this.platforms = data.platforms;
            this.years = data.years;

            if (!this.years.includes(Number(this.filters.year))) this.filters.year = this.years[0];
        } catch (error) {
            if (!error?.unavailable) this.optionsError = errorMessage(error, 'Gagal memuat pilihan filter.');
        }
    },

    get periodLabel() {
        const platform = this.platforms.find((item) => item.slug === this.filters.platform)?.name ?? 'Semua Platform';
        const period = this.filters.month ? `${MONTHS[this.filters.month - 1]} ${this.filters.year}` : `Tahun ${this.filters.year}`;

        return `${platform} · ${period}`;
    },

    get hasActiveFilter() {
        return this.filters.platform !== '' || this.filters.month !== '';
    },

    resetFilters() {
        this.filters = { platform: '', year: this.years[0], month: '' };
    },
}));

/**
 * State bersama tiap kartu: memuat data sesuai filter induk dan memuat ulang saat filter berubah.
 * status: 'ready' | 'empty' | 'error' | 'unavailable' (API backend belum tersedia).
 */
function remoteData(fetcher) {
    let requestId = 0;

    return {
        items: [],
        status: null,
        loading: true,
        error: '',

        init() {
            this.load();
            this.$watch('filters', () => this.load());
        },

        async load() {
            const current = ++requestId;
            this.loading = true;
            this.error = '';

            try {
                const { data } = await fetcher({ ...this.filters });
                if (current !== requestId) return;

                this.items = data;
                this.status = data.length ? 'ready' : 'empty';
                await this.$nextTick();
                this.render?.();
            } catch (error) {
                if (current !== requestId) return;

                this.items = [];
                this.status = error?.unavailable ? 'unavailable' : 'error';
                this.error = errorMessage(error, 'Gagal memuat data.');
            } finally {
                if (current === requestId) this.loading = false;
            }
        },
    };
}

/**
 * Kartu diagram batang. Instance Chart.js disimpan di luar state Alpine
 * agar tidak dibungkus Proxy reaktif.
 */
function barChartCard(fetcher, { horizontal, value, valueTick, tooltipLines }) {
    let chart = null;

    return {
        ...remoteData(fetcher),

        destroy() {
            chart?.destroy();
        },

        get chartHeight() {
            return horizontal ? `${Math.max(240, this.items.length * 48 + 56)}px` : '340px';
        },

        render() {
            if (!this.items.length) return;

            const labels = this.items.map((item) => item.name);
            const values = this.items.map(value);

            if (chart) {
                chart.data.labels = labels;
                chart.data.datasets[0].data = values;
                chart.update();
                return;
            }

            const items = () => this.items;
            const valueAxis = {
                beginAtZero: true,
                grid: { color: '#dde4ec' },
                border: { display: false },
                ticks: { color: '#6b7f99', font: { size: 11 }, callback: valueTick, maxTicksLimit: 6, precision: 0 },
            };
            const labelAxis = {
                grid: { display: false },
                ticks: {
                    color: '#1e293b',
                    font: { size: horizontal ? 12 : 11, weight: 600 },
                    autoSkip: false,
                    maxRotation: 40,
                    callback(tick) {
                        return horizontal ? packageLabel(items()[tick], 40) : truncate(this.getLabelForValue(tick), 16);
                    },
                },
            };

            Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;

            chart = new Chart(this.$refs.canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        data: values,
                        backgroundColor: horizontal ? '#5d60a2' : '#5d83a2',
                        hoverBackgroundColor: '#5da29f',
                        borderRadius: 8,
                        maxBarThickness: horizontal ? 28 : 48,
                    }],
                },
                options: {
                    indexAxis: horizontal ? 'y' : 'x',
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 400 },
                    scales: horizontal ? { x: valueAxis, y: labelAxis } : { x: labelAxis, y: valueAxis },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1f2639',
                            padding: 12,
                            cornerRadius: 10,
                            displayColors: false,
                            titleFont: { size: 13, weight: 700 },
                            bodyFont: { size: 12 },
                            callbacks: {
                                title: ([item]) => items()[item.dataIndex].name,
                                label: (item) => tooltipLines(items()[item.dataIndex]),
                            },
                        },
                    },
                },
            });
        },
    };
}

Alpine.data('topPackagesByRevenue', () =>
    barChartCard(fetchTopPackagesByRevenue, {
        horizontal: true,
        value: (item) => item.revenue,
        valueTick: millions,
        tooltipLines: (item) => [`Masa Aktif: ${activePeriod(item)}`, `Total Pendapatan: ${rupiah(item.revenue)}`],
    }),
);

Alpine.data('topPackagesByVolume', () =>
    barChartCard(fetchTopPackagesByVolume, {
        horizontal: false,
        value: (item) => item.transactions,
        valueTick: (tick) => number(tick),
        tooltipLines: (item) => [`Masa Aktif: ${activePeriod(item)}`, `Total Transaksi: ${number(item.transactions)}`],
    }),
);

const PERFORMANCE_COLUMNS = [
    { key: 'name', header: 'Nama Paket', width: 34 },
    { key: 'revenue', header: 'Revenue', type: 'number', format: rupiah },
    { key: 'trx', header: 'Trx', type: 'number', format: number },
    { key: 'low_price', header: 'Low Price', type: 'number', format: rupiah },
    { key: 'low_trx', header: 'Low Trx', type: 'number', format: number },
    { key: 'avg_price', header: 'Avg Price', type: 'number', format: rupiah },
    { key: 'avg_trx', header: 'Avg Trx', type: 'number', format: number },
    { key: 'high_price', header: 'High Price', type: 'number', format: rupiah },
    { key: 'high_trx', header: 'High Trx', type: 'number', format: number },
].map((column) => ({ ...column, value: (row) => row[column.key] }));

Alpine.data('packagePerformance', () => ({
    ...remoteData(fetchPackagePerformance),

    columns: PERFORMANCE_COLUMNS,
    search: '',
    exporting: null,

    get rows() {
        const keyword = this.search.trim().toLowerCase();
        if (!keyword) return this.items;

        return this.items.filter((row) => row.name.toLowerCase().includes(keyword));
    },

    cell(row, column) {
        return column.format ? column.format(row[column.key]) : row[column.key];
    },

    fileName() {
        const { platform, year, month } = this.filters;

        return ['detail-performa-paket', platform || 'semua-platform', year, month ? String(month).padStart(2, '0') : 'semua-bulan'].join('_');
    },

    async exportAs(type) {
        if (!this.rows.length || this.exporting) return;

        this.exporting = type;

        try {
            if (type === 'csv') {
                exportCsv(this.fileName(), this.columns, this.rows);
            } else {
                await exportExcel(this.fileName(), this.columns, this.rows);
            }
        } finally {
            this.exporting = null;
        }
    },
}));
