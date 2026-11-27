import assert from 'node:assert/strict';
import { test } from 'node:test';
import { loadPerformanceModule, plain } from './support/performance-module.mjs';

/** fetch() stand-in for GET /api/performance/purchase-times, answering `hourly` from the contract fixtures. */
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

const peak = await loadPerformanceModule('peak-hours.js');
const fixtures = await loadPerformanceModule('fixtures/index.js');
const { default: performancePurchaseTime, DAY_EVENT } = await loadPerformanceModule('purchase-time.js', browser);

const flush = () => new Promise((resolve) => setImmediate(resolve));

/** 7 Hari Terakhir answers with the requested day; Hari Ini has no day choice. */
function endpoint(url) {
    const day = url.searchParams.get('day_of_week');
    const hourly = url.searchParams.get('preset') === 'today'
        ? fixtures.todayPeakHours
        : fixtures.weekPeakHours(day ? Number(day) : null);

    return { body: { data: { hourly: plain(hourly) } } };
}

/** Chart.js stand-in: the x scale maps slot i to pixel 100 + 30·i inside an 820 px plot. */
function fakeChart(config) {
    return {
        config,
        width: 820,
        updates: 0,
        data: config.data,
        options: config.options,
        chartArea: { left: 100, right: 820, top: 10, bottom: 300 },
        scales: { x: { getPixelForValue: (index) => 100 + 30 * index, getValueForPixel: (x) => (x - 100) / 30 } },
        update() { this.updates += 1; },
        destroy() {},
    };
}

async function mount({ query = 'preset=last_7_days', api = endpoint } = {}) {
    const events = [];
    const charts = [];

    requests.length = 0;
    respond = api;

    const component = performancePurchaseTime({
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

const datasetLabels = (chart) => plain(chart.config.data.datasets.map(({ label }) => label));

test('reads GET /api/performance/purchase-times with the filter query and the chosen day', async () => {
    const { component } = await mount({ query: 'platforms%5B%5D=app-a&preset=last_7_days' });

    assert.deepEqual(requests, ['/api/performance/purchase-times?platforms%5B%5D=app-a&preset=last_7_days']);

    component.setDay('5');
    await flush();
    assert.equal(requests.at(-1), '/api/performance/purchase-times?platforms%5B%5D=app-a&preset=last_7_days&day_of_week=5');

    component.setDay('');
    await flush();
    assert.equal(requests.at(-1), '/api/performance/purchase-times?platforms%5B%5D=app-a&preset=last_7_days', 'Semua Hari sends no day');
});

test('FIX-12 AC-01: 24 WIB slots, bar height is Success payments, the detail shows hour, count and revenue', async () => {
    const { component, chart } = await mount();
    const { labels, datasets } = chart().config.data;

    assert.equal(labels.length, 24);
    assert.deepEqual([labels[0], labels[19], labels[23]], ['00.00', '19.00', '23.00']);
    assert.equal(datasets[0].data.length, 24);
    assert.equal(datasets[0].data[19], 1);
    assert.equal(datasets[0].data[18], 0);

    component.showSlot(19);
    assert.deepEqual(plain(component.detail), {
        time: '19.00–19.59 WIB',
        rows: [{
            key: 'active', label: 'Periode Aktif', color: '#3b5b8c', border: '#3b5b8c', pattern: 'solid', known: true,
            payments: '1 pembayaran', revenue: 'Rp 300.000', note: '',
        }],
    });
    assert.equal(component.peakHour, '19.00–19.59 WIB');
    assert.equal(chart().options.plugins.slotBands.index, 19, 'the open slot is highlighted');
});

test('FIX-12 AC-01: hover and tap pick the slot under the pointer, the keyboard moves through every slot', async () => {
    const { component, chart } = await mount();
    const { onClick, onHover } = chart().config.options;

    onClick({ type: 'touchstart', x: 100 + 30 * 20 + 4, y: 100 }, [], chart());
    assert.equal(component.slotIndex, 20);
    onHover({ type: 'mouseout' }, [], chart());
    assert.equal(component.slotIndex, null);

    component.firstSlot();
    component.moveSlot(-1);
    assert.equal(component.slotIndex, 0);
    component.lastSlot();
    component.moveSlot(1);
    assert.equal(component.slotIndex, 23);
});

test('FIX-12 AC-02: default is Semua Hari and the active period only; one day hides the day choice', async () => {
    const week = await mount();

    assert.equal(week.component.dayLabel, 'Semua Hari');
    assert.equal(week.component.dayOfWeek, '');
    assert.equal(week.component.daySelectable, true);
    assert.deepEqual(plain(week.component.dayOptions.map(({ label }) => label)), ['Semua Hari', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu']);
    assert.equal(week.component.showComparison, false);
    assert.deepEqual(datasetLabels(week.chart()), ['Periode Aktif']);

    const today = await mount({ query: 'preset=today' });
    assert.equal(today.component.daySelectable, false);
});

test('FIX-12 AC-03: the chosen day applies to both periods when the comparison is on', async () => {
    const { component, chart } = await mount();

    component.setDay('5');
    await flush();
    component.toggleComparison();

    assert.equal(component.dayLabel, 'Jumat');
    assert.deepEqual(datasetLabels(chart()), ['Periode Aktif', 'Pembanding']);
    assert.deepEqual(plain(chart().config.data.datasets.map(({ data }) => data[19])), [1, 1]);
    assert.equal(chart().config.data.datasets[1].borderWidth > 0, true, 'the comparison bar is outlined, not told apart by color alone');

    component.showSlot(19);
    assert.deepEqual(plain(component.detail.rows.map(({ label, payments, revenue }) => [label, payments, revenue])), [
        ['Periode Aktif', '1 pembayaran', 'Rp 300.000'],
        ['Pembanding', '1 pembayaran', 'Rp 200.000'],
    ]);

    component.setDay('1');
    await flush();
    assert.deepEqual(plain(chart().config.data.datasets.map(({ data }) => data[19])), [0, 0], 'Senin has no payment on either side');
    assert.equal(component.comparisonShown, true, 'the comparison choice survives a day change');
});

test('FIX-12 AC-04: hours outside the cutoff are Belum Tersedia, a complete hour without payments is 0', async () => {
    const { component, chart } = await mount({ query: 'preset=today' });
    const data = chart().config.data.datasets[0].data;

    assert.equal(data[11], 0);
    assert.equal(data[14], null, 'not covered yet is a gap, never 0');
    assert.equal(data[23], null);
    assert.deepEqual(plain(chart().options.plugins.slotBands.unavailable), [14, 15, 16, 17, 18, 19, 20, 21, 22, 23]);

    component.showSlot(11);
    assert.deepEqual(plain([component.detail.rows[0].payments, component.detail.rows[0].revenue]), ['0 pembayaran', 'Rp 0']);
    component.showSlot(14);
    assert.deepEqual(plain([component.detail.rows[0].payments, component.detail.rows[0].revenue]), ['Belum Tersedia', 'Belum Tersedia']);

    assert.equal(component.unavailableNote, 'Belum Tersedia: 14.00–23.59 WIB');
    assert.match(component.chartLabel, /Belum Tersedia: 14\.00–23\.59\./);
    assert.equal(plain(component.rows)[14].rows[0].known, false);
});

test('FIX-12 AC-05: the day shown is sent to the Dashboard export, and a new main filter goes back to Semua Hari', async () => {
    const { component, events } = await mount();

    component.setDay('5');
    await flush();
    assert.deepEqual(plain(events.filter(({ name }) => name === DAY_EVENT).map(({ detail }) => detail)), [{ day_of_week: null }, { day_of_week: 5 }]);

    component.applyFilter('preset=last_30_days');
    await flush();
    assert.equal(component.dayOfWeek, '');
    assert.equal(requests.at(-1), '/api/performance/purchase-times?preset=last_30_days');
    assert.deepEqual(plain(events.at(-1).detail), { day_of_week: null });
});

test('a comparison without the chosen day disables the switch and says why', async () => {
    const { component, chart } = await mount({ api: () => ({ body: { data: { hourly: plain(fixtures.noMatchingDayPeakHours(5)) } } }) });

    component.toggleComparison();
    assert.equal(component.comparisonAvailable, false);
    assert.equal(component.comparisonShown, false);
    assert.equal(component.comparisonNotice, 'Tidak ada hari yang sama di periode pembanding.');
    assert.deepEqual(datasetLabels(chart()), ['Periode Aktif']);
});

test('a failed request drops the previous result, and a late response is ignored', async () => {
    let release;
    const { component } = await mount();

    respond = () => ({ status: 500, body: { message: 'Server error' } });
    component.setDay('5');
    await flush();
    assert.equal(component.hourly, null);
    assert.equal(component.hasSlots, false);
    assert.ok(component.hourlyError);

    respond = () => new Promise((resolve) => { release = () => resolve(endpoint(new URL('http://localhost/?preset=last_7_days&day_of_week=2'))); });
    component.setDay('2');
    respond = endpoint;
    component.setDay('5');
    await flush();
    release();
    await flush();
    assert.equal(component.dayLabel, 'Jumat', 'the older Selasa response does not replace Jumat');
});

test('unavailableRanges joins neighbouring hours', () => {
    const slots = plain(fixtures.todayPeakHours.slots).map((slot) => ({ ...slot }));
    slots[2].active = { ...slots[2].active, availability: 'unavailable', success_payments: null };

    assert.equal(peak.unavailableRanges(slots), '02.00–02.59, 14.00–23.59');
    assert.equal(peak.unavailableRanges(plain(fixtures.weekPeakHours().slots)), '');
});
