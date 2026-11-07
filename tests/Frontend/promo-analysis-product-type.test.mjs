import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

async function productTypeModule(fetchAnalysisProductTypes) {
    const context = vm.createContext({ Intl, Date, Number, Math, Boolean, Object, String });
    const source = (path) => readFile(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
    const format = new vm.SourceTextModule(await source('format.js'), { context });
    const mocks = {
        '../../http': {
            errorMessage: (error, fallback) => (error.status >= 500 ? fallback : error.data?.message ?? fallback),
            validationErrors: ({ data }) => Object.fromEntries(Object.entries(data.errors).map(([key, messages]) => [key, messages[0]])),
        },
        './api': { fetchAnalysisProductTypes },
    };
    const module = new vm.SourceTextModule(await source('pages/promo/analysis-product-type.js'), { context });
    await module.link((specifier) => {
        if (specifier === '../../format') return format;
        const mock = mocks[specifier];
        return new vm.SyntheticModule(Object.keys(mock), function () {
            for (const [key, value] of Object.entries(mock)) this.setExport(key, value);
        }, { context });
    });
    await module.evaluate();

    return module.namespace;
}

const group = (key, name, transactions, revenue, transaction_percentage, revenue_percentage) => ({
    key, name, transactions, revenue, transaction_percentage, revenue_percentage,
});

function productTypeResponse(extra = {}) {
    return {
        data: {
            filter: { mode: 'month', date_from: '2026-09-01', date_to: '2026-09-30' },
            is_empty: false,
            message: null,
            totals: { transactions: 6, revenue: 1950000 },
            product_types: [
                group('first_purchase', 'Perdana', 2, 300000, 33.33, 15.38),
                group('renewal', 'Perpanjangan', 1, 300000, 16.67, 15.38),
                group('other', 'Bundling', 1, 400000, 16.67, 20.51),
                group('unknown', 'Tidak diketahui', 2, 950000, 33.33, 48.72),
            ],
            ...extra,
        },
    };
}

test('product type table shows every backend group in order with transactions and revenue separated', async () => {
    const queries = [];
    const { default: promoAnalysisProductType } = await productTypeModule(async (query) => {
        queries.push(query);
        return productTypeResponse();
    });
    const table = promoAnalysisProductType();

    await table.load('year=2026&month=9&platforms[]=jadiasn&payment_methods[]=BCA&search=cpns');

    assert.deepEqual(queries, ['year=2026&month=9&platforms[]=jadiasn&payment_methods[]=BCA&search=cpns']);
    assert.equal(table.loading, false);
    assert.deepEqual(table.visibleRows.map((row) => row.name), ['Perdana', 'Perpanjangan', 'Bundling', 'Tidak diketahui']);
    assert.equal(new Set(table.visibleRows.map((row) => table.rowKey(row))).size, 4);

    const [firstPurchase] = table.rows;
    assert.equal(table.format(firstPurchase.transactions, 'number'), '2');
    assert.match(table.format(firstPurchase.revenue, 'currency'), /^Rp\s?300\.000$/);
});

test('Perdana and Perpanjangan stay listed at 0 and no unknown row is added when the backend sends none', async () => {
    const { default: promoAnalysisProductType } = await productTypeModule(async () => productTypeResponse({
        totals: { transactions: 3, revenue: 500000 },
        product_types: [
            group('first_purchase', 'Perdana', 3, 500000, 100, 100),
            group('renewal', 'Perpanjangan', 0, 0, 0, 0),
        ],
    }));
    const table = promoAnalysisProductType();

    await table.load('');

    assert.deepEqual(table.visibleRows.map((row) => row.name), ['Perdana', 'Perpanjangan']);

    const renewal = table.visibleRows.at(-1);
    assert.equal(table.format(renewal.transactions, 'number'), '0');
    assert.equal(table.format(renewal.revenue, 'currency').replace(/\s/g, ''), 'Rp0');
});

test('product type table shows the empty message instead of zero rows', async () => {
    const { default: promoAnalysisProductType } = await productTypeModule(async () => productTypeResponse({
        is_empty: true,
        message: 'Data tidak tersedia untuk filter yang dipilih.',
        totals: { transactions: 0, revenue: 0 },
        product_types: [
            group('first_purchase', 'Perdana', 0, 0, 0, 0),
            group('renewal', 'Perpanjangan', 0, 0, 0, 0),
        ],
    }));
    const table = promoAnalysisProductType();

    await table.load('');

    assert.equal(table.isEmpty, true);
    assert.equal(table.visibleRows.length, 0);
    assert.equal(table.emptyMessage, 'Data tidak tersedia untuk filter yang dipilih.');
});

test('product type table hides server details, retries, and hands 422 errors back to the filter', async () => {
    const responses = [
        () => { throw { status: 500, data: { message: 'SQLSTATE[42S22]' } }; },
        () => productTypeResponse(),
        () => { throw { status: 422, data: { errors: { search: ['Pencarian kode promo maksimal 100 karakter.'] } } }; },
    ];
    const queries = [];
    const { default: promoAnalysisProductType } = await productTypeModule(async (query) => {
        queries.push(query);
        return responses.shift()();
    });
    const table = promoAnalysisProductType();
    const dispatched = [];
    table.$dispatch = (name, detail) => dispatched.push({ name, detail });

    await table.load('q=1');
    assert.equal(table.loadError, 'Gagal memuat rekap tipe produk.');
    assert.equal(table.rows.length, 0);

    await table.retry();
    assert.deepEqual(queries, ['q=1', 'q=1']);
    assert.equal(table.loadError, '');
    assert.equal(table.rows.length, 4);

    await table.load('q=3');
    assert.equal(table.rows.length, 0);
    assert.equal(table.loadError, '');
    assert.deepEqual(dispatched, [{
        name: 'promo-analysis-errors',
        detail: { search: 'Pencarian kode promo maksimal 100 karakter.' },
    }]);
});

test('a slower response for an older filter does not overwrite the newer one', async () => {
    let releaseOld;
    const { default: promoAnalysisProductType } = await productTypeModule((query) => (query === 'old'
        ? new Promise((resolve) => { releaseOld = () => resolve(productTypeResponse({ product_types: [] })); })
        : Promise.resolve(productTypeResponse())));
    const table = promoAnalysisProductType();

    const old = table.load('old');
    await table.load('new');
    releaseOld();
    await old;

    assert.equal(table.query, 'new');
    assert.equal(table.rows.length, 4);
    assert.equal(table.loading, false);
});
