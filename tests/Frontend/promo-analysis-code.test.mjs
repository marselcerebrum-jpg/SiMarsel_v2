import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

async function loadModule(path, api) {
    const context = vm.createContext({ Intl, Date, Number, Math, Boolean, Object, String, URLSearchParams });
    const source = (file) => readFile(new URL(`../../resources/js/${file}`, import.meta.url), 'utf8');
    const format = new vm.SourceTextModule(await source('format.js'), { context });
    const mocks = {
        '../../http': {
            errorMessage: (error, fallback) => (error.status >= 500 ? fallback : error.data?.message ?? fallback),
            validationErrors: ({ data }) => Object.fromEntries(Object.entries(data.errors).map(([key, messages]) => [key, messages[0]])),
        },
        './api': api,
    };
    const module = new vm.SourceTextModule(await source(path), { context });
    await module.link((specifier) => {
        if (specifier === '../../format') return format;
        const mock = mocks[specifier];
        return new vm.SyntheticModule(Object.keys(mock), function () {
            for (const [key, value] of Object.entries(mock)) this.setExport(key, value);
        }, { context });
    });
    await module.evaluate();

    return module.namespace.default;
}

const platform = { id: 2, slug: 'jadiasn', name: 'JADIASN' };

const registered = {
    code: 'RESELLER01',
    raw_codes: ['RESELLER01'],
    is_registered: true,
    promo_code_id: 7,
    platform,
    funnel: { id: 3, name: 'Sales' },
    source: { id: 8, name: 'Reseller' },
    usages: 2,
    revenue: 500000,
    contribution_percentage: 23.81,
    detail: { platform_id: 2, code: 'RESELLER01', group: 'G1' },
};

const unregistered = {
    code: 'UNREG26',
    raw_codes: [' unreg26 ', 'UNREG26'],
    is_registered: false,
    promo_code_id: null,
    platform,
    funnel: null,
    source: null,
    usages: 2,
    revenue: 0,
    contribution_percentage: 0,
    detail: { platform_id: 2, code: 'UNREG26', group: 'G2' },
};

function codesResponse(extra = {}) {
    return {
        data: [registered, unregistered],
        meta: { current_page: 1, last_page: 3, per_page: 15, from: 1, to: 15, total: 40 },
        totals: { transactions: 6, revenue: 2100000, usages: 7, code_revenue: 1500000 },
        is_empty: false,
        message: null,
        ...extra,
    };
}

test('recap loads with the shared filter, default revenue sort, and shows unregistered raw codes', async () => {
    const queries = [];
    const promoAnalysisCode = await loadModule('pages/promo/analysis-code.js', {
        fetchAnalysisCodes: async (query) => { queries.push(query); return codesResponse(); },
    });
    const table = promoAnalysisCode();

    await table.load('year=2026&month=9&search=reseller');

    assert.deepEqual(queries, ['year=2026&month=9&search=reseller&sort=revenue&direction=desc&page=1']);
    assert.equal(table.loading, false);
    assert.deepEqual(table.rows.map((row) => row.code), ['RESELLER01', 'UNREG26']);
    assert.equal(new Set(table.rows.map((row) => table.rowKey(row))).size, 2);
    assert.equal(table.rawCodes(unregistered), '" unreg26 ", "UNREG26"');
    assert.match(table.format(registered.revenue, 'currency'), /^Rp\s?500\.000$/);
    assert.equal(table.format(registered.contribution_percentage, 'percent'), '23,81%');
    assert.equal(table.format(unregistered.revenue, 'currency').replace(/\s/g, ''), 'Rp0');
});

test('sorting toggles direction, text columns start ascending, and both reset to page 1', async () => {
    const queries = [];
    const promoAnalysisCode = await loadModule('pages/promo/analysis-code.js', {
        fetchAnalysisCodes: async (query) => { queries.push(query); return codesResponse(); },
    });
    const table = promoAnalysisCode();

    await table.load('');
    await table.goToPage(2);
    assert.equal(table.page, 2);

    await table.sortBy('revenue');
    assert.deepEqual({ ...table.sort }, { key: 'revenue', dir: 'asc' });
    assert.equal(table.page, 1);

    await table.sortBy('code');
    assert.deepEqual({ ...table.sort }, { key: 'code', dir: 'asc' });
    assert.equal(table.ariaSort('code'), 'ascending');
    assert.equal(table.ariaSort('revenue'), 'none');

    await table.sortBy('usages');
    assert.deepEqual({ ...table.sort }, { key: 'usages', dir: 'desc' });

    assert.deepEqual(queries, [
        'sort=revenue&direction=desc&page=1',
        'sort=revenue&direction=desc&page=2',
        'sort=revenue&direction=asc&page=1',
        'sort=code&direction=asc&page=1',
        'sort=usages&direction=desc&page=1',
    ]);
});

test('pagination ignores pages outside the range and a new filter returns to page 1', async () => {
    const queries = [];
    const promoAnalysisCode = await loadModule('pages/promo/analysis-code.js', {
        fetchAnalysisCodes: async (query) => { queries.push(query); return codesResponse(); },
    });
    const table = promoAnalysisCode();

    await table.load('year=2026&month=9');
    await table.goToPage(0);
    await table.goToPage(4);
    await table.goToPage(3);
    await table.load('year=2026&month=8');

    assert.deepEqual(queries, [
        'year=2026&month=9&sort=revenue&direction=desc&page=1',
        'year=2026&month=9&sort=revenue&direction=desc&page=3',
        'year=2026&month=8&sort=revenue&direction=desc&page=1',
    ]);
});

test('recap shows the empty message, hides server details, and hands 422 errors to the filter', async () => {
    const responses = [
        () => codesResponse({ data: [], is_empty: true, message: 'Data tidak tersedia untuk filter yang dipilih.' }),
        () => { throw { status: 500, data: { message: 'SQLSTATE[42S22]' } }; },
        () => codesResponse(),
        () => { throw { status: 422, data: { errors: { search: ['Pencarian kode promo maksimal 100 karakter.'] } } }; },
    ];
    const promoAnalysisCode = await loadModule('pages/promo/analysis-code.js', {
        fetchAnalysisCodes: async () => responses.shift()(),
    });
    const table = promoAnalysisCode();
    const dispatched = [];
    table.$dispatch = (name, detail) => dispatched.push({ name, detail });

    await table.load('');
    assert.equal(table.isEmpty, true);
    assert.equal(table.emptyMessage, 'Data tidak tersedia untuk filter yang dipilih.');

    await table.load('q=2');
    assert.equal(table.loadError, 'Gagal memuat rekap kode promo.');
    assert.equal(table.rows.length, 0);

    await table.retry();
    assert.equal(table.loadError, '');
    assert.equal(table.rows.length, 2);

    await table.load('q=4');
    assert.equal(table.rows.length, 0);
    assert.deepEqual(dispatched, [{
        name: 'promo-analysis-errors',
        detail: { search: 'Pencarian kode promo maksimal 100 karakter.' },
    }]);
});

test('a slower response for an older filter does not overwrite the newer one', async () => {
    let releaseOld;
    const promoAnalysisCode = await loadModule('pages/promo/analysis-code.js', {
        fetchAnalysisCodes: (query) => (query.startsWith('old')
            ? new Promise((resolve) => { releaseOld = () => resolve(codesResponse({ data: [] })); })
            : Promise.resolve(codesResponse())),
    });
    const table = promoAnalysisCode();

    const old = table.load('old=1');
    await table.load('new=1');
    releaseOld();
    await old;

    assert.equal(table.rows.length, 2);
    assert.equal(table.loading, false);
});

test('clicking a row opens its detail with the active filter', async () => {
    const promoAnalysisCode = await loadModule('pages/promo/analysis-code.js', {
        fetchAnalysisCodes: async () => codesResponse(),
    });
    const table = promoAnalysisCode();
    const dispatched = [];
    table.$dispatch = (name, detail) => dispatched.push({ name, detail });

    await table.load('year=2026&month=9');
    table.openDetail(unregistered);

    assert.equal(dispatched.length, 1);
    assert.equal(dispatched[0].name, 'promo-code-usage-open');
    assert.equal(dispatched[0].detail.row, unregistered);
    assert.equal(dispatched[0].detail.filterQuery, 'year=2026&month=9');
});

function usagesResponse(extra = {}) {
    const transaction = (trx_id, paid_time, usages, revenue) => ({
        trx_id, paid_date: '2026-09-10', paid_time, platform, code_positions: [2], raw_codes: ['RESELLER01'],
        funnel: registered.funnel, source: registered.source, usages, revenue, transaction_nominal: revenue,
    });

    return {
        data: [
            { date: '2026-09-10', transactions_count: 2, usages: 2, revenue: 500000, transactions: [transaction('jadiasn-2', '11:00:00', 1, 300000), transaction('jadiasn-1', '09:30:00', 1, 200000)] },
            { date: '2026-09-08', transactions_count: 1, usages: 1, revenue: 0, transactions: [{ ...transaction('jadiasn-0', '08:00:00', 1, 0), paid_date: '2026-09-08' }] },
        ],
        meta: { current_page: 1, last_page: 2, per_page: 15, from: 1, to: 15, total: 20 },
        code: { code: 'RESELLER01', is_registered: true, platform, funnel: registered.funnel, source: registered.source, group: 'G1' },
        totals: { transactions: 20, usages: 21, revenue: 4500000 },
        is_empty: false,
        message: null,
        ...extra,
    };
}

test('detail uses the opening filter and the row detail, lists transactions, and shows totals', async () => {
    const queries = [];
    const promoAnalysisCodeUsage = await loadModule('pages/promo/analysis-code-usage.js', {
        fetchAnalysisCodeUsages: async (query) => { queries.push(query); return usagesResponse(); },
    });
    const detail = promoAnalysisCodeUsage();

    await detail.show({ row: registered, filterQuery: 'year=2026&month=9&platforms%5B%5D=jadiasn' });

    assert.equal(detail.open, true);
    assert.deepEqual(queries, ['year=2026&month=9&platforms%5B%5D=jadiasn&platform_id=2&code=RESELLER01&group=G1&page=1']);
    assert.deepEqual(detail.transactions.map((trx) => trx.trx_id), ['jadiasn-2', 'jadiasn-1', 'jadiasn-0']);
    assert.equal(detail.formatDate('2026-09-10'), '10 Sep 2026');
    assert.deepEqual({ ...detail.totals }, { transactions: 20, usages: 21, revenue: 4500000 });

    await detail.goToPage(2);
    await detail.goToPage(3);
    assert.deepEqual(queries.slice(1), ['year=2026&month=9&platforms%5B%5D=jadiasn&platform_id=2&code=RESELLER01&group=G1&page=2']);
});

test('detail handles empty, 422, and server errors, and ignores responses after closing', async () => {
    let release;
    const responses = [
        () => usagesResponse({ data: [], is_empty: true, message: 'Data tidak tersedia untuk filter yang dipilih.' }),
        () => { throw { status: 422, data: { errors: { group: ['Grup klasifikasi tidak valid. Buka detail dari baris rekap kode promo.'] } } }; },
        () => { throw { status: 500, data: { message: 'SQLSTATE[42S22]' } }; },
        () => new Promise((resolve) => { release = () => resolve(usagesResponse()); }),
    ];
    const promoAnalysisCodeUsage = await loadModule('pages/promo/analysis-code-usage.js', {
        fetchAnalysisCodeUsages: async () => responses.shift()(),
    });
    const detail = promoAnalysisCodeUsage();

    await detail.show({ row: registered, filterQuery: '' });
    assert.equal(detail.isEmpty, true);
    assert.equal(detail.emptyMessage, 'Data tidak tersedia untuk filter yang dipilih.');

    await detail.retry();
    assert.equal(detail.loadError, 'Grup klasifikasi tidak valid. Buka detail dari baris rekap kode promo.');

    await detail.retry();
    assert.equal(detail.loadError, 'Gagal memuat detail penggunaan kode promo.');

    const pending = detail.retry();
    detail.close();
    release();
    await pending;

    assert.equal(detail.open, false);
    assert.equal(detail.result, null);
    assert.equal(detail.loading, false);
});
