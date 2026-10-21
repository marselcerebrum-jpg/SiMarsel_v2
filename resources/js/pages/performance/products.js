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

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

const rupiahFormat = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
const numberFormat = new Intl.NumberFormat('id-ID');

const rupiah = (value) => rupiahFormat.format(value ?? 0);
const number = (value) => numberFormat.format(value ?? 0);

// 1500000000 → "1.500 Juta"
const millions = (value) => `${(value / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} Juta`;

const truncate = (text, max) => (text.length > max ? `${text.slice(0, max - 1)}…` : text);

const activePeriod = (item) => item.active_period ?? '-';

// Warna batang mengikuti masa aktif paket (bukan peringkat), jadi warnanya tetap saat filter berubah.
// Tiga warna pertama lolos validator palet (aman buta warna); masa aktif lain memakai slot berikutnya.
const PERIOD_COLORS = new Map([
    ['6 Bulan', '#2a78d6'],
    ['1 Tahun', '#eb6834'],
    ['2 Tahun', '#1baf7a'],
]);
const EXTRA_PERIOD_COLORS = ['#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
const NO_PERIOD_COLOR = '#9aa3ae';
const NO_PERIOD_LABEL = 'Tanpa masa aktif';

const periodColor = (period) => {
    if (!period) return NO_PERIOD_COLOR;

    if (!PERIOD_COLORS.has(period)) {
        PERIOD_COLORS.set(period, EXTRA_PERIOD_COLORS[PERIOD_COLORS.size - 3] ?? NO_PERIOD_COLOR);
    }

    return PERIOD_COLORS.get(period);
};

// Pecah teks per kata menjadi beberapa baris; kata atau baris terakhir yang terlalu panjang dipotong.
const wrap = (text, width, maxLines) => {
    const lines = [];

    for (const word of text.split(' ')) {
        const last = lines.at(-1);

        if (last !== undefined && `${last} ${word}`.length <= width) lines[lines.length - 1] = `${last} ${word}`;
        else lines.push(word);
    }

    const kept = lines.length <= maxLines ? lines : [...lines.slice(0, maxLines - 1), lines.slice(maxLines - 1).join(' ')];

    return kept.map((line) => truncate(line, width));
};

// Perkiraan lebar satu karakter label sumbu (12px, tebal), untuk menghitung muatan per batang.
const LABEL_CHAR_PX = 7.2;
// Di bawah lebar ini per batang, nama paket pasti bertumpuk: tampilkan nomor peringkat saja.
const MIN_LABEL_SLOT_PX = 64;

// Label sumbu: nama paket (maks. 3 baris) lalu masa aktif di baris terakhir, selebar slot batang.
const packageLines = (item, slotWidth) => {
    const width = Math.floor((slotWidth - 12) / LABEL_CHAR_PX);

    return [...wrap(item.name, width, 3), ...(item.active_period ? [truncate(`(${item.active_period})`, width)] : [])];
};

const currentYear = new Date().getFullYear();

/**
 * Induk halaman Product Overview: menyimpan filter yang dipakai bersama oleh semua kartu.
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
function barChartCard(fetcher, { value, valueTick, valueTitle, tooltipLines }) {
    let chart = null;

    return {
        ...remoteData(fetcher),

        destroy() {
            chart?.destroy();
        },

        // Legenda warna: masa aktif yang ada di data, urut sesuai urutan warnanya.
        get legend() {
            const periods = new Set(this.items.map((item) => item.active_period ?? null));
            periods.forEach(periodColor);

            const ordered = [...PERIOD_COLORS.keys()].filter((period) => periods.has(period));
            if (periods.has(null)) ordered.push(null);

            return ordered.map((period) => ({ label: period ?? NO_PERIOD_LABEL, color: periodColor(period) }));
        },

        render() {
            if (!this.items.length) return;

            const labels = this.items.map((item) => item.name);
            const values = this.items.map(value);
            const colors = this.items.map((item) => periodColor(item.active_period));

            if (chart) {
                chart.data.labels = labels;
                Object.assign(chart.data.datasets[0], { data: values, backgroundColor: colors, hoverBackgroundColor: colors });
                chart.update();
                return;
            }

            const items = () => this.items;

            Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;

            chart = new Chart(this.$refs.canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        data: values,
                        backgroundColor: colors,
                        hoverBackgroundColor: colors,
                        borderRadius: 4,
                        maxBarThickness: 64,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 400 },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                color: '#1e293b',
                                font: { size: 12, weight: 600, lineHeight: 1.3 },
                                padding: 8,
                                autoSkip: false,
                                maxRotation: 0,
                                minRotation: 0,
                                // Label mengikuti lebar slot tiap batang agar tidak saling menumpuk.
                                callback(tick) {
                                    const slotWidth = this.width / items().length;

                                    return slotWidth < MIN_LABEL_SLOT_PX ? String(tick + 1) : packageLines(items()[tick], slotWidth);
                                },
                            },
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: '#e3e6ea' },
                            border: { display: false },
                            ticks: { color: '#6b7685', font: { size: 12 }, padding: 8, callback: valueTick, maxTicksLimit: 8, precision: 0 },
                            title: { display: true, text: valueTitle, color: '#6b7685', font: { size: 12, weight: 600 }, padding: { bottom: 8 } },
                        },
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f2340',
                            padding: 12,
                            cornerRadius: 8,
                            displayColors: false,
                            titleFont: { size: 13, weight: 700 },
                            bodyFont: { size: 12 },
                            callbacks: {
                                title: ([item]) => `${item.dataIndex + 1}. ${items()[item.dataIndex].name}`,
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
        value: (item) => item.revenue,
        valueTick: millions,
        valueTitle: 'Total Pendapatan',
        tooltipLines: (item) => [`Masa Aktif: ${activePeriod(item)}`, `Total Pendapatan: ${rupiah(item.revenue)}`],
    }),
);

Alpine.data('topPackagesByVolume', () =>
    barChartCard(fetchTopPackagesByVolume, {
        value: (item) => item.transactions,
        valueTick: (tick) => number(tick),
        valueTitle: 'Jumlah Transaksi',
        tooltipLines: (item) => [`Masa Aktif: ${activePeriod(item)}`, `Total Transaksi: ${number(item.transactions)}`],
    }),
);

// `hint` = definisi metrik (sama dengan docs/api/products.md), tampil saat judul kolom di-hover.
// `excelFormat` dipakai untuk kolom rata-rata agar nilai desimalnya tidak dibulatkan di Excel.
const PERFORMANCE_COLUMNS = [
    { key: 'name', header: 'Package Name', width: 34, hint: 'Nama produk lengkap, termasuk masa aktif' },
    { key: 'revenue', header: 'Revenue', type: 'number', format: rupiah, hint: 'Total nominal transaksi berhasil' },
    { key: 'trx', header: 'Trx', type: 'number', format: number, hint: 'Jumlah transaksi berhasil' },
    { key: 'low_price', header: 'Low Price', type: 'number', format: rupiah, hint: 'Nominal terendah dari satu transaksi' },
    { key: 'low_trx', header: 'Low Trx', type: 'number', format: number, hint: 'Jumlah transaksi terendah dalam satu hari' },
    { key: 'avg_price', header: 'Avg Price', type: 'number', format: rupiah, excelFormat: '#,##0.00', hint: 'Revenue dibagi Trx' },
    { key: 'avg_trx', header: 'Avg Trx', type: 'number', format: number, excelFormat: '#,##0.00', hint: 'Trx dibagi jumlah hari yang ada penjualannya' },
    { key: 'high_price', header: 'High Price', type: 'number', format: rupiah, hint: 'Nominal tertinggi dari satu transaksi' },
    { key: 'high_trx', header: 'High Trx', type: 'number', format: number, hint: 'Jumlah transaksi tertinggi dalam satu hari' },
].map((column) => ({ ...column, value: (row) => row[column.key] }));

const PAGE_SIZE = 10;

Alpine.data('packagePerformance', () => {
    const data = remoteData(fetchPackagePerformance);

    return {
        ...data,

        columns: PERFORMANCE_COLUMNS,
        search: '',
        exporting: null,
        page: 1,

        init() {
            data.init.call(this);
            this.$watch('search', () => (this.page = 1));
            this.$watch('filters', () => (this.page = 1));
        },

        // Semua baris hasil pencarian; dipakai juga untuk ekspor.
        get rows() {
            const keyword = this.search.trim().toLowerCase();
            if (!keyword) return this.items;

            return this.items.filter((row) => row.name.toLowerCase().includes(keyword));
        },

        get pageCount() {
            return Math.max(1, Math.ceil(this.rows.length / PAGE_SIZE));
        },

        get currentPage() {
            return Math.min(this.page, this.pageCount);
        },

        get pageRows() {
            const start = (this.currentPage - 1) * PAGE_SIZE;

            return this.rows.slice(start, start + PAGE_SIZE);
        },

        get rangeStart() {
            return this.rows.length ? (this.currentPage - 1) * PAGE_SIZE + 1 : 0;
        },

        get rangeEnd() {
            return Math.min(this.currentPage * PAGE_SIZE, this.rows.length);
        },

        // Nomor halaman yang ditampilkan: halaman pertama, terakhir, dan sekitar halaman aktif; celah jadi "…".
        get pageLinks() {
            const total = this.pageCount;
            const current = this.currentPage;
            const pages = new Set([1, total, current - 1, current, current + 1]);

            if (current <= 3) [2, 3, 4].forEach((page) => pages.add(page));
            if (current >= total - 2) [total - 3, total - 2, total - 1].forEach((page) => pages.add(page));

            const sorted = [...pages].filter((page) => page >= 1 && page <= total).sort((a, b) => a - b);

            return sorted.flatMap((page, index) => {
                const gap = index > 0 && page - sorted[index - 1] > 1 ? [{ key: `gap-${page}`, page: null }] : [];

                return [...gap, { key: `page-${page}`, page }];
            });
        },

        goTo(page) {
            this.page = Math.min(Math.max(1, page), this.pageCount);
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
    };
});
