import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

async function sourceModule(fetchAnalysisSources) {
    const context = vm.createContext({ Intl, Date, Number, Math, Boolean, Object, String });
    const source = (path) => readFile(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
    const format = new vm.SourceTextModule(await source('format.js'), { context });
    const mocks = {
        '../../http': {
            errorMessage: (error, fallback) => (error.status >= 500 ? fallback : error.data?.message ?? fallback),
            validationErrors: ({ data }) => Object.fromEntries(Object.entries(data.errors).map(([key, messages]) => [key, messages[0]])),
        },
        './api': { fetchAnalysisSources },
    };
    const module = new vm.SourceTextModule(await source('pages/promo/analysis-source.js'), { context });
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

const source = (id, name, usages, revenue, contribution_percentage) => ({
    key: 'source', source: { id, name }, name, usages, revenue, contribution_percentage,
});

const noSource = (usages, revenue, contribution_percentage) => ({
    key: 'no_source', source: null, name: 'Tanpa Sumber', usages, revenue, contribution_percentage,
});

function sourceResponse(extra = {}) {
    return {
        data: {
            filter: { mode: 'month', date_from: '2026-09-01', date_to: '2026-09-30' },
            is_empty: false,
            message: null,
            totals: { transactions: 6, revenue: 2100000, usages: 8, source_revenue: 1500000 },
            sources: [
                source(1, 'Aplikasi', 0, 0, 0),
                source(2, 'Instagram', 2, 100000, 4.76),
                source(3, 'TikTok', 1, 0, 0),
                source(8, 'Reseller', 2, 500000, 23.81),
                { key: 'deleted_source', source: null, name: 'Webinar', usages: 1, revenue: 0, contribution_percentage: 0 },
                noSource(2, 900000, 42.86),
            ],
            ...extra,
        },
    };
}

test('source table shows every backend source in order with consistent formatting', async () => {
    const queries = [];
    const { default: promoAnalysisSource } = await sourceModule(async (query) => {
        queries.push(query);
        return sourceResponse();
    });
    const table = promoAnalysisSource();

    await table.load('year=2026&month=9&search=cpns');

    assert.deepEqual(queries, ['year=2026&month=9&search=cpns']);
    assert.equal(table.loading, false);
    assert.deepEqual(table.visibleRows.map((row) => row.name), ['Aplikasi', 'Instagram', 'TikTok', 'Reseller', 'Webinar', 'Tanpa Sumber']);
    assert.equal(new Set(table.visibleRows.map((row) => table.rowKey(row))).size, 6);

    assert.equal(table.format(table.rows[0].usages, 'number'), '0');
    assert.match(table.format(table.rows[3].revenue, 'currency'), /^Rp\s?500\.000$/);
    assert.equal(table.format(table.rows[3].contribution_percentage, 'percent'), '23,81%');
    assert.equal(table.format(table.rows[2].revenue, 'currency').replace(/\s/g, ''), 'Rp0');
});

test('Tanpa Sumber is listed only when a code usage falls into it', async () => {
    const { default: promoAnalysisSource } = await sourceModule(async () => sourceResponse({
        sources: [source(2, 'Instagram', 2, 100000, 4.76), noSource(0, 0, 0)],
    }));
    const table = promoAnalysisSource();

    await table.load('');

    assert.deepEqual(table.visibleRows.map((row) => row.name), ['Instagram']);
});

test('source table shows the empty message instead of zero rows', async () => {
    const { default: promoAnalysisSource } = await sourceModule(async () => sourceResponse({
        is_empty: true,
        message: 'Data tidak tersedia untuk filter yang dipilih.',
        totals: { transactions: 0, revenue: 0, usages: 0, source_revenue: 0 },
    }));
    const table = promoAnalysisSource();

    await table.load('');

    assert.equal(table.isEmpty, true);
    assert.equal(table.visibleRows.length, 0);
    assert.equal(table.emptyMessage, 'Data tidak tersedia untuk filter yang dipilih.');
});

test('source table hides server details, retries, and hands 422 errors back to the filter', async () => {
    const responses = [
        () => { throw { status: 500, data: { message: 'SQLSTATE[42S22]' } }; },
        () => sourceResponse(),
        () => { throw { status: 422, data: { errors: { search: ['Pencarian kode promo maksimal 100 karakter.'] } } }; },
    ];
    const queries = [];
    const { default: promoAnalysisSource } = await sourceModule(async (query) => {
        queries.push(query);
        return responses.shift()();
    });
    const table = promoAnalysisSource();
    const dispatched = [];
    table.$dispatch = (name, detail) => dispatched.push({ name, detail });

    await table.load('q=1');
    assert.equal(table.loadError, 'Gagal memuat rekap source.');
    assert.equal(table.rows.length, 0);

    await table.retry();
    assert.deepEqual(queries, ['q=1', 'q=1']);
    assert.equal(table.loadError, '');
    assert.equal(table.rows.length, 6);

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
    const { default: promoAnalysisSource } = await sourceModule((query) => (query === 'old'
        ? new Promise((resolve) => { releaseOld = () => resolve(sourceResponse({ sources: [] })); })
        : Promise.resolve(sourceResponse())));
    const table = promoAnalysisSource();

    const old = table.load('old');
    await table.load('new');
    releaseOld();
    await old;

    assert.equal(table.query, 'new');
    assert.equal(table.rows.length, 6);
    assert.equal(table.loading, false);
});
