import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';
import { analysisCopyModule } from './support/analysis-copy-module.mjs';

const source = (path) => readFile(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');

function syntheticModule(exports, context) {
    return new vm.SyntheticModule(Object.keys(exports), function () {
        for (const [key, value] of Object.entries(exports)) this.setExport(key, value);
    }, { context });
}

const httpMock = {
    errorMessage: (error, fallback) => (error?.status >= 500 ? fallback : error?.data?.message ?? fallback),
    validationErrors: ({ data }) => Object.fromEntries(Object.entries(data.errors).map(([key, messages]) => [key, messages[0]])),
};

async function loadModule(path, mocks = {}, globals = {}) {
    const context = vm.createContext({ Intl, Date, Number, Math, Boolean, Object, String, URLSearchParams, Set, Promise, Blob, ...globals });
    const format = new vm.SourceTextModule(await source('format.js'), { context });
    const copy = await analysisCopyModule(context);
    const module = new vm.SourceTextModule(await source(path), { context });

    await module.link((specifier) => {
        if (specifier === '../../format') return format;
        if (specifier === './analysis-copy') return copy;
        if (!mocks[specifier]) throw new Error(`Unexpected import ${specifier}`);
        return syntheticModule(mocks[specifier], context);
    });
    await module.evaluate();

    return module.namespace;
}

async function copyModule() {
    const copy = await analysisCopyModule(vm.createContext({ String, Object }));
    await copy.evaluate();

    return copy.namespace;
}

/**
 * Objects made inside a vm context have another Object prototype, so compare them as plain data.
 */
const same = (actual, expected, message) => assert.deepEqual(JSON.parse(JSON.stringify(actual ?? null)), expected, message);

/**
 * A browser clipboard (secure context) that records what is written. `onWrite` can throw to simulate a denied clipboard.
 */
function fakeClipboard(copies, onWrite = async () => {}) {
    class ClipboardItem {
        constructor(items) {
            this.items = items;
        }
    }
    const record = async (text) => {
        await onWrite(text);
        copies.push(text);
    };

    return {
        ClipboardItem,
        window: { isSecureContext: true, ClipboardItem },
        navigator: {
            clipboard: {
                writeText: record,
                write: async ([item]) => record(await (await item.items['text/plain']).text()),
            },
        },
    };
}

/**
 * The actions bar with every side effect recorded. `answers` plays the components:
 * event name => function(detail) that fills the detail like the real listener does.
 */
async function actionsBar({ exportAnalysis, onWrite, answers = {}, scope = {} }) {
    const calls = { exports: [], downloads: [], toasts: [], copies: [], events: [] };
    const { default: promoAnalysisActions } = await loadModule('pages/promo/analysis-actions.js', {
        '../../export': {
            download: (blob, fileName) => calls.downloads.push({ blob, fileName }),
        },
        '../../http': httpMock,
        '../../components/toast': { showToast: (toast) => calls.toasts.push(toast) },
        './api': {
            exportAnalysis: async (query) => {
                calls.exports.push(query);
                return exportAnalysis(query);
            },
        },
    }, fakeClipboard(calls.copies, onWrite));
    const bar = Object.assign(promoAnalysisActions(), { query: 'year=2026&month=9', filterInvalid: false, ...scope });
    bar.$dispatch = (name, detail) => {
        calls.events.push({ name, detail });
        answers[name]?.(detail);
    };

    return { bar, calls };
}

const snapshotOf = async (component, target) => {
    const detail = { target, snapshot: null };
    component.provideCopy(detail);

    return detail.snapshot;
};

test('copy text is tab-separated with a header row, raw numbers, and no tab or line break inside a cell', async () => {
    const { FUNNEL_COLUMNS, copySnapshot } = await copyModule();
    const snapshot = copySnapshot({ loading: false, loadError: '', isEmpty: false }, FUNNEL_COLUMNS, [
        { name: 'Sales', transactions: 3, revenue: 700000, contribution_percentage: 46.67 },
        { name: 'Tanpa\tfunnel\nbaru', transactions: 0, revenue: 0, contribution_percentage: null },
    ]);

    assert.equal(snapshot.status, 'ready');
    assert.equal(snapshot.rows, 2);
    assert.equal(snapshot.text, [
        'Funnel\tJumlah Transaksi\tJumlah Revenue\tKontribusi Funnel (%)',
        'Sales\t3\t700000\t46.67',
        'Tanpa funnel baru\t0\t0\t',
    ].join('\n'));
});

test('decimals use the decimal separator of the browser language so spreadsheets read them as numbers', async () => {
    const { toTsv } = (await (async () => {
        const context = vm.createContext({ Intl, Number, String, navigator: { language: 'id-ID' } });
        const module = new vm.SourceTextModule(await source('export.js'), { context });
        await module.link(() => {});
        await module.evaluate();
        return module.namespace;
    })());
    const columns = [{ header: 'Kontribusi (%)', value: (row) => row.share }, { header: 'Revenue', value: (row) => row.revenue }];

    assert.equal(toTsv(columns, [{ share: 46.67, revenue: 700000 }]), 'Kontribusi (%)\tRevenue\n46,67\t700000');
    assert.equal(toTsv(columns, [{ share: 46.67, revenue: 700000 }], { decimal: '.' }), 'Kontribusi (%)\tRevenue\n46.67\t700000');
});

test('nothing is copied while loading, after a failed load, or when the filter has no data', async () => {
    const { SOURCE_COLUMNS, copySnapshot, copyProblem } = await copyModule();
    const rows = [{ name: 'Reseller', usages: 1, revenue: 1, contribution_percentage: 100 }];

    same(copySnapshot({ loading: true }, SOURCE_COLUMNS, rows), { status: 'loading' });
    same(copySnapshot({ loading: false, loadError: 'Gagal' }, SOURCE_COLUMNS, rows), { status: 'error' });
    same(copySnapshot({ loading: false, loadError: '', isEmpty: true }, SOURCE_COLUMNS, rows), { status: 'empty' });
    same(copySnapshot({ loading: false, loadError: '', isEmpty: false }, SOURCE_COLUMNS, []), { status: 'empty' });

    assert.equal(copyProblem({ status: 'ready' }, 'Source'), null);
    assert.match(copyProblem({ status: 'loading' }, 'Source'), /masih dimuat/);
    assert.match(copyProblem({ status: 'error' }, 'Source'), /gagal dimuat/);
    assert.equal(copyProblem({ status: 'empty' }, 'Source'), 'Tidak ada data Source untuk filter yang dipilih, sehingga tidak ada yang disalin.');
    assert.match(copyProblem(null, 'Source'), /tidak tersedia/);
});

test('each component copies its own rows with the page headers', async () => {
    const funnel = (await loadModule('pages/promo/analysis-funnel.js', {
        '../../http': httpMock,
        './api': { fetchAnalysisFunnels: async () => ({ data: { is_empty: false, funnels: [{ key: 'funnel', name: 'Sales', transactions: 2, revenue: 500000, contribution_percentage: 100 }] } }) },
    })).default();
    await funnel.load('year=2026&month=9');

    assert.equal(await snapshotOf(funnel, 'source'), null, 'answers only for its own target');
    assert.equal((await snapshotOf(funnel, 'funnel')).text, 'Funnel\tJumlah Transaksi\tJumlah Revenue\tKontribusi Funnel (%)\nSales\t2\t500000\t100');

    const productType = (await loadModule('pages/promo/analysis-product-type.js', {
        '../../http': httpMock,
        './api': { fetchAnalysisProductTypes: async () => ({ data: { is_empty: false, product_types: [{ key: 'first_purchase', name: 'Perdana', transactions: 2, revenue: 300000, transaction_percentage: 100, revenue_percentage: 100 }] } }) },
    })).default();
    await productType.load('');

    assert.equal((await snapshotOf(productType, 'product-type')).text, 'Tipe Produk\tJumlah Transaksi\tRevenue\nPerdana\t2\t300000');

    const source = (await loadModule('pages/promo/analysis-source.js', {
        '../../http': httpMock,
        './api': { fetchAnalysisSources: async () => ({ data: { is_empty: true, message: 'Data tidak tersedia untuk filter yang dipilih.', sources: [{ key: 'no_source', name: 'Tanpa source', usages: 0, revenue: 0, contribution_percentage: 0 }] } }) },
    })).default();
    await source.load('');

    same(await snapshotOf(source, 'source'), { status: 'empty' });
});

test('KPI summary copies every card in page order with the change its badge shows', async () => {
    const metrics = [
        { key: 'promo_transactions', label: 'Total Transactions with Promo', format: 'number' },
        { key: 'promo_revenue', label: 'Revenue with Promo', format: 'currency' },
    ];
    const summary = (await loadModule('pages/promo/analysis-summary.js', {
        '../../http': httpMock,
        './api': {
            fetchAnalysisSummary: async () => ({
                data: {
                    is_empty: false,
                    kpis: {
                        promo_revenue: { value: 700000, previous_value: 0, change_percentage: null, trend: 'up' },
                        promo_transactions: { value: 3, previous_value: 1, change_percentage: 200, trend: 'up' },
                    },
                },
            }),
        },
    })).default(metrics);

    same(await snapshotOf(summary, 'summary'), { status: 'loading' });
    await summary.load('');

    assert.equal((await snapshotOf(summary, 'summary')).text, [
        'KPI\tNilai\tPerubahan (%)',
        'Total Transactions with Promo\t3\t200',
        'Revenue with Promo\t700000\t',
    ].join('\n'), 'no change when the previous value is 0, as the card shows no badge');
});

test('code summary copies every row of the filter in the current sort, not only the page on screen, unregistered codes included', async () => {
    const registered = { code: 'RESELLER01', raw_codes: ['RESELLER01'], is_registered: true, platform: { id: 2, name: 'JADIASN' }, funnel: { id: 3, name: 'Sales' }, source: { id: 8, name: 'Reseller' }, usages: 2, revenue: 500000, contribution_percentage: 23.81, detail: { group: 'a' } };
    const unregistered = { code: 'UNREG26', raw_codes: [' unreg26 ', 'UNREG26'], is_registered: false, platform: { id: 2, name: 'JADIASN' }, funnel: null, source: null, usages: 2, revenue: 400000, contribution_percentage: 19.05, detail: { group: 'b' } };
    const queries = [];
    const code = (await loadModule('pages/promo/analysis-code.js', {
        '../../http': httpMock,
        './api': {
            fetchAnalysisCodes: async (query) => {
                queries.push(query);
                const page = Number(new URLSearchParams(query).get('page'));

                return { data: [page === 1 ? registered : unregistered], meta: { current_page: page, last_page: 2, per_page: 1, total: 2 }, is_empty: false };
            },
        },
    })).default();
    await code.load('year=2026&month=9');
    queries.length = 0;

    const snapshot = await snapshotOf(code, 'codes');
    assert.equal(snapshot.status, 'ready');
    assert.equal(snapshot.rows, 2, 'counts every row of the filter, from meta.total');
    assert.equal(await snapshot.text(), [
        'Kode Promo\tKode Asli Markaz\tStatus\tPlatform\tKategori/Funnel\tSource\tJumlah Penggunaan\tJumlah Revenue\tKontribusi (%)',
        'RESELLER01\tRESELLER01\tTerdaftar\tJADIASN\tSales\tReseller\t2\t500000\t23.81',
        'UNREG26\t unreg26 , UNREG26\tBelum terdaftar\tJADIASN\t\t\t2\t400000\t19.05',
    ].join('\n'));
    same(queries, [
        'year=2026&month=9&sort=revenue&direction=desc&page=1&per_page=100',
        'year=2026&month=9&sort=revenue&direction=desc&page=2&per_page=100',
    ]);

    await code.sortBy('code');
    const params = new URLSearchParams('year=2026&month=9');
    code.provideExportParams({ params });
    assert.equal(params.toString(), 'year=2026&month=9&sort=code&direction=asc');
});

test('trend copies every bucket with only the series the chart shows and gives its active period to the export', async () => {
    class Chart {}
    const trend = (await loadModule('pages/promo/analysis-trend.js', {
        alpinejs: { default: { raw: (value) => value } },
        '../../http': httpMock,
        './api': {
            fetchAnalysisTrend: async () => ({
                data: {
                    is_empty: false,
                    labels: ['01 - 06 Sep 2026'],
                    datasets: [
                        { key: 'promo_transactions', label: 'Transaksi dengan Promo', data: [1] },
                        { key: 'promo_revenue', label: 'Revenue dengan Promo', data: [100000] },
                        { key: 'total_transactions', label: 'Total Transaksi', data: [2] },
                        { key: 'total_sales', label: 'Total Penjualan', data: [500000] },
                    ],
                    rows: [{ label: '01 - 06 Sep 2026', period_start: '2026-09-01', period_end: '2026-09-06', promo_transactions: 1, promo_revenue: 100000, total_transactions: 2, total_sales: 500000 }],
                },
            }),
        },
        './chart': {
            renderChart: () => new Chart(),
            trendChartConfig: (data) => data,
            trendSeries: (datasets) => ['promo_transactions', 'promo_revenue', 'total_sales'].map((key) => datasets.find((dataset) => dataset.key === key)),
        },
    })).default();
    trend.$nextTick = () => {};
    await trend.load('date_from=2025-01-01&date_to=2026-09-30');

    assert.equal((await snapshotOf(trend, 'trend')).text, [
        'Periode\tTransaksi dengan Promo\tRevenue dengan Promo\tTotal Penjualan',
        '01 - 06 Sep 2026\t1\t100000\t500000',
    ].join('\n'));

    const params = new URLSearchParams();
    trend.provideExportParams({ params });
    assert.equal(params.get('period'), 'monthly', 'a range over 366 days is exported monthly, as the chart shows it');
});

test('top 5 copies unregistered codes by their raw spelling, as the table shows them', async () => {
    const topCodes = (await loadModule('pages/promo/analysis-top-codes.js', {
        '../../http': httpMock,
        './api': {
            fetchAnalysisTopCodes: async () => ({
                data: {
                    is_empty: false,
                    codes: [
                        { rank: 1, code: 'RESELLER01', raw_codes: ['RESELLER01'], is_registered: true, platform: { id: 2 }, usages: 2, revenue: 500000 },
                        { rank: 2, code: 'UNREG26', raw_codes: [' unreg26 ', 'unreg26'], is_registered: false, platform: { id: 2 }, usages: 1, revenue: 100000 },
                    ],
                },
            }),
        },
    })).default();
    await topCodes.load('');

    assert.equal((await snapshotOf(topCodes, 'top-codes')).text, [
        'Peringkat\tKode Promo\tJumlah Penggunaan\tRevenue',
        '1\tRESELLER01\t2\t500000',
        '2\tunreg26\t1\t100000',
    ].join('\n'));
});

test('Export Excel sends the active filter with the trend period and code sort, then downloads the backend file', async () => {
    const blob = { size: 10 };
    const { bar, calls } = await actionsBar({
        exportAnalysis: async () => ({ blob, fileName: 'promo-analisis_2026-09-01_2026-09-30.xlsx' }),
        scope: { query: 'platforms[]=jadiasn&payment_methods[]=BCA&year=2026&month=9&search=cpns' },
        answers: {
            'promo-analysis-export-params': ({ params }) => {
                params.set('period', 'weekly');
                params.set('sort', 'usages');
                params.set('direction', 'desc');
            },
        },
    });

    const pending = bar.exportExcel();
    assert.equal(bar.exporting, true);
    assert.equal(bar.status.type, 'loading');
    await pending;

    same(calls.exports, ['platforms%5B%5D=jadiasn&payment_methods%5B%5D=BCA&year=2026&month=9&search=cpns&period=weekly&sort=usages&direction=desc']);
    same(calls.downloads, [{ blob, fileName: 'promo-analisis_2026-09-01_2026-09-30.xlsx' }]);
    same(calls.toasts, [{ title: 'Export Successful', text: 'File promo-analisis_2026-09-01_2026-09-30.xlsx berhasil diunduh.' }]);
    assert.equal(bar.exporting, false);
    assert.equal(bar.status, null);
});

test('Export Excel shows the backend message on failure and hands filter errors back to the fields', async () => {
    const tooLarge = 'Data terlalu besar untuk diekspor: sheet Detail Penggunaan berisi 1048576 baris, maksimal 1048575 baris. Persempit periode atau filter, lalu coba lagi.';
    let failure = { status: 422, data: { message: tooLarge } };
    const { bar, calls } = await actionsBar({ exportAnalysis: async () => { throw failure; } });

    await bar.exportExcel();
    same(bar.status, { type: 'error', message: tooLarge });
    assert.equal(calls.downloads.length, 0);

    failure = { status: 422, data: { message: 'Filter tidak valid.', errors: { 'platforms.0': ['Platform tidak ditemukan.'] } } };
    await bar.exportExcel();
    same(calls.events.find(({ name }) => name === 'promo-analysis-errors').detail, { 'platforms.0': 'Platform tidak ditemukan.' });

    failure = { status: 500, data: { message: 'SQLSTATE[42S02] internal detail' } };
    await bar.exportExcel();
    same(bar.status, { type: 'error', message: 'Gagal mengekspor data analisis promo. Silakan coba lagi.' });
    assert.equal(bar.exporting, false);

    bar.clearStatus();
    assert.equal(bar.status, null, 'a new filter clears the old message');
});

test('Export and Copy do nothing while the filter is invalid or not applied yet', async () => {
    const { bar, calls } = await actionsBar({ exportAnalysis: async () => ({}), scope: { filterInvalid: true } });

    assert.equal(bar.actionsDisabled, true);
    await bar.exportExcel();
    await bar.copy();
    assert.equal(calls.exports.length + calls.copies.length + calls.events.length, 0);

    bar.filterInvalid = false;
    bar.query = null;
    assert.equal(bar.actionsDisabled, true);
});

test('Copy asks only the selected component and writes its rows to the clipboard', async () => {
    const { bar, calls } = await actionsBar({
        exportAnalysis: async () => ({}),
        answers: {
            'promo-analysis-copy': (detail) => {
                if (detail.target === 'funnel') detail.snapshot = { status: 'ready', text: 'Funnel\nSales', rows: 1 };
            },
        },
    });

    bar.copyTarget = 'funnel';
    await bar.copy();

    same(calls.copies, ['Funnel\nSales']);
    same(calls.toasts, [{ title: 'Data Copied', text: '1 baris Promo Analysis by Funnel berhasil disalin.' }]);
    assert.equal(bar.status, null);
    assert.equal(bar.copying, false);
});

test('Copy of a paginated table loads every row while showing its status, then writes it in one go', async () => {
    let resolveRows;
    const { bar, calls } = await actionsBar({
        exportAnalysis: async () => ({}),
        answers: {
            'promo-analysis-copy': (detail) => {
                detail.snapshot = { status: 'ready', rows: 32, text: () => new Promise((resolve) => { resolveRows = resolve; }) };
            },
        },
    });

    bar.copyTarget = 'codes';
    const pending = bar.copy();
    assert.equal(bar.copying, true);
    same(bar.status, { type: 'loading', message: 'Menyalin data Summary by Promo Code sesuai filter aktif…' });
    assert.equal(bar.busy, true, 'Export waits until the copy is done');

    resolveRows('Kode Promo\nA');
    await pending;

    same(calls.copies, ['Kode Promo\nA']);
    same(calls.toasts, [{ title: 'Data Copied', text: '32 baris Summary by Promo Code berhasil disalin.' }]);
    assert.equal(bar.status, null);
});

test('Copy of a paginated table shows the backend message when loading its rows fails', async () => {
    const { bar, calls } = await actionsBar({
        exportAnalysis: async () => ({}),
        answers: {
            'promo-analysis-copy': (detail) => {
                detail.snapshot = { status: 'ready', rows: 32, text: () => Promise.reject({ status: 500, data: { message: 'SQLSTATE secret' } }) };
            },
        },
    });

    bar.copyTarget = 'codes';
    await bar.copy();

    assert.equal(calls.copies.length, 0);
    assert.equal(calls.toasts.length, 0);
    same(bar.status, { type: 'error', message: 'Gagal mengambil data Summary by Promo Code. Silakan coba lagi.' });
});

test('Copy shows a clear message and writes nothing when the component has no data or the clipboard fails', async () => {
    let answer = { status: 'empty' };
    const { bar, calls } = await actionsBar({
        exportAnalysis: async () => ({}),
        onWrite: async () => { throw new Error('denied'); },
        answers: { 'promo-analysis-copy': (detail) => { detail.snapshot = answer; } },
    });

    bar.copyTarget = 'product-type';
    await bar.copy();
    assert.equal(calls.copies.length, 0);
    same(bar.status, { type: 'error', message: 'Tidak ada data First-Time vs Renewal Promo Analysis untuk filter yang dipilih, sehingga tidak ada yang disalin.' });

    answer = { status: 'ready', text: 'x', rows: 1 };
    await bar.copy();
    assert.equal(calls.toasts.length, 0);
    assert.match(bar.status.message, /Gagal menyalin ke clipboard/);
});

test('download file name is read from Content-Disposition', async () => {
    const context = vm.createContext({ decodeURIComponent, document: { querySelector: () => null } });
    const http = new vm.SourceTextModule(await source('http.js'), { context });
    await http.link(() => {});
    await http.evaluate();
    const { dispositionFileName } = http.namespace;

    assert.equal(dispositionFileName('attachment; filename=promo-analisis_2026-09-01_2026-09-30.xlsx'), 'promo-analisis_2026-09-01_2026-09-30.xlsx');
    assert.equal(dispositionFileName('attachment; filename="promo analisis.xlsx"'), 'promo analisis.xlsx');
    assert.equal(dispositionFileName("attachment; filename=x.xlsx; filename*=UTF-8''promo%20analisis.xlsx"), 'promo analisis.xlsx');
    assert.equal(dispositionFileName(null), null);
});

test('usage detail copies every transaction of the code for the active filter', async () => {
    const queries = [];
    const toasts = [];
    const copies = [];
    const day = (date, trx) => ({ date, transactions: [{ trx_id: trx, paid_date: date, platform: { id: 2, name: 'JADIASN' }, usages: 1, revenue: 250000 }] });
    const { default: promoAnalysisCodeUsage } = await loadModule('pages/promo/analysis-code-usage.js', {
        '../../http': httpMock,
        '../../components/toast': { showToast: (toast) => toasts.push(toast) },
        './api': {
            fetchAnalysisCodeUsages: async (query) => {
                queries.push(query);
                const page = Number(new URLSearchParams(query).get('page'));

                return { data: [page === 1 ? day('2026-09-10', 'a') : day('2026-09-09', 'b')], meta: { current_page: page, last_page: 2, total: 2 }, totals: { usages: 2, revenue: 500000 }, is_empty: false };
            },
        },
    }, fakeClipboard(copies));
    const detail = promoAnalysisCodeUsage();

    await detail.show({ row: { code: 'RESELLER01', platform: { name: 'JADIASN' }, detail: { platform_id: 2, code: 'RESELLER01', group: 'g1' } }, filterQuery: 'year=2026&month=9' });
    queries.length = 0;
    await detail.copy();

    same(queries, [
        'year=2026&month=9&platform_id=2&code=RESELLER01&group=g1&page=1&per_page=100',
        'year=2026&month=9&platform_id=2&code=RESELLER01&group=g1&page=2&per_page=100',
    ]);
    same(copies, ['Tanggal\tPlatform/Aplikasi\tJumlah Penggunaan\tRevenue\n2026-09-10\tJADIASN\t1\t250000\n2026-09-09\tJADIASN\t1\t250000']);
    same(toasts, [{ title: 'Data Copied', text: '2 baris detail penggunaan RESELLER01 berhasil disalin.' }]);
    assert.equal(detail.copyError, '');
});

test('usage detail copies nothing and says so when the code has no usage for the filter', async () => {
    const copies = [];
    const { default: promoAnalysisCodeUsage } = await loadModule('pages/promo/analysis-code-usage.js', {
        '../../http': httpMock,
        '../../components/toast': { showToast: () => {} },
        './api': { fetchAnalysisCodeUsages: async () => ({ data: [], meta: { current_page: 1, last_page: 1, total: 0 }, totals: null, is_empty: true }) },
    }, fakeClipboard(copies));
    const detail = promoAnalysisCodeUsage();

    await detail.show({ row: { code: 'X1', platform: { name: 'JADIASN' }, detail: { platform_id: 2, code: 'X1' } }, filterQuery: '' });
    await detail.copy();

    assert.equal(copies.length, 0);
    assert.equal(detail.copyError, 'Tidak ada data detail penggunaan X1 untuk filter yang dipilih, sehingga tidak ada yang disalin.');
});
