import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';
import { analysisCopyModule } from './support/analysis-copy-module.mjs';
import {
    defaultFilter,
    filterErrorField,
    isFutureMonth,
    toQueryString,
    validateFilter,
    withMode,
    yearOptions,
} from '../../resources/js/pages/promo/analysis-filter.js';

const NOW = new Date(2026, 10, 3);

async function summaryModule(fetchAnalysisSummary) {
    const context = vm.createContext({ Intl, Date, Number, Math, Boolean, Object, String });
    const source = (path) => readFile(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
    const format = new vm.SourceTextModule(await source('format.js'), { context });
    const mocks = {
        '../../http': {
            errorMessage: (error, fallback) => (error.status >= 500 ? fallback : error.data?.message ?? fallback),
            validationErrors: ({ data }) => Object.fromEntries(Object.entries(data.errors).map(([key, messages]) => [key, messages[0]])),
        },
        './api': { fetchAnalysisSummary },
    };
    const module = new vm.SourceTextModule(await source('pages/promo/analysis-summary.js'), { context });
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

function summaryResponse(kpis, extra = {}) {
    return {
        data: {
            filter: { mode: 'month', date_from: '2026-09-01', date_to: '2026-09-30', search: null },
            previous_period: { date_from: '2026-08-01', date_to: '2026-08-31' },
            is_empty: false,
            message: null,
            kpis,
            ...extra,
        },
    };
}

test('default filter is the current month with every platform and payment method', () => {
    assert.deepEqual(defaultFilter(NOW), {
        platforms: [], payment_methods: [], mode: 'month', year: '2026', month: '11', date_from: '', date_to: '', search: '',
    });
    // No period parameter: the API default, the 1st of this month until today.
    assert.equal(toQueryString(defaultFilter(NOW), NOW), '');
    assert.equal(toQueryString({ ...defaultFilter(NOW), month: '10' }, NOW), 'year=2026&month=10');
    assert.equal(toQueryString({ ...defaultFilter(NOW), year: '2025' }, NOW), 'year=2025&month=11');
});

test('query sends one period mode with platforms, payment methods and trimmed search', () => {
    const filter = {
        ...withMode(defaultFilter(NOW), 'date_range', NOW),
        platforms: ['jadiasn', 'cerebrum'],
        payment_methods: ['BCA', 'Tidak diketahui'],
        search: '  cpns ',
    };

    const params = new URLSearchParams(toQueryString(filter));

    assert.deepEqual(params.getAll('platforms[]'), ['jadiasn', 'cerebrum']);
    assert.deepEqual(params.getAll('payment_methods[]'), ['BCA', 'Tidak diketahui']);
    assert.equal(params.get('date_from'), '2026-11-01');
    assert.equal(params.get('date_to'), '2026-11-03');
    assert.equal(params.get('search'), 'cpns');
    assert.equal(params.has('year'), false);
    assert.equal(params.has('month'), false);
});

test('switching period mode keeps the other filters', () => {
    const filter = { ...defaultFilter(NOW), platforms: ['jadiasn'], payment_methods: ['QRIS'], search: 'abc' };
    const next = withMode(withMode(filter, 'date_range', NOW), 'month', NOW);

    assert.deepEqual(next, { ...defaultFilter(NOW), platforms: ['jadiasn'], payment_methods: ['QRIS'], search: 'abc' });
});

test('validation catches an incomplete or reversed period before the request', () => {
    const range = { ...defaultFilter(NOW), mode: 'date_range' };

    assert.deepEqual(validateFilter(range), { date_from: 'Tanggal mulai wajib diisi.', date_to: 'Tanggal akhir wajib diisi.' });
    assert.deepEqual(
        validateFilter({ ...range, date_from: '2026-10-10', date_to: '2026-10-01' }),
        { date_to: 'Tanggal akhir tidak boleh sebelum tanggal mulai.' },
    );
    assert.deepEqual(validateFilter({ ...defaultFilter(NOW), month: '' }), { month: 'Bulan wajib dipilih.' });
    assert.ok(validateFilter({ ...defaultFilter(NOW), search: 'x'.repeat(101) }).search);
    assert.deepEqual(validateFilter(defaultFilter(NOW)), {});
});

test('future months and years are recognised and backend error keys map to fields', () => {
    assert.equal(isFutureMonth(2026, 12, NOW), true);
    assert.equal(isFutureMonth(2026, 11, NOW), false);
    assert.equal(isFutureMonth(2025, 12, NOW), false);
    assert.deepEqual(yearOptions(NOW).slice(0, 2), [2026, 2025]);
    assert.equal(yearOptions(NOW).at(-1), 2000);
    assert.equal(filterErrorField('payment_methods.1'), 'payment_methods');
    assert.equal(filterErrorField('platform'), 'platforms');
    assert.equal(filterErrorField('filter'), 'filter');
});

test('comparison only shows up/down against a non-zero previous value', async () => {
    const { comparison } = await summaryModule(async () => ({}));

    assert.equal(comparison({ value: 3, previous_value: 1, change_percentage: 200, trend: 'up' }).short, '↑ 200,0%');
    assert.equal(comparison({ value: 2, previous_value: 4, change_percentage: -50, trend: 'down' }).short, '↓ 50,0%');

    // No valid base: no up/down, and no division by zero.
    assert.equal(comparison({ value: 5, previous_value: 0, change_percentage: null, trend: 'up' }), null);
    assert.equal(comparison({ value: 60, previous_value: null, change_percentage: null, trend: 'up' }), null);
    assert.equal(comparison(undefined), null);
});

test('summary formats currency and percentage consistently', async () => {
    const { default: promoAnalysisSummary } = await summaryModule(async () => summaryResponse({
        promo_transactions: { value: 3, previous_value: 1, change_percentage: 200, trend: 'up' },
        non_promo_transactions: { value: 2, previous_value: 1, change_percentage: 100, trend: 'up' },
        promo_revenue: { value: 700000, previous_value: 150000, change_percentage: 366.67, trend: 'up' },
        promo_transaction_percentage: { value: 60, previous_value: 50, change_percentage: 20, trend: 'up' },
    }));
    const summary = promoAnalysisSummary();

    await summary.load('year=2026&month=9');

    assert.equal(summary.loading, false);
    assert.match(summary.value('promo_revenue', 'currency'), /^Rp\s?700\.000$/);
    assert.equal(summary.value('promo_transaction_percentage', 'percent'), '60,0%');
});

test('summary reports empty data, server errors, and hands 422 errors back to the filter', async () => {
    const responses = [
        () => summaryResponse({}, { is_empty: true, message: 'Data tidak tersedia untuk filter yang dipilih.' }),
        () => { throw { status: 500, data: { message: 'SQLSTATE[42S22]' } }; },
        () => { throw { status: 422, data: { errors: { 'payment_methods.0': ['Metode pembayaran harus berupa teks.'] } } }; },
    ];
    const { default: promoAnalysisSummary } = await summaryModule(async () => responses.shift()());
    const summary = promoAnalysisSummary();
    const dispatched = [];
    summary.$dispatch = (name, detail) => dispatched.push({ name, detail });

    await summary.load('q=1');
    assert.equal(summary.isEmpty, true);
    assert.equal(summary.emptyMessage, 'Data tidak tersedia untuk filter yang dipilih.');

    await summary.load('q=2');
    assert.equal(summary.loadError, 'Gagal memuat ringkasan promo.');
    assert.equal(summary.summary, null);

    await summary.load('q=3');
    assert.equal(summary.loadError, '');
    assert.deepEqual(dispatched, [{ name: 'promo-analysis-errors', detail: { 'payment_methods.0': 'Metode pembayaran harus berupa teks.' } }]);
});
