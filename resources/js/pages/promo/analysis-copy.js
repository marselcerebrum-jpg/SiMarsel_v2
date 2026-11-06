import { copyText, toTsv } from '../../export';
import { errorMessage } from '../../http';

/**
 * "Copy" for the promo analysis components (docs/api/promo-analysis.md#tombol-salin). The actions bar
 * dispatches `promo-analysis-copy` with { target, snapshot: null }; the selected component fills
 * `snapshot` with copySnapshot() from the data it shows, so the copy always matches the page.
 * Paginated tables use copyAllSnapshot(), which copies every row of the active filter, not one page.
 */
export const COPY_TARGETS = [
    { value: 'summary', label: 'KPI Summary' },
    { value: 'trend', label: 'Promo Usage Trend' },
    { value: 'top-codes', label: 'Top 5 Promo Codes' },
    { value: 'funnel', label: 'Promo Analysis by Funnel' },
    { value: 'source', label: 'Promo Analysis by Source' },
    { value: 'product-type', label: 'First-Time vs Renewal Promo Analysis' },
    { value: 'codes', label: 'Summary by Promo Code' },
];

const registration = (row) => (row.is_registered ? 'Terdaftar' : 'Belum terdaftar');

/**
 * Rows are { label, value, change }, as a card shows them; `change` is empty when the card shows no badge.
 */
export const SUMMARY_COLUMNS = [
    { header: 'KPI', value: (row) => row.label },
    { header: 'Nilai', value: (row) => row.value },
    { header: 'Perubahan (%)', value: (row) => row.change },
];

/**
 * The bucket plus one column per series the chart shows, with the backend labels.
 */
export function trendColumns(series = []) {
    return [
        { header: 'Periode', value: (row) => row.label },
        ...series.map(({ key, label }) => ({ header: label, value: (row) => row[key] })),
    ];
}

export const TOP_CODE_COLUMNS = [
    { header: 'Peringkat', value: (row) => row.rank },
    { header: 'Kode Promo', value: (row) => row.display_code },
    { header: 'Jumlah Penggunaan', value: (row) => row.usages },
    { header: 'Revenue', value: (row) => row.revenue },
];

export const FUNNEL_COLUMNS = [
    { header: 'Funnel', value: (row) => row.name },
    { header: 'Jumlah Transaksi', value: (row) => row.transactions },
    { header: 'Jumlah Revenue', value: (row) => row.revenue },
    { header: 'Kontribusi Funnel (%)', value: (row) => row.contribution_percentage },
];

export const SOURCE_COLUMNS = [
    { header: 'Source', value: (row) => row.name },
    { header: 'Jumlah Penggunaan', value: (row) => row.usages },
    { header: 'Jumlah Revenue', value: (row) => row.revenue },
    { header: 'Kontribusi Source (%)', value: (row) => row.contribution_percentage },
];

export const PRODUCT_TYPE_COLUMNS = [
    { header: 'Tipe Produk', value: (row) => row.name },
    { header: 'Jumlah Transaksi', value: (row) => row.transactions },
    { header: 'Revenue', value: (row) => row.revenue },
];

/**
 * Unregistered codes keep their status and raw Markaz spellings, as the table shows them.
 */
export const CODE_COLUMNS = [
    { header: 'Kode Promo', value: (row) => row.code },
    { header: 'Kode Asli Markaz', value: (row) => (row.raw_codes ?? []).join(', ') },
    { header: 'Status', value: registration },
    { header: 'Platform', value: (row) => row.platform?.name },
    { header: 'Kategori/Funnel', value: (row) => row.funnel?.name },
    { header: 'Source', value: (row) => row.source?.name },
    { header: 'Jumlah Penggunaan', value: (row) => row.usages },
    { header: 'Jumlah Revenue', value: (row) => row.revenue },
    { header: 'Kontribusi (%)', value: (row) => row.contribution_percentage },
];

/**
 * The usage detail table, one row per transaction.
 */
export const USAGE_COLUMNS = [
    { header: 'Tanggal', value: (row) => row.paid_date },
    { header: 'Platform/Aplikasi', value: (row) => row.platform?.name },
    { header: 'Jumlah Penggunaan', value: (row) => row.usages },
    { header: 'Revenue', value: (row) => row.revenue },
];

/**
 * The largest page the paginated analysis endpoints accept.
 */
export const MAX_PER_PAGE = 100;

/**
 * Every row of a paginated endpoint for the query, page after page in the backend's order.
 */
export async function allPages(fetchPage, query, pick = (result) => result.data) {
    const params = new URLSearchParams(query);
    params.set('per_page', String(MAX_PER_PAGE));

    const rows = [];
    let page = 1;
    let lastPage = 1;

    do {
        params.set('page', String(page));
        const result = await fetchPage(params.toString());

        rows.push(...pick(result));
        lastPage = result.meta?.last_page ?? 1;
        page++;
    } while (page <= lastPage);

    return rows;
}

/**
 * What a component can hand over right now. Nothing is copied while it loads, after a failed load,
 * or when the filter has no data.
 */
export function copySnapshot({ loading, loadError, isEmpty }, columns, rows) {
    if (loading) return { status: 'loading' };
    if (loadError) return { status: 'error' };
    if (isEmpty || rows.length === 0) return { status: 'empty' };

    return { status: 'ready', text: toTsv(columns, rows), rows: rows.length };
}

/**
 * Like copySnapshot() for a paginated table: `total` rows of the active filter, loaded by `loadRows`
 * when the copy runs.
 */
export function copyAllSnapshot({ loading, loadError, isEmpty }, columns, total, loadRows) {
    if (loading) return { status: 'loading' };
    if (loadError) return { status: 'error' };
    if (isEmpty || !total) return { status: 'empty' };

    return { status: 'ready', text: () => loadRows().then((rows) => toTsv(columns, rows)), rows: total };
}

/**
 * Message for a snapshot that cannot be copied, or null when it can.
 */
export function copyProblem(snapshot, label) {
    switch (snapshot?.status) {
        case 'ready':
            return null;
        case 'loading':
            return `Data ${label} masih dimuat. Tunggu sampai selesai, lalu salin lagi.`;
        case 'error':
            return `Data ${label} gagal dimuat, sehingga tidak ada yang disalin. Coba muat ulang komponen tersebut.`;
        case 'empty':
            return `Tidak ada data ${label} untuk filter yang dipilih, sehingga tidak ada yang disalin.`;
        default:
            return 'Komponen yang dipilih tidak tersedia untuk disalin.';
    }
}

/**
 * Copies a snapshot. Resolves { message } to show as the success toast, or { error } to show as the status.
 */
export async function runCopy(snapshot, label) {
    const problem = copyProblem(snapshot, label);
    if (problem) return { error: problem };

    let loadError = null;
    const text = typeof snapshot.text === 'function'
        ? snapshot.text().catch((error) => {
            loadError = error;
            throw error;
        })
        : snapshot.text;

    try {
        await copyText(text);
    } catch {
        return {
            error: loadError
                ? errorMessage(loadError, `Gagal mengambil data ${label}. Silakan coba lagi.`)
                : 'Gagal menyalin ke clipboard. Izinkan akses clipboard di browser, lalu coba lagi.',
        };
    }

    return { message: `${snapshot.rows} baris ${label} berhasil disalin.` };
}
