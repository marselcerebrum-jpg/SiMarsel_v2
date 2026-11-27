import assert from 'node:assert/strict';
import { test } from 'node:test';
import { loadPerformanceModule, plain } from './support/performance-module.mjs';

/** fetch() stand-in for GET /api/performance/trend/sales, answering from the contract fixtures. */
const requests = [];
let respond = null;

const browser = {
    document: { querySelector: () => null },
    window: { location: { search: '', pathname: '/dashboard' }, history: { replaceState() {} } },
    fetch: async (url) => {
        requests.push(url);
        const { status = 200, body } = await respond(new URL(url, 'http://localhost'));

        return { ok: status < 400, status, json: async () => body };
    },
};

const trend = await loadPerformanceModule('combined-trend.js');
const fixtures = await loadPerformanceModule('fixtures/index.js');
const { default: performanceTrend, GRANULARITY_EVENT } = await loadPerformanceModule('trend.js', browser);

const flush = () => new Promise((resolve) => setImmediate(resolve));
const hourly = () => plain(fixtures.todayHourlyTrend);
const daily = () => plain(fixtures.todayDailyTrend);
const series = () => trend.trendSeries(hourly().series);
const visibility = () => trend.defaultVisibility(series());
const bucketAt = (hour) => hourly().buckets[hour];

/** 90 Hari Terakhir: the backend leaves out per jam. */
const ninetyDays = () => ({ ...daily(), granularity: 'weekly', granularities: ['daily', 'weekly', 'monthly'] });

/** The endpoint for the fixture scenario: Hari Ini per jam or per hari, 90 Hari Terakhir per minggu. */
function endpoint(url) {
    const granularity = url.searchParams.get('granularity');

    if (url.searchParams.get('preset') === 'last_90_days') {
        return granularity === 'hourly' ? { status: 422, body: plain(fixtures.hourlyTooLongError.data) } : { body: { data: ninetyDays() } };
    }

    return { body: { data: granularity === 'daily' ? daily() : hourly() } };
}

/** Chart.js stand-in: the x scale maps bucket i to pixel 100 + 50·i inside a 1000 px plot. */
function fakeChart(config) {
    return {
        config,
        width: 1000,
        updates: 0,
        data: config.data,
        options: config.options,
        chartArea: { left: 100, right: 1000, top: 10, bottom: 300 },
        scales: { x: { getPixelForValue: (index) => 100 + 50 * index, getValueForPixel: (x) => (x - 100) / 50 } },
        update() { this.updates += 1; },
        destroy() {},
    };
}

async function mount({ query = 'preset=today', api = endpoint } = {}) {
    const events = [];
    const charts = [];

    requests.length = 0;
    respond = api;

    const component = performanceTrend({
        render: (_canvas, config) => {
            const chart = fakeChart(config);
            charts.push(chart);

            return chart;
        },
    });

    component.$refs = { canvas: { parentElement: { clientWidth: 1000 } } };
    component.$nextTick = (callback) => callback();
    component.$dispatch = (name, detail) => events.push({ name, detail });
    component.applyFilter(query);
    await flush();

    return { component, events, chart: () => charts.at(-1) };
}

test('reads GET /api/performance/trend/sales with the filter query and the requested grouping', async () => {
    const { component } = await mount({ query: 'platforms%5B%5D=app-a&preset=today' });

    assert.deepEqual(requests, ['/api/performance/trend/sales?platforms%5B%5D=app-a&preset=today']);

    component.setGranularity('daily');
    await flush();
    assert.equal(requests.at(-1), '/api/performance/trend/sales?platforms%5B%5D=app-a&preset=today&granularity=daily');
});

test('FIX-06 AC-01: Omzet solid, Omzet Pembanding dashed and Transaksi Berhasil bars show first, on axes by unit', async () => {
    const { component, chart } = await mount();
    const { datasets } = chart().config.data;

    assert.deepEqual(plain(component.series.map(({ label }) => label)), ['Omzet', 'Omzet Pembanding', 'Transaksi Berhasil', 'Total Transaksi']);
    assert.deepEqual(plain(datasets.map(({ label, hidden }) => [label, hidden])), [
        ['Omzet', false],
        ['Omzet Pembanding', false],
        ['Transaksi Berhasil', false],
        ['Total Transaksi', true],
    ]);
    assert.deepEqual(plain(datasets.map(({ type, yAxisID }) => [type, yAxisID])), [
        ['line', 'currency'],
        ['line', 'currency'],
        ['bar', 'count'],
        ['bar', 'count'],
    ]);
    assert.deepEqual(plain(datasets[0].borderDash), [], 'active line is solid');
    assert.deepEqual(plain(datasets[1].borderDash), [6, 4], 'comparison line is dashed');

    const { scales } = chart().config.options;
    assert.equal(scales.currency.title.text, 'Rupiah');
    assert.equal(scales.count.title.text, 'Jumlah Transaksi');
    assert.deepEqual([scales.currency.position, scales.count.position], ['left', 'right']);
    assert.equal(scales.currency.ticks.callback(1500000).replace(/\s/g, ' '), 'Rp 1,5 jt');
    assert.equal(component.chartLabel, 'Grafik tren per jam, 11 Nov 2026, 00.00–01.00 WIB sampai 11 Nov 2026, 23.00–24.00 WIB');
});

test('FE AC-01: the legend toggles all four series, can hide every series and turn them back on', async () => {
    const { component, chart } = await mount();
    const hidden = () => plain(chart().data.datasets.map(({ hidden: value }) => value));

    component.toggleSeries('total_checkouts');
    assert.equal(component.isSeriesVisible('total_checkouts'), true);
    component.toggleSeries('revenue');
    assert.deepEqual(hidden(), [true, false, false, false]);

    for (const key of ['comparison_revenue', 'success_payments', 'total_checkouts']) component.toggleSeries(key);
    assert.deepEqual(hidden(), [true, true, true, true], 'every series can be hidden');

    component.toggleSeries('revenue');
    assert.deepEqual(hidden(), [false, true, true, true], 'the legend turns a series back on');
    assert.ok(chart().updates > 0);
});

test('FE AC-02: a tapped point shows the active time, the comparison time and the unit of each visible series', async () => {
    const { component, chart } = await mount();

    component.toggleSeries('total_checkouts');
    chart().config.options.onClick({ type: 'click', x: 500, y: 150 }, [], chart());

    assert.equal(component.pointIndex, 8);
    assert.equal(component.detailLeft, 372, 'card centred on the point');
    assert.equal(chart().options.plugins.activeGuide.index, 8);
    assert.deepEqual(plain(component.detail), {
        active: '11 Nov 2026, 08.00–09.00 WIB',
        comparison: '10 Nov 2026, 08.00–09.00 WIB',
        rows: [
            { key: 'revenue', label: 'Omzet', pattern: 'solid', color: '#059669', value: 'Rp 360.000' },
            { key: 'comparison_revenue', label: 'Omzet Pembanding', pattern: 'dashed', color: '#64748b', value: 'Rp 180.000' },
            { key: 'success_payments', label: 'Transaksi Berhasil', pattern: 'bar', color: '#3b5b8c', value: '2 transaksi' },
            { key: 'total_checkouts', label: 'Total Transaksi', pattern: 'bar', color: '#f59e0b', value: '2 transaksi' },
        ],
    });

    // Hiding a series removes it from the open detail.
    component.toggleSeries('revenue');
    assert.deepEqual(plain(component.detail.rows.map(({ label }) => label)), ['Omzet Pembanding', 'Transaksi Berhasil', 'Total Transaksi']);

    // Outside the plot area nothing changes; the mouse leaving the canvas closes the detail.
    chart().config.options.onHover({ type: 'mousemove', x: 50, y: 150 }, [], chart());
    assert.equal(component.pointIndex, 8);
    chart().config.options.onHover({ type: 'mouseout', x: 0, y: 0 }, [], chart());
    assert.equal(component.detail, null);
});

test('FE AC-02: the keyboard opens and moves the point detail', async () => {
    const { component } = await mount();

    component.movePoint(1);
    assert.equal(component.pointIndex, 0);
    component.movePoint(-1);
    assert.equal(component.pointIndex, 0, 'stays on the first point');
    component.lastPoint();
    assert.equal(component.pointIndex, 23);
    component.movePoint(1);
    assert.equal(component.pointIndex, 23, 'stays on the last point');
    component.firstPoint();
    component.movePoint(1);
    assert.equal(component.detail.active, '11 Nov 2026, 01.00–02.00 WIB');
    component.closePoint();
    assert.equal(component.pointIndex, null);
});

test('FE AC-03: Omzet 360.000 from the response with the comparison time sent by the backend', () => {
    const detail = trend.pointDetail(bucketAt(8), series(), visibility());

    assert.equal(detail.rows.find(({ key }) => key === 'revenue').value, 'Rp 360.000');
    assert.equal(detail.comparison, '10 Nov 2026, 08.00–09.00 WIB');

    // Per hari: the partial bucket ends at the 14.00 boundary on both sides.
    const day = trend.pointDetail(daily().buckets[0], series(), visibility());
    assert.equal(day.active, '11 Nov 2026, 00.00–14.00 WIB');
    assert.equal(day.comparison, '10 Nov 2026, 00.00–14.00 WIB');
    assert.equal(day.rows[0].value, 'Rp 360.000');
});

test('FIX-06 AC-04: buckets after the 14.00 boundary are gaps, a missing comparison point is empty', () => {
    const [revenue, comparison, successful, total] = trend.trendDatasets(hourly().buckets, series(), visibility());

    for (const { data } of [revenue, successful, total]) {
        assert.deepEqual(plain(data.slice(14)), Array(10).fill(null), 'not drawn as 0');
        assert.equal(data[13], 0, 'a complete hour without sales stays 0');
    }
    assert.deepEqual(plain(comparison.data.slice(10, 12)), [null, null]);
    assert.equal(comparison.spanGaps, false);
    assert.equal(revenue.spanGaps, false);

    const missing = trend.pointDetail(bucketAt(10), series(), visibility());
    assert.equal(missing.comparison, '10 Nov 2026, 10.00–11.00 WIB');
    assert.equal(missing.rows.find(({ key }) => key === 'comparison_revenue').value, 'Belum tersedia');

    const outside = trend.pointDetail(bucketAt(15), series(), visibility());
    assert.equal(outside.active, '11 Nov 2026, 15.00–16.00 WIB');
    assert.equal(outside.comparison, '10 Nov 2026, 15.00–16.00 WIB');
    assert.deepEqual(plain(outside.rows.map(({ value }) => value)), ['Belum tersedia', 'Belum tersedia', 'Belum tersedia']);
    assert.equal(trend.formatBucketTime(null), 'Belum tersedia');
});

test('FIX-06 AC-03: the grouping comes from the response, a change regroups and is sent to the export', async () => {
    const { component, events } = await mount();

    assert.equal(component.granularity, 'hourly');
    assert.deepEqual(plain(events.at(-1)), { name: GRANULARITY_EVENT, detail: { granularity: 'hourly' } });
    assert.deepEqual(plain(trend.GRANULARITIES.map(({ label }) => label)), ['Per Jam', 'Per Hari', 'Per Minggu', 'Per Bulan']);

    component.setGranularity('daily');
    await flush();
    assert.equal(component.granularity, 'daily');
    assert.equal(component.buckets.length, 1);
    assert.deepEqual(plain(events.at(-1).detail), { granularity: 'daily' });

    // A new filter goes back to the backend default.
    component.applyFilter('preset=today');
    await flush();
    assert.equal(requests.at(-1), '/api/performance/trend/sales?preset=today');
    assert.equal(component.granularity, 'hourly');
});

test('FIX-06 AC-03: a grouping the backend does not accept for the range cannot be picked', async () => {
    const { component } = await mount({ query: 'preset=last_90_days' });

    assert.equal(component.granularity, 'weekly');
    assert.equal(component.isGranularityDisabled('hourly'), true);
    assert.equal(component.isGranularityDisabled('monthly'), false);

    const sent = requests.length;
    component.setGranularity('hourly');
    await flush();
    assert.equal(requests.length, sent, 'no request for a disabled grouping');
});

test('series visibility is local state and survives grouping and filter changes', async () => {
    const { component, chart } = await mount();

    component.toggleSeries('total_checkouts');
    component.toggleSeries('revenue');
    component.setGranularity('daily');
    await flush();
    component.applyFilter('preset=today');
    await flush();

    assert.equal(component.isSeriesVisible('total_checkouts'), true);
    assert.equal(component.isSeriesVisible('revenue'), false);
    assert.deepEqual(plain(chart().config.data.datasets.map(({ hidden }) => hidden)), [true, false, false, false]);
});

test('phones show fewer axis labels; tick labels follow the grouping', async () => {
    assert.deepEqual(plain(trend.tickLimits(true)), { x: 4, y: 4 });

    const { chart } = await mount();
    trend.applyTickLimits(chart(), 360);
    const { scales } = chart().options;
    assert.deepEqual([scales.x.ticks.maxTicksLimit, scales.currency.ticks.maxTicksLimit, scales.count.ticks.maxTicksLimit], [4, 4, 4]);
    trend.applyTickLimits(chart(), 1200);
    assert.equal(scales.x.ticks.maxTicksLimit, 12);

    assert.equal(chart().config.data.labels[8], '08.00');
    const bucket = (start_at) => ({ active: { start_at } });
    assert.equal(trend.tickLabel(bucket('2026-11-05T08:00:00+07:00'), 'hourly', true), '5 Nov 08.00');
    assert.equal(trend.tickLabel(bucket('2026-11-04T00:00:00+07:00'), 'daily'), '4 Nov');
    assert.equal(trend.tickLabel(bucket('2026-11-01T00:00:00+07:00'), 'monthly'), 'Nov 2026');
    assert.equal(
        trend.formatBucketTime({ start_at: '2026-11-04T00:00:00+07:00', end_at: '2026-11-09T00:00:00+07:00', availability: 'complete' }),
        '4 Nov 2026, 00.00 – 8 Nov 2026, 24.00 WIB',
    );
});

test('a failed load shows the backend message and no chart', async () => {
    const { component, chart } = await mount({
        query: 'preset=last_90_days&granularity=hourly',
        api: () => ({ status: 422, body: plain(fixtures.hourlyTooLongError.data) }),
    });

    assert.equal(component.hasPoints, false);
    assert.equal(component.trendLoading, false);
    assert.equal(component.trendPlaceholder, 'Rentang tanggal untuk pengelompokan hourly maksimal 31 hari.');
    assert.equal(chart(), undefined);
});
