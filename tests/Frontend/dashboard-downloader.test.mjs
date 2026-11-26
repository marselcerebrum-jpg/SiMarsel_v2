import assert from 'node:assert/strict';
import { test } from 'node:test';
import { loadPerformanceModule, plain } from './support/performance-module.mjs';

const browser = {
    document: { querySelector: () => null },
    window: { location: { search: '', pathname: '/dashboard' }, history: { replaceState() {} } },
};

const trend = await loadPerformanceModule('downloader-trend.js');
const fixtures = await loadPerformanceModule('fixtures/index.js');
const { default: performanceDownloader } = await loadPerformanceModule('downloader.js', browser);

const flush = () => new Promise((resolve) => setImmediate(resolve));
const HOUR_14 = 13;

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

/** Backend stand-in: answers each requested grouping with a contract fixture and records the requests. */
function backend(byGranularity) {
    const calls = [];
    const resolve = (query, options) => {
        calls.push({ query, ...options });
        const response = byGranularity[options.granularity ?? 'default'];

        return response ? Promise.resolve(plain(response)) : Promise.reject(new Error('unexpected request'));
    };

    return { calls, resolve };
}

async function mount(byGranularity, query = 'preset=today') {
    const charts = [];
    const { calls, resolve } = backend(byGranularity);
    const component = performanceDownloader({
        resolve,
        render: (_canvas, config) => {
            const chart = fakeChart(config);
            charts.push(chart);

            return chart;
        },
    });

    component.$refs = { downloaderCanvas: { parentElement: { clientWidth: 1000 } } };
    component.$nextTick = (callback) => callback();
    component.applyDownloaderFilter(query);
    await flush();

    return { component, calls, chart: () => charts.at(-1) };
}

const today = () => mount({ default: fixtures.todayHourlyDownloaders, daily: fixtures.todayDailyDownloaders });

test('FIX-08 AC-01: the active line is solid, the comparison line is dashed, and the legend uses the response labels', async () => {
    const { component, calls, chart } = await today();
    const [active, comparison] = chart().config.data.datasets;

    assert.deepEqual(plain(calls), [{ query: 'preset=today', granularity: null }], 'the backend picks the default grouping');
    assert.deepEqual(plain(component.downloaderSeries.map(({ label, pattern }) => [label, pattern])), [
        ['Downloader', 'solid'],
        ['Downloader Pembanding', 'dashed'],
    ]);
    assert.deepEqual(plain(active.borderDash), []);
    assert.deepEqual(plain(comparison.borderDash), [6, 4]);
    assert.equal(chart().config.options.scales.y.title.text, 'Downloader');
});

test('FE AC-01: the tapped 14.00 point shows the same period and numbers as its table row', async () => {
    const { component, chart } = await today();

    chart().config.options.onClick({ type: 'click', x: 100 + 50 * HOUR_14, y: 150 }, [], chart());

    assert.equal(component.downloaderPoint, HOUR_14);
    assert.deepEqual(plain(component.downloaderDetail), plain(component.downloaderRows[HOUR_14]));
    assert.deepEqual(plain(component.downloaderDetail), {
        key: '13',
        activePeriod: '11 Nov 2026, sampai 14.00 WIB',
        provisional: '',
        downloader: '88',
        comparisonPeriod: '10 Nov 2026, sampai 14.00 WIB',
        comparisonDownloader: '80',
        change: '+10%',
    });
});

test('FIX-08 AC-02: the table has one row per chart point and follows the grouping of the chart', async () => {
    const { component, calls, chart } = await today();

    component.toggleDetails();
    assert.equal(component.detailsOpen, true);
    assert.equal(component.downloaderRows.length, 24);
    assert.equal(chart().config.data.labels.length, 24);
    assert.equal(chart().config.data.labels[HOUR_14], '14.00');

    component.setDownloaderGranularity('daily');
    await flush();
    assert.deepEqual(plain(calls.at(-1)), { query: 'preset=today', granularity: 'daily' });
    assert.equal(component.downloaderRows.length, 1);
    assert.equal(chart().config.data.labels.length, 1);
    assert.deepEqual(plain(component.downloaderRows[0]), {
        key: '0',
        activePeriod: '11 Nov 2026, sampai 14.00 WIB',
        provisional: 'Sementara',
        downloader: '88',
        comparisonPeriod: '10 Nov 2026, sampai 14.00 WIB',
        comparisonDownloader: '80',
        change: '+10%',
    });
    assert.equal(component.detailsOpen, true, 'the table stays open when the grouping changes');
});

test('FIX-08 AC-03: without the 14.00 comparison record the comparison line breaks and the active value stays', async () => {
    const { component, chart } = await mount({ default: fixtures.todayMissingCutoffComparisonDownloaders });
    const [active, comparison] = chart().config.data.datasets;
    const row = component.downloaderRows[HOUR_14];

    assert.equal(comparison.data[HOUR_14], null);
    assert.equal(comparison.spanGaps, false);
    assert.equal(active.data[HOUR_14], 88);
    assert.equal(row.downloader, '88');
    assert.equal(row.comparisonDownloader, 'Pembanding belum tersedia');
    assert.equal(row.change, '', 'no change percentage');
});

test('FE AC-02: a missing comparison point breaks the line, a real comparison of 0 stays 0', async () => {
    const { component, chart } = await today();
    const [active, comparison] = chart().config.data.datasets;

    assert.equal(comparison.data[10], null, '11.00 is not drawn');
    assert.deepEqual([comparison.data[9], comparison.data[11]], [47, 60]);
    assert.equal(component.downloaderRows[10].comparisonDownloader, 'Pembanding belum tersedia');
    assert.equal(component.downloaderRows[10].downloader, '58');
    assert.equal(component.downloaderRows[10].change, '');

    assert.equal(comparison.data[0], 0, 'a real 0 is drawn as 0');
    assert.equal(component.downloaderRows[0].comparisonDownloader, '0');
    assert.equal(component.downloaderRows[0].change, 'Belum dapat dihitung');

    assert.equal(active.data[14], null, 'hours after the boundary are gaps, not 0');
    assert.equal(component.downloaderRows[14].downloader, 'Belum tersedia');
});

test('FE AC-03: the full period shows the latest total 99 while the 14.00 record stays 80', async () => {
    const before = await mount({ daily: fixtures.yesterdayDailyBeforeRevisionDownloaders, default: fixtures.yesterdayDailyBeforeRevisionDownloaders }, 'preset=yesterday');
    assert.equal(before.component.downloaderRows[0].downloader, '100');

    const after = await mount({ default: fixtures.yesterdayDailyDownloaders }, 'preset=yesterday');
    assert.equal(after.component.downloaderRows[0].downloader, '99');
    assert.equal(after.chart().config.data.datasets[0].data[0], 99);
    assert.equal(after.component.downloaderRows[0].activePeriod, '10 Nov 2026');

    const hourly = await mount({ default: fixtures.yesterdayHourlyDownloaders }, 'preset=yesterday');
    assert.equal(hourly.component.downloaderRows[HOUR_14].downloader, '80');
    assert.equal(hourly.component.downloaderRows[HOUR_14].activePeriod, '10 Nov 2026, sampai 14.00 WIB');
    assert.equal(hourly.component.downloaderRows.at(-1).downloader, '99');
    assert.equal(hourly.component.downloaderRows.at(-1).activePeriod, '10 Nov 2026, sampai 24.00 WIB');

    const { component } = await today();
    assert.equal(component.downloaderRows[HOUR_14].comparisonDownloader, '80', 'the equivalent hour uses the stored record');
});

test('FIX-08 AC-04: the Downloader rows add up to the period total and the platform total of the same response', async () => {
    const { component } = await mount({ default: fixtures.last7DaysDownloaders }, 'preset=last_7_days');
    const response = fixtures.last7DaysDownloaders;
    const total = response.buckets.reduce((sum, { active }) => sum + active.value, 0);

    assert.equal(component.downloaderRows.length, 7);
    assert.equal(total, 557);
    assert.equal(response.totals.active.value, total);
    assert.equal(response.platforms[0].value, total);
    assert.deepEqual(plain(component.downloaderRows.map(({ activePeriod }) => activePeriod)).slice(0, 2), ['4 Nov 2026', '5 Nov 2026']);
});

test('only daily totals on the comparison day: the hourly comparison stays empty and only the full day is compared', async () => {
    const { component } = await mount({ default: fixtures.yesterdayHourlyDownloaders }, 'preset=yesterday');

    assert.ok(component.downloaderRows.slice(0, 23).every(({ comparisonDownloader }) => comparisonDownloader === 'Pembanding belum tersedia'));
    assert.equal(component.downloaderRows[23].comparisonDownloader, '90');
    assert.equal(component.downloaderRows[23].change, '+10%');
});

test('the groupings come from the response, and the completeness boundary is shown', async () => {
    const { component } = await today();
    assert.deepEqual(plain(component.downloaderGranularities.map(({ label }) => label)), ['Per Jam', 'Per Hari', 'Per Minggu', 'Per Bulan']);
    assert.equal(component.downloaderCompleteness, 'Batas lengkap: 11 Nov 2026, 14.00 WIB');

    const longRange = { ...plain(fixtures.last7DaysDownloaders), granularities: ['daily', 'weekly', 'monthly'] };
    const daily = await mount({ default: longRange }, 'preset=last_7_days');
    assert.deepEqual(plain(daily.component.downloaderGranularities.map(({ value }) => value)), ['daily', 'weekly', 'monthly']);
});

test('a multi-day bucket shows its dates; a partial one adds its cutoff', () => {
    const week = { start_at: '2026-11-04T00:00:00+07:00', end_at: '2026-11-11T00:00:00+07:00', availability: 'complete', as_of: '2026-11-11T00:00:00+07:00' };
    const partial = { start_at: '2026-11-09T00:00:00+07:00', end_at: '2026-11-12T00:00:00+07:00', availability: 'partial', as_of: '2026-11-11T14:00:00+07:00' };

    assert.equal(trend.formatDownloaderPeriod(week, 'weekly'), '4 Nov 2026 – 10 Nov 2026');
    assert.equal(trend.formatDownloaderPeriod(partial, 'weekly'), '9 Nov 2026 – 11 Nov 2026, sampai 14.00 WIB');
});

test('hovering opens the point detail, and the mouse leaving closes it', async () => {
    const { component, chart } = await today();

    chart().config.options.onHover({ type: 'mousemove', x: 100, y: 150 }, [], chart());
    assert.equal(component.downloaderDetail.activePeriod, '11 Nov 2026, sampai 01.00 WIB');

    chart().config.options.onHover({ type: 'mouseout', x: 0, y: 0 }, [], chart());
    assert.equal(component.downloaderDetail, null);
});

test('phones show fewer axis labels with the same data', async () => {
    const { chart } = await today();

    trend.applyDownloaderTicks(chart(), 360);
    assert.deepEqual([chart().options.scales.x.ticks.maxTicksLimit, chart().options.scales.y.ticks.maxTicksLimit], [4, 4]);
    trend.applyDownloaderTicks(chart(), 1200);
    assert.equal(chart().options.scales.x.ticks.maxTicksLimit, 12);
    assert.equal(chart().config.data.datasets[0].data.length, 24);
});

test('a response from an older filter never replaces the latest one', async () => {
    let releaseOld;
    const old = new Promise((resolve) => { releaseOld = resolve; });
    const component = performanceDownloader({
        resolve: (query) => (query === 'old' ? old : Promise.resolve(plain(fixtures.yesterdayDailyDownloaders))),
    });
    component.$nextTick = (callback) => callback();

    component.applyDownloaderFilter('old');
    component.applyDownloaderFilter('new');
    await flush();
    releaseOld(plain(fixtures.todayHourlyDownloaders));
    await flush();

    assert.equal(component.downloaderRows[0].downloader, '99');
});

test('a failed load shows the error and no chart or table', async () => {
    const { component, chart } = await mount({});

    assert.equal(component.hasDownloaderPoints, false);
    assert.equal(component.downloaderLoading, false);
    assert.match(component.downloaderPlaceholder, /\S/);
    assert.equal(chart(), undefined);
});
