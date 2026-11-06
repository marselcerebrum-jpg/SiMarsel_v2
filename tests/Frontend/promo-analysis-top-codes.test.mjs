import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';
import { analysisCopyModule } from './support/analysis-copy-module.mjs';

async function topCodesModule(fetchAnalysisTopCodes) {
    const context = vm.createContext({ Intl, Date, Number, Math, Boolean, Object, String });
    const source = (path) => readFile(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
    const format = new vm.SourceTextModule(await source('format.js'), { context });
    const mocks = {
        '../../http': {
            errorMessage: (error, fallback) => (error.status >= 500 ? fallback : error.data?.message ?? fallback),
            validationErrors: ({ data }) => Object.fromEntries(Object.entries(data.errors).map(([key, messages]) => [key, messages[0]])),
        },
        './api': { fetchAnalysisTopCodes },
    };
    const module = new vm.SourceTextModule(await source('pages/promo/analysis-top-codes.js'), { context });
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

const platform = (id, name) => ({ id, slug: name.toLowerCase(), name });

const code = (rank, value, usages, revenue, extra = {}) => ({
    rank,
    code: value,
    raw_codes: [value],
    is_registered: true,
    promo_code_id: rank,
    platform: platform(2, 'JADIASN'),
    usages,
    revenue,
    contribution_percentage: 0,
    detail: { platform_id: 2, code: value },
    ...extra,
});

function topCodesResponse(extra = {}) {
    return {
        data: {
            filter: { mode: 'month', date_from: '2026-09-01', date_to: '2026-09-30' },
            is_empty: false,
            message: null,
            codes: [
                code(1, 'RESELLER01', 4, 0),
                code(2, 'CPNS26', 3, 900000),
                code(3, 'CPNS26', 2, 300000, { platform: platform(1, 'CEREBRUM') }),
                code(4, 'PROMOBARU', 1, 150000, { is_registered: false, promo_code_id: null, raw_codes: ['promobaru', ' PromoBaru '] }),
            ],
            ...extra,
        },
    };
}

test('top codes keep the backend rank order with usages and revenue formatted', async () => {
    const queries = [];
    const { default: promoAnalysisTopCodes } = await topCodesModule(async (query) => {
        queries.push(query);
        return topCodesResponse();
    });
    const widget = promoAnalysisTopCodes();

    await widget.load('year=2026&month=9&search=cpns');

    assert.deepEqual(queries, ['year=2026&month=9&search=cpns']);
    assert.equal(widget.loading, false);
    assert.deepEqual(widget.codes.map((row) => [row.rank, row.code]), [[1, 'RESELLER01'], [2, 'CPNS26'], [3, 'CPNS26'], [4, 'PROMOBARU']]);
    assert.equal(new Set(widget.codes.map((row) => widget.rowKey(row))).size, 4);

    assert.equal(widget.format(widget.codes[0].usages, 'number'), '4');
    assert.equal(widget.format(widget.codes[0].revenue, 'currency').replace(/\s/g, ''), 'Rp0');
    assert.match(widget.format(widget.codes[1].revenue, 'currency'), /^Rp\s?900\.000$/);
});

test('a registered code shows its code and an unregistered code shows its raw code', async () => {
    const { default: promoAnalysisTopCodes } = await topCodesModule(async () => topCodesResponse());
    const widget = promoAnalysisTopCodes();

    await widget.load('');

    assert.equal(widget.displayCode(widget.codes[0]), 'RESELLER01');
    assert.equal(widget.displayCode(widget.codes.at(-1)), 'promobaru, PromoBaru');
    assert.equal(widget.displayCode({ ...widget.codes.at(-1), raw_codes: [] }), 'PROMOBARU');
});

test('top codes show the backend empty message', async () => {
    const { default: promoAnalysisTopCodes } = await topCodesModule(async () => topCodesResponse({
        is_empty: true,
        message: 'Tidak ada penggunaan kode promo pada filter yang dipilih.',
        codes: [],
    }));
    const widget = promoAnalysisTopCodes();

    await widget.load('');

    assert.equal(widget.isEmpty, true);
    assert.equal(widget.codes.length, 0);
    assert.equal(widget.emptyMessage, 'Tidak ada penggunaan kode promo pada filter yang dipilih.');
});

test('top codes hide server details, retry, and hand 422 errors back to the filter', async () => {
    const responses = [
        () => { throw { status: 500, data: { message: 'SQLSTATE[42S22]' } }; },
        () => topCodesResponse(),
        () => { throw { status: 422, data: { errors: { search: ['Pencarian kode promo maksimal 100 karakter.'] } } }; },
    ];
    const queries = [];
    const { default: promoAnalysisTopCodes } = await topCodesModule(async (query) => {
        queries.push(query);
        return responses.shift()();
    });
    const widget = promoAnalysisTopCodes();
    const dispatched = [];
    widget.$dispatch = (name, detail) => dispatched.push({ name, detail });

    await widget.load('q=1');
    assert.equal(widget.loadError, 'Gagal memuat 5 kode promo teratas.');
    assert.equal(widget.codes.length, 0);

    await widget.retry();
    assert.deepEqual(queries, ['q=1', 'q=1']);
    assert.equal(widget.loadError, '');
    assert.equal(widget.codes.length, 4);

    await widget.load('q=3');
    assert.equal(widget.codes.length, 0);
    assert.equal(widget.loadError, '');
    assert.deepEqual(dispatched, [{
        name: 'promo-analysis-errors',
        detail: { search: 'Pencarian kode promo maksimal 100 karakter.' },
    }]);
});

test('a slower response for an older filter does not overwrite the newer one', async () => {
    let releaseOld;
    const { default: promoAnalysisTopCodes } = await topCodesModule((query) => (query === 'old'
        ? new Promise((resolve) => { releaseOld = () => resolve(topCodesResponse({ codes: [] })); })
        : Promise.resolve(topCodesResponse())));
    const widget = promoAnalysisTopCodes();

    const old = widget.load('old');
    await widget.load('new');
    releaseOld();
    await old;

    assert.equal(widget.query, 'new');
    assert.equal(widget.codes.length, 4);
    assert.equal(widget.loading, false);
});
