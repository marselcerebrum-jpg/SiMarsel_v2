import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { loadPerformanceModule, plain } from './support/performance-module.mjs';

const browser = {
    document: { querySelector: () => null },
    window: { location: { search: '', pathname: '/dashboard' }, history: { replaceState() {} } },
};

const filters = await loadPerformanceModule('filter.js');
const period = await loadPerformanceModule('period.js');
const resolved = await loadPerformanceModule('fixtures/resolved-periods.js');
const { default: dashboardFilter, PERIOD_PENDING, STATUS } = await loadPerformanceModule('global-filter.js', browser);

const PLATFORMS = [
    { value: 'cerebrum', label: 'Cerebrum' },
    { value: 'jadiasn', label: 'JadiASN' },
    { value: 'jadibumn', label: 'JadiBUMN' },
];

const MESSAGE = 'Tanggal akhir tidak boleh sebelum tanggal mulai.';

/** `data.filter.period` the backend returns for a query (fixtures generated from PeriodResolverService). */
function periodFor(query) {
    const params = new URLSearchParams(query);
    const comparison = params.get('comparison') ?? 'auto';
    const manual = comparison === 'auto' ? null : resolved.resolvedManualComparisonPeriods[comparison];

    return plain(manual ?? resolved.resolvedPresetPeriods[params.get('preset')]);
}

/**
 * Mounts the filter. Each `performance-filter` event stands for the summary request; `respond()` plays the
 * summary response handing its period back, as performanceSummary does through `performance-period`.
 */
function mount({ search = '' } = {}) {
    const events = [];
    const urls = [];
    const filter = dashboardFilter(PLATFORMS, { search, writeUrl: (query) => urls.push(query) });

    filter.$nextTick = (callback) => callback();
    filter.$dispatch = (name, detail) => events.push({ name, detail });

    const respond = (query = events.at(-1).detail) => filter.receivePeriod({ query, period: periodFor(query) });

    filter.init();
    respond();

    return { filter, events, urls, respond };
}

test('AC-01: without URL parameters the Dashboard opens on Hari Ini, Otomatis and every platform', () => {
    assert.deepEqual(plain(filters.filterFromUrl('')), plain(filters.defaultFilter()));

    const { filter, events, urls } = mount();

    assert.deepEqual([filter.filter.preset, filter.filter.comparison, filter.filter.platforms.length], ['today', 'auto', 0]);
    assert.equal(filter.platformSummary, 'Semua Platform');
    assert.deepEqual(plain(events), [{ name: 'performance-filter', detail: 'preset=today' }]);
    assert.deepEqual(urls, ['preset=today']);
    assert.equal(filter.periodInfo.active, '11 Nov 2026, 00.00–14.00 WIB');
    assert.equal(filter.periodInfo.comparison, '10 Nov 2026, 00.00–14.00 WIB');
    assert.equal(filter.periodInfo.completeUntil, '11 Nov 2026, 14.00 WIB');
    assert.equal(filter.activeRangeLabel, filter.periodInfo.active);
});

test('older URLs keep their platforms and date range; the removed year modes open the default period', () => {
    const read = (search) => plain(filters.filterFromUrl(search));

    assert.deepEqual(read('platforms[]=cerebrum&platforms[]=jadiasn').platforms, ['cerebrum', 'jadiasn']);
    assert.deepEqual(read('platform=cerebrum').platforms, ['cerebrum']);

    const range = read('date_from=2026-11-05&date_to=2026-11-11');
    assert.deepEqual([range.preset, range.date_from, range.date_to], ['date_range', '2026-11-05', '2026-11-11']);

    for (const search of ['year=2026&month=9', 'years[]=2025&years[]=2026', 'year_from=2024&year_to=2026', 'preset=unknown&comparison=unknown']) {
        const filter = read(search);
        assert.deepEqual([filter.preset, filter.comparison], ['today', 'auto'], search);
    }

    const custom = read('preset=last_7_days&comparison=custom&comparison_from=2026-10-01&comparison_to=2026-10-10');
    assert.deepEqual(
        [custom.preset, custom.comparison, custom.comparison_from, custom.comparison_to],
        ['last_7_days', 'custom', '2026-10-01', '2026-10-10'],
    );
});

test('the query follows the backend contract: preset is always sent, Otomatis is left out', () => {
    const filter = {
        ...plain(filters.defaultFilter()),
        platforms: ['cerebrum'],
        preset: 'date_range',
        date_from: '2026-11-05',
        date_to: '2026-11-11',
        comparison: 'custom',
        comparison_from: '2026-10-01',
        comparison_to: '2026-10-10',
    };
    const query = filters.toQueryString(filter);

    assert.equal(decodeURIComponent(query), 'platforms[]=cerebrum&preset=date_range&date_from=2026-11-05&date_to=2026-11-11&comparison=custom&comparison_from=2026-10-01&comparison_to=2026-10-10');
    assert.deepEqual(plain(filters.filterFromUrl(query)), filter);
    assert.equal(filters.toQueryString(plain(filters.defaultFilter())), 'preset=today');
    assert.equal(filters.toQueryString({ ...plain(filters.defaultFilter()), comparison: 'previous_period' }), 'preset=today&comparison=previous_period');
});

test('nine presets and four manual comparisons are offered with the backend values, Otomatis first', () => {
    assert.deepEqual(plain(filters.PRESETS).map(({ label }) => label), [
        'Hari Ini', 'Kemarin', '7 Hari Terakhir', '30 Hari Terakhir', '90 Hari Terakhir', 'Bulan Ini', 'Bulan Lalu', 'Rentang Tanggal', 'Tahun Lalu',
    ]);
    assert.deepEqual(plain(filters.PRESETS).map(({ value }) => value), Object.keys(resolved.resolvedPresetPeriods));
    assert.deepEqual(plain(filters.COMPARISONS).map(({ label }) => label), [
        'Otomatis', 'Periode Sebelumnya', 'Periode Sama Bulan Lalu', 'Periode Sama Tahun Lalu', 'Rentang Pembanding Khusus',
    ]);
    assert.deepEqual(plain(filters.COMPARISONS).slice(1).map(({ value }) => value), Object.keys(resolved.resolvedManualComparisonPeriods));
});

test('AC-02: every preset shows the resolver ranges of the business rules (11 Nov 2026, 14.00 WIB)', () => {
    const expected = {
        today: ['11 Nov 2026, 00.00–14.00 WIB', '10 Nov 2026, 00.00–14.00 WIB', '10 Nov 2026, 14.00 WIB (jam setara)', true, '11 Nov 2026, 14.00 WIB'],
        yesterday: ['10 Nov 2026, 00.00–24.00 WIB', '9 Nov 2026, 00.00–24.00 WIB', '10 Nov 2026, 00.00 WIB (keadaan terbaru)', false, '11 Nov 2026, 00.00 WIB'],
        last_7_days: ['4 Nov 2026, 00.00 – 10 Nov 2026, 24.00 WIB', '28 Okt 2026, 00.00 – 3 Nov 2026, 24.00 WIB', '4 Nov 2026, 00.00 WIB (keadaan terbaru)', false, '11 Nov 2026, 00.00 WIB'],
        last_30_days: ['12 Okt 2026, 00.00 – 10 Nov 2026, 24.00 WIB', '12 Sep 2026, 00.00 – 11 Okt 2026, 24.00 WIB', '12 Okt 2026, 00.00 WIB (keadaan terbaru)', false, '11 Nov 2026, 00.00 WIB'],
        last_90_days: ['13 Agu 2026, 00.00 – 10 Nov 2026, 24.00 WIB', '15 Mei 2026, 00.00 – 12 Agu 2026, 24.00 WIB', '13 Agu 2026, 00.00 WIB (keadaan terbaru)', false, '11 Nov 2026, 00.00 WIB'],
        this_month: ['1 Nov 2026, 00.00 – 11 Nov 2026, 14.00 WIB', '1 Okt 2026, 00.00 – 11 Okt 2026, 14.00 WIB', '11 Okt 2026, 14.00 WIB (jam setara)', true, '11 Nov 2026, 14.00 WIB'],
        last_month: ['1 Okt 2026, 00.00 – 31 Okt 2026, 24.00 WIB', '1 Sep 2026, 00.00 – 30 Sep 2026, 24.00 WIB', '1 Okt 2026, 00.00 WIB (keadaan terbaru)', false, '1 Nov 2026, 00.00 WIB'],
        date_range: ['5 Nov 2026, 00.00 – 11 Nov 2026, 14.00 WIB', '29 Okt 2026, 00.00 – 4 Nov 2026, 14.00 WIB', '4 Nov 2026, 14.00 WIB (jam setara)', true, '11 Nov 2026, 14.00 WIB'],
        last_year: ['1 Jan 2026, 00.00 – 11 Nov 2026, 14.00 WIB', '1 Jan 2025, 00.00 – 11 Nov 2025, 14.00 WIB', '11 Nov 2025, 14.00 WIB (jam setara)', true, '11 Nov 2026, 14.00 WIB'],
    };

    for (const [preset, [active, comparison, record, provisional, completeUntil]] of Object.entries(expected)) {
        const info = plain(period.describePeriod(resolved.resolvedPresetPeriods[preset]));

        assert.deepEqual(
            [info.active, info.comparison, info.snapshot, info.provisional, info.provisionalLabel, info.completeUntil],
            [active, comparison, record, provisional, provisional ? 'Sementara' : '', completeUntil],
            preset,
        );
        assert.equal(info.change, null, preset);
    }
});

test('AC-02: missing equivalent dates keep the active total and show the range the change uses', () => {
    const info = plain(period.describePeriod(resolved.resolvedMissingEquivalentDates));

    assert.equal(info.active, '1 Mar 2026, 00.00 – 31 Mar 2026, 14.00 WIB');
    assert.equal(info.comparison, '1 Feb 2026, 00.00 – 28 Feb 2026, 24.00 WIB');
    assert.deepEqual(info.change, {
        active: '1 Mar 2026, 00.00 – 28 Mar 2026, 24.00 WIB',
        comparison: '1 Feb 2026, 00.00 – 28 Feb 2026, 24.00 WIB',
    });
});

test('AC-03: each manual comparison changes only the comparison period', () => {
    const base = { ...plain(filters.defaultFilter()), preset: 'date_range', date_from: '2026-11-05', date_to: '2026-11-11' };
    const withoutComparison = (query) => {
        const params = new URLSearchParams(query);
        ['comparison', 'comparison_from', 'comparison_to'].forEach((key) => params.delete(key));

        return params.toString();
    };

    for (const [mode, fixture] of Object.entries(resolved.resolvedManualComparisonPeriods)) {
        const query = filters.toQueryString({ ...base, comparison: mode, comparison_from: '2026-10-01', comparison_to: '2026-10-10' });
        assert.equal(withoutComparison(query), filters.toQueryString(base), mode);
        assert.equal(period.describePeriod(fixture).active, '5 Nov 2026, 00.00 – 11 Nov 2026, 14.00 WIB', mode);
    }

    const { filter, respond } = mount({ search: 'preset=date_range&date_from=2026-11-05&date_to=2026-11-11' });
    for (const mode of ['previous_period', 'same_period_last_month', 'same_period_last_year', 'custom']) {
        filter.filter.comparison = mode;
        filter.changeComparison();
        if (mode === 'custom') {
            Object.assign(filter.filter, { comparison_from: '2026-10-01', comparison_to: '2026-10-10' });
            filter.applyGlobalFilter();
        }
        respond();

        assert.equal(filter.period.comparison_mode, mode);
        assert.deepEqual([filter.filter.preset, filter.filter.date_from, filter.filter.date_to], ['date_range', '2026-11-05', '2026-11-11'], mode);
        assert.equal(filter.periodInfo.active, '5 Nov 2026, 00.00 – 11 Nov 2026, 14.00 WIB', mode);
    }
});

test('AC-03: a custom comparison of another length shows both durations', () => {
    const custom = plain(period.describePeriod(resolved.resolvedManualComparisonPeriods.custom));
    assert.deepEqual(custom.durations, { active: '7 hari', comparison: '10 hari' });
    assert.equal(custom.comparison, '1 Okt 2026, 00.00 – 10 Okt 2026, 24.00 WIB');

    for (const mode of ['previous_period', 'same_period_last_month', 'same_period_last_year']) {
        assert.equal(period.describePeriod(resolved.resolvedManualComparisonPeriods[mode]).durations, null, mode);
    }
    // Bulan Lalu compares months of different lengths, but they are not custom ranges.
    assert.equal(resolved.resolvedPresetPeriods.last_month.durations_differ, true);
    assert.equal(period.describePeriod(resolved.resolvedPresetPeriods.last_month).durations, null);
});

test('AC-04 (FE AC-02): an end before the start is rejected on the end field, both values stay, and it applies once fixed', () => {
    const { filter, events } = mount({ search: 'preset=date_range&date_from=2026-11-05&date_to=2026-11-11' });
    const sent = events.length;

    filter.filter.date_from = '2026-11-11';
    filter.filter.date_to = '2026-11-05';
    filter.applyGlobalFilter();

    assert.deepEqual(plain(filter.filterErrors), { date_to: MESSAGE });
    assert.deepEqual([filter.filter.date_from, filter.filter.date_to], ['2026-11-11', '2026-11-05']);
    assert.equal(events.length, sent, 'nothing is sent');
    assert.deepEqual([filter.filterStatus, filter.filterFailed], [STATUS.invalid, true]);

    filter.filter.date_to = '2026-11-11';
    filter.applyGlobalFilter();

    assert.deepEqual(plain(filter.filterErrors), {});
    assert.equal(events.length, sent + 1);
    assert.equal(decodeURIComponent(events.at(-1).detail), 'preset=date_range&date_from=2026-11-11&date_to=2026-11-11');
});

test('AC-04: the custom comparison range is checked the same way on its own end field', () => {
    const errors = plain(filters.validateFilter({
        ...plain(filters.defaultFilter()),
        comparison: 'custom',
        comparison_from: '2026-10-10',
        comparison_to: '2026-10-01',
    }));

    assert.deepEqual(errors, { comparison_to: MESSAGE });
});

const SEVEN_DAYS = {
    active: '4 Nov 2026, 00.00 – 10 Nov 2026, 24.00 WIB',
    comparison: '28 Okt 2026, 00.00 – 3 Nov 2026, 24.00 WIB',
    record: '4 Nov 2026, 00.00 WIB (keadaan terbaru)',
};

/** Mounts the filter with 7 Hari Terakhir applied and its response received. */
function mountSevenDays() {
    const mounted = mount();

    mounted.filter.filter.preset = 'last_7_days';
    mounted.filter.changePreset();
    mounted.respond();
    assert.equal(mounted.filter.periodInfo.active, SEVEN_DAYS.active);

    return mounted;
}

const shows = (info, text) => JSON.stringify(info).includes(text);

test('the previous period is not shown while the response for the new filter is pending', () => {
    const { filter, respond } = mountSevenDays();

    filter.filter.preset = 'last_30_days';
    filter.changePreset();

    const pending = plain(filter.periodInfo);
    assert.deepEqual(
        [pending.active, pending.comparison, pending.completeUntil, filter.activeRangeLabel],
        [PERIOD_PENDING, PERIOD_PENDING, PERIOD_PENDING, PERIOD_PENDING],
    );
    for (const text of Object.values(SEVEN_DAYS)) assert.equal(shows(pending, text), false, text);
    assert.deepEqual([pending.provisional, pending.change, pending.durations], [false, null, null]);

    respond('preset=last_7_days');
    assert.equal(filter.periodInfo.active, PERIOD_PENDING, 'the late 7-day response does not bring it back');

    respond('preset=last_30_days');
    assert.equal(filter.periodInfo.active, '12 Okt 2026, 00.00 – 10 Nov 2026, 24.00 WIB');
    assert.equal(filter.periodInfo.comparison, '12 Sep 2026, 00.00 – 11 Okt 2026, 24.00 WIB');
    assert.equal(filter.periodInfo.completeUntil, '11 Nov 2026, 00.00 WIB');
});

test('a failed request for the new filter shows the failure instead of the previous period', () => {
    const { filter } = mountSevenDays();

    filter.filter.preset = 'last_30_days';
    filter.changePreset();
    filter.receivePeriod({ query: filter.filterQuery, error: { status: 500, data: {} } });

    const failed = plain(filter.periodInfo);
    assert.deepEqual(
        [failed.active, failed.comparison, failed.completeUntil],
        [STATUS.periodError, STATUS.periodError, STATUS.periodError],
    );
    for (const text of Object.values(SEVEN_DAYS)) assert.equal(shows(failed, text), false, text);
    assert.deepEqual([filter.filterStatus, filter.filterFailed], [`${STATUS.failed} ${STATUS.periodError}`, true]);
});

test('AC-05 (FE AC-03): a 7-day response arriving after the 30-day one is ignored', () => {
    const { filter, events, respond } = mount();

    filter.filter.preset = 'last_7_days';
    filter.changePreset();
    filter.filter.preset = 'last_30_days';
    filter.changePreset();
    assert.equal(filter.periodLoading, true);

    respond('preset=last_30_days');
    respond('preset=last_7_days');

    assert.equal(filter.period.preset, 'last_30_days');
    assert.equal(filter.periodInfo.active, '12 Okt 2026, 00.00 – 10 Nov 2026, 24.00 WIB');
    assert.match(filter.filterStatus, /30 Hari Terakhir/);
    assert.equal(filter.periodLoading, false);
    assert.equal(events.at(-1).detail, 'preset=last_30_days');

    filter.receivePeriod({ query: 'preset=last_7_days', error: { status: 500, data: {} } });
    assert.deepEqual([filter.period.preset, filter.filterFailed], ['last_30_days', false], 'a late error is ignored too');
});

test('FE AC-01 / AC-06: a global change reaches every section and leaves local comparison visibility alone', () => {
    const { filter, events } = mount();

    // Sections keep their own state; the global filter only sends the query.
    const sections = ['trend', 'contribution', 'payment-method', 'purchase-time'].map((name) => ({
        name,
        comparisonVisible: name !== 'purchase-time',
        queries: [],
        reload(query) { this.queries.push(query); },
    }));
    const deliver = () => events.splice(0).forEach(({ detail }) => sections.forEach((section) => section.reload(detail)));

    events.splice(0);
    filter.togglePlatform('cerebrum');
    deliver();

    for (const section of sections) assert.deepEqual(section.queries.map(decodeURIComponent), ['platforms[]=cerebrum&preset=today'], section.name);
    assert.equal(sections.find(({ name }) => name === 'purchase-time').comparisonVisible, false);
    assert.equal(filter.platformSummary, 'Cerebrum');

    const ownKeys = Object.keys(filter);
    assert.equal(ownKeys.some((key) => /visib|series|transaction/i.test(key)), false, 'no section state lives in the global filter');
});

test('platforms can be one, several, or all again', () => {
    const { filter, events } = mount();

    filter.togglePlatform('cerebrum');
    filter.togglePlatform('jadiasn');
    assert.equal(filter.platformSummary, 'Cerebrum, JadiASN');
    filter.togglePlatform('jadibumn');
    assert.equal(filter.platformSummary, '3 platform');

    filter.selectAllPlatforms();
    assert.deepEqual([filter.allPlatformsSelected, filter.platformSummary, events.at(-1).detail], [true, 'Semua Platform', 'preset=today']);
});

test('FE AC-04 / AC-07: the comparison note shows the record date and hour, or Belum tersedia', () => {
    const { filter, respond } = mount();
    assert.equal(filter.periodInfo.snapshot, '10 Nov 2026, 14.00 WIB (jam setara)');

    filter.filter.preset = 'yesterday';
    filter.changePreset();
    respond();
    assert.equal(filter.periodInfo.snapshot, '10 Nov 2026, 00.00 WIB (keadaan terbaru)');

    filter.receivePeriod({ query: filter.filterQuery, period: plain(resolved.resolvedMissingComparisonRecord) });
    assert.equal(filter.periodInfo.snapshot, 'Belum tersedia');
    assert.equal(filter.periodInfo.comparison, '10 Nov 2026, 00.00–14.00 WIB');
});

test('FE AC-04: period labels come from the response, never from the browser clock', async () => {
    const source = await readFile(new URL('../../resources/js/pages/performance/period.js', import.meta.url), 'utf8');

    assert.doesNotMatch(source, /Date\.now|new Date\(\)/);
});

test('a failed or rejected request is told in text', () => {
    const failed = mount();
    failed.filter.receivePeriod({ query: failed.filter.filterQuery, error: { status: 500, data: {} } });
    assert.deepEqual([failed.filter.filterStatus, failed.filter.filterFailed, failed.filter.period], [`${STATUS.failed} ${STATUS.periodError}`, true, null]);

    const rejected = mount({ search: 'preset=date_range&date_from=2026-11-05&date_to=2026-11-11' });
    rejected.filter.receivePeriod({
        query: rejected.filter.filterQuery,
        error: { status: 422, data: { errors: { date_to: ['Tanggal akhir tidak boleh sebelum tanggal mulai.'], 'platforms.0': ['Platform tidak ditemukan.'] } } },
    });
    assert.deepEqual(plain(rejected.filter.filterErrors), { date_to: MESSAGE, platforms: 'Platform tidak ditemukan.' });
    assert.equal(rejected.filter.filterStatus, STATUS.invalid);

    assert.equal(mount().filter.filterStatus, 'Filter diterapkan: Hari Ini, pembanding Otomatis, Semua Platform.');
});
