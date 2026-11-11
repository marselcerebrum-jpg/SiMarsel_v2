import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';
import { cloneFilter, defaultFilter, periodLabel, withMode } from '../../resources/js/pages/promo/analysis-filter.js';

const NOW = new Date(2026, 10, 3);
const source = (path) => readFile(new URL(`../../resources/js/pages/promo/${path}`, import.meta.url), 'utf8');

function syntheticModule(exports, context) {
    return new vm.SyntheticModule(Object.keys(exports), function () {
        for (const [key, value] of Object.entries(exports)) this.setExport(key, value);
    }, { context });
}

async function analysisFixture() {
    const context = vm.createContext({ Date, URLSearchParams, Object, String, Number, Boolean });
    const filterModule = new vm.SourceTextModule(await source('analysis-filter.js'), { context });
    const module = new vm.SourceTextModule(await source('analysis.js'), { context });
    await module.link((specifier) => {
        if (specifier === './analysis-filter') return filterModule;
        if (specifier === '../../http') return syntheticModule({ errorMessage: (error, fallback) => fallback }, context);
        return syntheticModule({
            fetchAnalysisFilters: async () => ({ data: { platforms: [{ slug: 'jadiasn', name: 'JadiASN' }], payment_methods: ['BCA'] } }),
        }, context);
    });
    await module.evaluate();

    const events = [];
    const analysis = module.namespace.default();
    analysis.$nextTick = (callback) => callback();
    analysis.$dispatch = (name, detail) => events.push({ name, detail });
    analysis.init();
    await new Promise((resolve) => setImmediate(resolve));

    return { analysis, filterEvents: () => events.filter(({ name }) => name === 'promo-analysis-filter') };
}

test('period label names the month, the running month, or the date range', () => {
    assert.equal(periodLabel({ ...defaultFilter(NOW), month: '9' }, NOW), 'September 2026');
    assert.equal(periodLabel(defaultFilter(NOW), NOW), 'November 2026 (sampai hari ini)');
    assert.equal(periodLabel({ ...withMode(defaultFilter(NOW), 'date_range', NOW), date_to: '2026-11-03' }, NOW), '1 November 2026 – 3 November 2026');
});

test('a cloned filter does not follow later edits', () => {
    const filter = defaultFilter(NOW);
    const copy = cloneFilter(filter);

    filter.platforms.push('jadiasn');
    filter.search = 'CPNS';

    assert.deepEqual(copy.platforms, []);
    assert.equal(copy.search, '');
});

test('the analysis loads once on open and only reloads on Terapkan filter', async () => {
    const { analysis, filterEvents } = await analysisFixture();
    assert.equal(filterEvents().length, 1);

    analysis.toggle('platforms', 'jadiasn');
    analysis.toggle('payment_methods', 'BCA');
    analysis.filter.search = 'CPNS';
    analysis.filter.mode = 'date_range';
    analysis.changeMode();

    assert.equal(filterEvents().length, 1);
    assert.equal(analysis.activeFilters.find(({ label }) => label === 'Platform').value, 'Semua Platform');

    analysis.apply();

    assert.equal(filterEvents().length, 2);
    assert.match(filterEvents()[1].detail, /platforms%5B%5D=jadiasn/);
    assert.deepEqual(
        [...analysis.activeFilters].map(({ label, value }) => [label, value]).slice(1),
        [['Platform', 'JadiASN'], ['Metode pembayaran', 'BCA'], ['Kode promo', 'CPNS']],
    );
});

test('Atur ulang restores the default filter and reloads right away', async () => {
    const { analysis, filterEvents } = await analysisFixture();

    analysis.toggle('platforms', 'jadiasn');
    analysis.apply();
    analysis.resetFilter();

    assert.equal(filterEvents().length, 3);
    assert.equal(filterEvents()[2].detail, filterEvents()[0].detail);
    assert.equal(analysis.filter.platforms.length, 0);
});

test('an invalid filter is not sent and opens the advanced filters when the error is there', async () => {
    const { analysis, filterEvents } = await analysisFixture();

    analysis.filter.search = 'X'.repeat(101);
    analysis.apply();

    assert.equal(filterEvents().length, 1);
    assert.ok(analysis.errors.search);
    assert.equal(analysis.advancedOpen, true);
});

async function tabsFixture(search) {
    const replaced = [];
    const context = vm.createContext({
        URL,
        URLSearchParams,
        window: {
            location: { search, href: `http://localhost/promo${search}` },
            history: { replaceState: (state, title, url) => replaced.push(String(url)) },
        },
        document: { getElementById: () => null },
    });
    const module = new vm.SourceTextModule(await source('tabs.js'), { context });
    await module.link(() => {});
    await module.evaluate();

    return { promoTabs: module.namespace.default, replaced };
}

test('main tabs open Analisis by default and keep the selected tab in the URL', async () => {
    const { promoTabs, replaced } = await tabsFixture('');
    const tabs = promoTabs('promo', ['analysis', 'promo-codes', 'master-data'], 'tab');

    assert.equal(tabs.tab, 'analysis');

    tabs.select('master-data');
    assert.equal(tabs.tab, 'master-data');
    assert.match(replaced.at(-1), /tab=master-data/);

    tabs.select('unknown');
    assert.equal(tabs.tab, 'master-data');
});

test('the old Kode Promo link still opens Kelola Kode, and subtabs leave the URL alone', async () => {
    const { promoTabs, replaced } = await tabsFixture('?tab=promo-codes&code=CPNS26');

    assert.equal(promoTabs('promo', ['analysis', 'promo-codes', 'master-data'], 'tab').tab, 'promo-codes');

    const subtabs = promoTabs('promo-master', ['funnel', 'source', 'platform']);
    subtabs.$nextTick = (callback) => callback();
    subtabs.move(-1);

    assert.equal(subtabs.tab, 'platform');
    assert.equal(replaced.length, 0);
});
