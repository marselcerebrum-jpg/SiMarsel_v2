import assert from 'node:assert/strict';
import { test } from 'node:test';
import { loadPerformanceModule, plain } from './support/performance-module.mjs';

/** fetch() stand-in for GET /api/performance/summary, answering `data.sales_summary` from the contract fixtures. */
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

const { SALES_SUMMARY_METRICS, salesSummaryCards } = await loadPerformanceModule('sales-summary-cards.js');
const fixtures = await loadPerformanceModule('fixtures/index.js');
const { default: performanceSalesSummary } = await loadPerformanceModule('sales-summary.js', browser);

const flush = () => new Promise((resolve) => setImmediate(resolve));
const respondWith = (response) => () => Promise.resolve(plain(response));
const cards = (summary) => plain(salesSummaryCards(plain(summary)));

async function mount({ query = 'preset=today', resolve } = {}) {
    const component = performanceSalesSummary({ resolve });
    component.applySalesSummaryFilter(query);
    await flush();

    return component;
}

test('parent AC-01: the seven metrics follow the scope order, two per block then the supporting row', () => {
    assert.deepEqual(plain(SALES_SUMMARY_METRICS), [
        'revenue',
        'success_payments',
        'total_checkouts',
        'conversion',
        'average_order_value',
        'unique_buyers',
        'downloaders',
    ]);
});

test('FE AC-01: every active value is plain text, so it reads on a phone without a tooltip', async () => {
    const component = await mount({ resolve: respondWith(fixtures.todaySalesSummary) });
    const { salesCards } = component;

    assert.equal(component.salesSummaryLoading, false);
    assert.deepEqual(
        plain(SALES_SUMMARY_METRICS.map((key) => salesCards[key].value)),
        ['Rp 1.462.500', '9', '16', '60%', 'Rp 162.500', '7', '73'],
    );
});

test('FE AC-02: Konversi 50% → 60% shows +10 poin persentase under the active value', () => {
    const { conversion } = cards(fixtures.todaySalesSummary);

    assert.equal(conversion.value, '60%');
    assert.equal(conversion.comparison, '50%');
    assert.deepEqual(conversion.change, { text: '+10 poin persentase', symbol: '▲', label: 'Naik' });
});

test('parent AC-02: each comparison is read from the same date basis as its active value', () => {
    const result = cards(fixtures.crossDaySalesSummary);

    // paid basis: 8 payments vs 5; created basis: 10 checkouts vs 8; Downloader 120 vs 100.
    assert.equal(result.success_payments.comparison, '5');
    assert.deepEqual(result.success_payments.change, { text: '+60%', symbol: '▲', label: 'Naik' });
    assert.equal(result.total_checkouts.comparison, '8');
    assert.equal(result.total_checkouts.change.text, '+25%');
    assert.equal(result.revenue.comparison, 'Rp 900.000');
    assert.equal(result.average_order_value.change.text, '0%');
    assert.equal(result.average_order_value.change.label, 'Tidak berubah');
    assert.equal(result.downloaders.change.text, '+20%');
});

test('parent AC-03: 10 created, 6 Success and 8 payments show Total 10, Konversi 60% and Transaksi Berhasil 8', () => {
    const result = cards(fixtures.crossDaySalesSummary);

    assert.equal(result.total_checkouts.value, '10');
    assert.equal(result.conversion.value, '60%');
    assert.equal(result.success_payments.value, '8');
});

test('FE AC-03: a missing comparison keeps the active value and says so in the comparison place', () => {
    const result = cards(fixtures.missingRevenueComparisonSalesSummary);

    assert.equal(result.revenue.value, 'Rp 1.440.000');
    assert.equal(result.revenue.comparison, 'Pembanding belum tersedia');
    assert.deepEqual(result.revenue.change, { text: '', symbol: '', label: '' });
    assert.equal(result.success_payments.comparison, '5', 'the other cards keep their comparison');

    const missing = cards(fixtures.missingComparisonSalesSummary);
    for (const key of SALES_SUMMARY_METRICS) {
        assert.notEqual(missing[key].value, 'Belum tersedia', `${key} keeps its active value`);
        assert.equal(missing[key].comparison, 'Pembanding belum tersedia');
        assert.equal(missing[key].change.text, '');
    }
});

test('parent AC-04: a comparison of 0 is shown as 0 and its relative change cannot be computed', () => {
    const result = cards(fixtures.zeroComparisonSalesSummary);

    assert.equal(result.revenue.comparison, 'Rp 0');
    assert.equal(result.revenue.change.text, 'Belum dapat dihitung');
    assert.equal(result.downloaders.comparison, '0');
    assert.equal(result.downloaders.change.text, 'Belum dapat dihitung');
    assert.equal(result.conversion.comparison, 'Belum dapat dihitung', 'Konversi pembanding has a zero divisor');
    assert.equal(result.conversion.change.text, '');
    assert.equal(result.average_order_value.comparison, 'Belum dapat dihitung');
});

test('parent AC-04: a valid 0 stays apart from not available', () => {
    const empty = cards(fixtures.emptyCompleteSalesSummary);
    const unknown = cards(fixtures.incompleteSalesSummary);

    assert.equal(empty.total_checkouts.value, '0');
    assert.equal(empty.revenue.value, 'Rp 0');
    assert.equal(empty.downloaders.value, '0');
    assert.equal(empty.conversion.value, 'Belum dapat dihitung');

    for (const key of SALES_SUMMARY_METRICS) {
        assert.equal(unknown[key].value, 'Belum tersedia', key);
        assert.equal(unknown[key].comparison, 'Pembanding belum tersedia', key);
    }
});

test('FE AC-04: Total 3 shows 2 purchase attempts and 1 claim; Konversi 50% shows 1 of 2 attempts', () => {
    const result = cards(fixtures.claimSalesSummary);

    assert.equal(result.total_checkouts.value, '3');
    assert.equal(result.total_checkouts.detail, '2 percobaan pembelian · 1 klaim');
    assert.equal(result.conversion.value, '50%');
    assert.equal(result.conversion.detail, '1 dari 2 percobaan pembelian');
    assert.equal(result.conversion.note, '');
});

test('parent AC-07: only a claim gives Total 1 and a Konversi that cannot be computed', () => {
    const result = cards(fixtures.onlyClaimSalesSummary);

    assert.equal(result.total_checkouts.value, '1');
    assert.equal(result.total_checkouts.detail, '0 percobaan pembelian · 1 klaim');
    assert.equal(result.conversion.value, 'Belum dapat dihitung');
    assert.equal(result.conversion.detail, '0 dari 0 percobaan pembelian');
});

test('FE AC-05: Omzet, AOV and Pembeli Unik show the backend values without recomputing them', async () => {
    const response = plain(fixtures.revenueSalesSummary);
    const metric = (summary, key) => summary.metrics.find((item) => item.key === key);

    const component = await mount({ resolve: respondWith(response) });

    assert.equal(component.salesCards.revenue.value, 'Rp 360.000');
    assert.equal(component.salesCards.average_order_value.value, 'Rp 180.000');
    assert.equal(component.salesCards.unique_buyers.value, '1');

    // A changed response is shown as is: AOV is not divided again and the change is not recomputed.
    const changed = plain(response);
    metric(changed, 'revenue').active.value = 540000;
    component.salesSummary = changed;

    assert.equal(component.salesCards.revenue.value, 'Rp 540.000');
    assert.equal(component.salesCards.average_order_value.value, 'Rp 180.000');
    assert.equal(component.salesCards.revenue.change.text, '', 'without a comparison the change stays empty');
});

test('FE AC-06: an empty status keeps Total 10 and marks Konversi 60% as Sementara until it is filled', () => {
    const provisional = cards(fixtures.provisionalSalesSummary);

    assert.equal(provisional.total_checkouts.value, '10');
    assert.equal(provisional.conversion.value, '60%');
    assert.equal(provisional.conversion.note, 'Sementara · 1 status belum tersedia');

    const resolved = cards(fixtures.resolvedSalesSummary);

    assert.equal(resolved.total_checkouts.value, '10');
    assert.equal(resolved.conversion.value, '70%');
    assert.equal(resolved.conversion.note, '');
});

test('parent AC-05: the cards read data.sales_summary of the summary endpoint, and Coba Lagi repeats the same filter', async () => {
    const query = 'platforms%5B%5D=jadiasn&preset=last_7_days';
    let fail = true;
    requests.length = 0;
    respond = () => (fail
        ? { status: 500, body: { message: 'SQLSTATE[HY000]' } }
        : { body: { data: { kpis: {}, sales_summary: plain(fixtures.crossDaySalesSummary) } } });

    const component = await mount({ query });

    assert.equal(component.salesSummaryError, 'Gagal memuat Ringkasan Sales.', 'no technical detail is shown');
    assert.equal(component.salesSummary, null);
    assert.equal(component.salesCards.revenue.value, 'Belum tersedia');

    fail = false;
    component.retrySalesSummary();
    assert.equal(component.salesSummaryLoading, true);
    await flush();

    assert.deepEqual(plain(requests), [`/api/performance/summary?${query}`, `/api/performance/summary?${query}`]);
    assert.equal(component.salesSummaryError, '');
    assert.equal(component.salesCards.revenue.value, 'Rp 1.440.000');
});
