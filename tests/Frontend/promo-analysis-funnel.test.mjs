import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';
import { analysisCopyModule } from './support/analysis-copy-module.mjs';

async function funnelModule(fetchAnalysisFunnels) {
    const context = vm.createContext({ Intl, Date, Number, Math, Boolean, Object, String });
    const source = (path) => readFile(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
    const format = new vm.SourceTextModule(await source('format.js'), { context });
    const mocks = {
        '../../http': {
            errorMessage: (error, fallback) => (error.status >= 500 ? fallback : error.data?.message ?? fallback),
            validationErrors: ({ data }) => Object.fromEntries(Object.entries(data.errors).map(([key, messages]) => [key, messages[0]])),
        },
        './api': { fetchAnalysisFunnels },
    };
    const module = new vm.SourceTextModule(await source('pages/promo/analysis-funnel.js'), { context });
    const copy = await analysisCopyModule(context);
    await module.link((specifier) => {
        if (specifier === '../../format') return format;
        if (specifier === './analysis-copy') return copy;
        const mock = mocks[specifier];
        return new vm.SyntheticModule(Object.keys(mock), function () {
            for (const [key, value] of Object.entries(mock)) this.setExport(key, value);
        }, { context });
    });
    await module.evaluate();

    return module.namespace;
}

const funnel = (id, name, transactions, revenue, contribution_percentage) => ({
    key: 'funnel', funnel: { id, name }, name, transactions, revenue, contribution_percentage,
});

function funnelResponse(extra = {}) {
    return {
        data: {
            filter: { mode: 'month', date_from: '2026-09-01', date_to: '2026-09-30' },
            is_empty: false,
            message: null,
            totals: { transactions: 7, revenue: 2800000 },
            funnels: [
                funnel(1, 'Aplikasi', 2, 300000, 10.71),
                funnel(3, 'Sales', 2, 700000, 25),
                { key: 'deleted_funnel', funnel: null, name: 'Webinar', transactions: 0, revenue: 0, contribution_percentage: 0 },
                { key: 'no_funnel', funnel: null, name: 'Tanpa Funnel', transactions: 2, revenue: 1100000, contribution_percentage: 39.29 },
                { key: 'no_code', funnel: null, name: 'Tanpa Kode', transactions: 1, revenue: 700000, contribution_percentage: 25 },
            ],
            ...extra,
        },
    };
}

test('funnel table shows backend rows in order with consistent formatting', async () => {
    const queries = [];
    const { default: promoAnalysisFunnel } = await funnelModule(async (query) => {
        queries.push(query);
        return funnelResponse();
    });
    const table = promoAnalysisFunnel();

    await table.load('year=2026&month=9&platforms%5B%5D=jadiasn');

    assert.deepEqual(queries, ['year=2026&month=9&platforms%5B%5D=jadiasn']);
    assert.equal(table.loading, false);
    assert.deepEqual(table.visibleRows.map((row) => row.name), ['Aplikasi', 'Sales', 'Webinar', 'Tanpa Funnel', 'Tanpa Kode']);
    assert.equal(new Set(table.visibleRows.map((row) => table.rowKey(row))).size, 5);

    assert.match(table.format(table.rows[0].revenue, 'currency'), /^Rp\s?300\.000$/);
    assert.equal(table.format(table.rows[0].contribution_percentage, 'percent'), '10,71%');
    assert.equal(table.format(table.rows[1].contribution_percentage, 'percent'), '25,0%');
    assert.equal(table.format(table.rows[3].transactions, 'number'), '2');
});

test('funnel table shows the empty message instead of zero rows', async () => {
    const { default: promoAnalysisFunnel } = await funnelModule(async () => funnelResponse({
        is_empty: true,
        message: 'Data tidak tersedia untuk filter yang dipilih.',
        totals: { transactions: 0, revenue: 0 },
    }));
    const table = promoAnalysisFunnel();

    await table.load('');

    assert.equal(table.isEmpty, true);
    assert.equal(table.visibleRows.length, 0);
    assert.equal(table.emptyMessage, 'Data tidak tersedia untuk filter yang dipilih.');
});

test('funnel table hides server details, retries, and hands 422 errors back to the filter', async () => {
    const responses = [
        () => { throw { status: 500, data: { message: 'SQLSTATE[42S22]' } }; },
        () => funnelResponse(),
        () => { throw { status: 422, data: { errors: { filter: ['Pilih salah satu filter periode saja: rentang tanggal, atau bulan & tahun.'] } } }; },
    ];
    const queries = [];
    const { default: promoAnalysisFunnel } = await funnelModule(async (query) => {
        queries.push(query);
        return responses.shift()();
    });
    const table = promoAnalysisFunnel();
    const dispatched = [];
    table.$dispatch = (name, detail) => dispatched.push({ name, detail });

    await table.load('q=1');
    assert.equal(table.loadError, 'Gagal memuat rekap funnel.');
    assert.equal(table.rows.length, 0);

    await table.retry();
    assert.deepEqual(queries, ['q=1', 'q=1']);
    assert.equal(table.loadError, '');
    assert.equal(table.rows.length, 5);

    await table.load('q=3');
    assert.equal(table.rows.length, 0);
    assert.equal(table.loadError, '');
    assert.deepEqual(dispatched, [{
        name: 'promo-analysis-errors',
        detail: { filter: 'Pilih salah satu filter periode saja: rentang tanggal, atau bulan & tahun.' },
    }]);
});

test('a slower response for an older filter does not overwrite the newer one', async () => {
    let releaseOld;
    const { default: promoAnalysisFunnel } = await funnelModule((query) => (query === 'old'
        ? new Promise((resolve) => { releaseOld = () => resolve(funnelResponse({ funnels: [] })); })
        : Promise.resolve(funnelResponse())));
    const table = promoAnalysisFunnel();

    const old = table.load('old');
    await table.load('new');
    releaseOld();
    await old;

    assert.equal(table.query, 'new');
    assert.equal(table.rows.length, 5);
    assert.equal(table.loading, false);
});
