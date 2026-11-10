import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';
import { analysisCopyModule } from './support/analysis-copy-module.mjs';

// Arrays built inside the vm context have another Array prototype; compare them as plain data.
const plain = (value) => JSON.parse(JSON.stringify(value));

const source = (path) => readFile(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');

function syntheticModule(exports, context) {
    return new vm.SyntheticModule(Object.keys(exports), function () {
        for (const [key, value] of Object.entries(exports)) this.setExport(key, value);
    }, { context });
}

async function chartModule(context, charts = []) {
    class Chart {
        static defaults = { font: {} };

        constructor(canvas, config) {
            this.canvas = canvas;
            this.config = config;
            this.destroyed = false;
            charts.push(this);
        }

        destroy() {
            this.destroyed = true;
        }
    }

    const format = new vm.SourceTextModule(await source('format.js'), { context });
    const module = new vm.SourceTextModule(await source('pages/promo/chart.js'), { context });
    await module.link((specifier) => {
        if (specifier === '../../format') return format;
        if (specifier === 'chart.js/auto') return syntheticModule({ default: Chart }, context);
        throw new Error(`Unexpected import: ${specifier}`);
    });

    return module;
}

async function trendModule(fetchAnalysisTrend, charts = []) {
    const context = vm.createContext({ Intl, Date, Number, Math, Boolean, Object, String, URLSearchParams });
    const chart = await chartModule(context, charts);
    const mocks = {
        alpinejs: { default: { raw: (value) => value } },
        '../../http': {
            errorMessage: (error, fallback) => (error.status >= 500 ? fallback : error.data?.message ?? fallback),
            validationErrors: ({ data }) => Object.fromEntries(Object.entries(data.errors).map(([key, messages]) => [key, messages[0]])),
        },
        './api': { fetchAnalysisTrend },
    };
    const module = new vm.SourceTextModule(await source('pages/promo/analysis-trend.js'), { context });
    const copy = await analysisCopyModule(context);
    await module.link((specifier) => {
        if (specifier === './chart') return chart;
        if (specifier === './analysis-copy') return copy;
        return syntheticModule(mocks[specifier], context);
    });
    await module.evaluate();

    return { trend: module.namespace, chart: chart.namespace };
}

function trendComponent(factory) {
    const component = factory();
    component.$refs = { canvas: {} };
    component.$nextTick = (callback) => callback();
    component.dispatched = [];
    component.$dispatch = (name, detail) => component.dispatched.push({ name, detail });

    return component;
}

const datasets = (promo = [1, 2, null], revenue = [100000, 500000, null], sales = [500000, 900000, null]) => [
    { key: 'promo_transactions', label: 'Transaksi dengan Promo', data: promo },
    { key: 'promo_revenue', label: 'Revenue dengan Promo', data: revenue },
    { key: 'total_transactions', label: 'Total Transaksi', data: [2, 3, null] },
    { key: 'total_sales', label: 'Total Penjualan', data: sales },
];

function trendResponse(extra = {}) {
    return {
        data: {
            filter: { mode: 'month', date_from: '2026-09-01', date_to: '2026-09-30' },
            period: 'weekly',
            is_empty: false,
            message: null,
            totals: { promo_transactions: 3, promo_revenue: 600000, total_transactions: 5, total_sales: 1400000 },
            labels: ['01 - 06 Sep 2026', '07 - 13 Sep 2026', '14 - 20 Sep 2026'],
            datasets: datasets(),
            rows: [],
            ...extra,
        },
    };
}

test('chart shows promo transactions, promo revenue, and the total sales overlay with backend labels', async () => {
    const { chart } = await trendModule(async () => trendResponse());
    const config = chart.trendChartConfig(trendResponse().data);

    assert.deepEqual(plain(config.data.datasets.map((set) => set.label)), ['Transaksi dengan Promo', 'Revenue dengan Promo', 'Total Penjualan']);
    assert.deepEqual(plain(config.data.datasets.map((set) => set.type)), ['bar', 'line', 'line']);
    assert.deepEqual(plain(config.data.datasets.map((set) => set.yAxisID)), ['count', 'revenue', 'revenue']);
    assert.ok(config.data.datasets.every((set) => !/penggunaan/i.test(set.label)));
    assert.deepEqual(plain(config.data.datasets[0].data), [1, 2, null]);
    assert.equal(config.options.plugins.legend.position, 'bottom');
    const legendSort = config.options.plugins.legend.labels.sort;
    assert.deepEqual(plain([{ datasetIndex: 2 }, { datasetIndex: 0 }, { datasetIndex: 1 }].sort(legendSort)), [{ datasetIndex: 0 }, { datasetIndex: 1 }, { datasetIndex: 2 }]);

    assert.equal(config.options.plugins.tooltip.itemSort({ datasetIndex: 2 }, { datasetIndex: 0 }) > 0, true);

    const label = config.options.plugins.tooltip.callbacks.label;
    assert.equal(label({ datasetIndex: 0, raw: 2, dataset: config.data.datasets[0] }), ' Transaksi dengan Promo: 2');
    assert.match(label({ datasetIndex: 2, raw: 900000, dataset: config.data.datasets[2] }), /^ Total Penjualan: Rp\s?900\.000$/);
    assert.equal(label({ datasetIndex: 1, raw: null, dataset: config.data.datasets[1] }), ' Revenue dengan Promo: —');
});

test('trend sends the shared filter with the chosen bucket and redraws on every change', async () => {
    const queries = [];
    const charts = [];
    const { trend } = await trendModule(async (query) => {
        queries.push(query);
        return trendResponse();
    }, charts);
    const component = trendComponent(trend.default);

    await component.load('year=2026&month=9&search=cpns');
    assert.equal(component.activePeriod, 'daily');
    assert.equal(component.hasPoints, true);
    assert.equal(component.loading, false);
    assert.equal(charts.length, 1);

    await component.setPeriod('weekly');
    await component.load('year=2026&month=8');
    await component.setPeriod('weekly');

    assert.deepEqual(queries, [
        'year=2026&month=9&search=cpns&period=daily',
        'year=2026&month=9&search=cpns&period=weekly',
        'year=2026&month=8&period=weekly',
    ]);
    assert.equal(charts.length, 3);
    assert.deepEqual(charts.map((item) => item.destroyed), [true, true, false]);
    assert.match(component.chartLabel, /dari 01 - 06 Sep 2026 sampai 14 - 20 Sep 2026$/);
});

test('a date range over 366 days only allows the monthly bucket', async () => {
    const queries = [];
    const { trend } = await trendModule(async (query) => {
        queries.push(query);
        return trendResponse();
    });
    const component = trendComponent(trend.default);

    assert.equal(trend.rangeDays('date_from=2025-01-01&date_to=2026-01-01'), 366);
    assert.equal(trend.rangeDays('year=2026&month=9'), 0);

    await component.load('date_from=2025-01-01&date_to=2026-01-02');
    assert.equal(component.isPeriodDisabled('daily'), true);
    assert.equal(component.isPeriodDisabled('weekly'), true);
    assert.equal(component.isPeriodDisabled('monthly'), false);
    assert.equal(component.activePeriod, 'monthly');

    await component.setPeriod('daily');
    await component.load('date_from=2025-01-01&date_to=2025-12-31');

    assert.deepEqual(queries, [
        'date_from=2025-01-01&date_to=2026-01-02&period=monthly',
        'date_from=2025-01-01&date_to=2025-12-31&period=daily',
    ]);
});

test('an empty result shows the backend message instead of a chart', async () => {
    const charts = [];
    const { trend } = await trendModule(async () => trendResponse({
        is_empty: true,
        message: 'Data tidak tersedia untuk filter yang dipilih.',
        datasets: datasets([0, 0, 0], [0, 0, 0], [0, 0, 0]),
    }), charts);
    const component = trendComponent(trend.default);

    await component.load('');

    assert.equal(component.hasPoints, false);
    assert.equal(component.placeholder, 'Data tidak tersedia untuk filter yang dipilih.');
    assert.equal(charts.length, 0);
});

test('trend hides server details, retries, and hands 422 errors back to the filter', async () => {
    const responses = [
        () => { throw { status: 500, data: { message: 'SQLSTATE[42S22]' } }; },
        () => trendResponse(),
        () => { throw { status: 422, data: { errors: { date_to: ['Rentang tanggal untuk periode daily maksimal 366 hari.'] } } }; },
    ];
    const { trend } = await trendModule(async () => responses.shift()());
    const component = trendComponent(trend.default);

    await component.load('q=1');
    assert.equal(component.loadError, 'Gagal memuat grafik tren promo.');
    assert.equal(component.placeholder, 'Gagal memuat grafik tren promo.');

    await component.retry();
    assert.equal(component.loadError, '');
    assert.equal(component.hasPoints, true);

    await component.load('q=3');
    assert.equal(component.hasPoints, false);
    assert.equal(component.loadError, '');
    assert.deepEqual(component.dispatched, [{
        name: 'promo-analysis-errors',
        detail: { date_to: 'Rentang tanggal untuk periode daily maksimal 366 hari.' },
    }]);
});

test('a slower response for an older filter does not overwrite the newer one', async () => {
    let releaseOld;
    const { trend } = await trendModule((query) => (query.startsWith('old')
        ? new Promise((resolve) => { releaseOld = () => resolve(trendResponse({ labels: ['x'] })); })
        : Promise.resolve(trendResponse())));
    const component = trendComponent(trend.default);

    const old = component.load('old');
    await component.load('new');
    releaseOld();
    await old;

    assert.equal(component.query, 'new');
    assert.equal(component.result.labels.length, 3);
    assert.equal(component.loading, false);
});
