import assert from 'node:assert/strict';
import { test } from 'node:test';
import { loadPerformanceModule, plain } from './support/performance-module.mjs';

const browser = {
    document: { querySelector: () => null },
    window: { location: { search: '', pathname: '/dashboard' }, history: { replaceState() {} } },
};

const list = await loadPerformanceModule('transaction-list.js');
const fixtures = await loadPerformanceModule('fixtures/index.js');
const { default: performanceTransactions, LIST_EVENT, transactionApi } = await loadPerformanceModule('transactions.js', {
    ...browser,
    fetch: async (url) => {
        fetched.push(url);

        return { ok: true, status: 200, json: async () => ({ data: [] }) };
    },
});

const fetched = [];
const flush = () => new Promise((resolve) => setImmediate(resolve));
const MAIN = 'platforms%5B%5D=jadiasn&preset=this_month';

/** Stand-in for the three endpoints; records every query and answers from the contract fixtures. */
function fakeApi({ list: listAnswer, detail: detailAnswer } = {}) {
    const calls = { list: [], options: [], detail: [] };

    return {
        calls,
        api: {
            list: async (query) => {
                calls.list.push(decodeURIComponent(query));
                if (listAnswer) return listAnswer(query);

                return plain(fixtures.transactionListResponse());
            },
            options: async (query) => {
                calls.options.push(decodeURIComponent(query));

                return plain(fixtures.transactionOptions);
            },
            detail: async (id) => {
                calls.detail.push(id);
                if (detailAnswer) return detailAnswer(id);

                return plain(fixtures.transactionDetails[id]);
            },
        },
    };
}

async function mount(options = {}) {
    const events = [];
    const { api, calls } = fakeApi(options);
    const component = performanceTransactions({ api });

    component.$dispatch = (name, detail) => events.push({ name, detail });
    component.applyFilter(options.query ?? MAIN);
    await flush();

    return { component, calls, events };
}

const lastList = (calls) => calls.list.at(-1);

test('the default endpoints read the documented paths', async () => {
    fetched.length = 0;
    await transactionApi.list('preset=today&statuses%5B%5D=pending');
    await transactionApi.options('preset=today');
    await transactionApi.detail(812);

    assert.deepEqual(fetched, [
        '/api/performance/transactions?preset=today&statuses%5B%5D=pending',
        '/api/performance/transactions/options?preset=today',
        '/api/performance/transactions/812',
    ]);
});

test('FIX-13 AC-01: nine columns in order, Tanggal Dibuat by default, page changes keep the filters', async () => {
    const { component, calls } = await mount({ list: (query) => plain(fixtures.transactionListResponse({ total: 60, page: new URLSearchParams(query).get('page') ? 2 : 1 })) });

    assert.equal(lastList(calls), 'platforms[]=jadiasn&preset=this_month', 'Tanggal Dibuat is the default and is not sent');
    assert.equal(component.dateBasisLabel, 'Tanggal Dibuat');
    assert.deepEqual(plain(Object.keys(component.rows[0])).slice(1, 10), [
        'trxId', 'createdAt', 'paidAt', 'platform', 'product', 'customer', 'paymentMethod', 'nominal', 'status',
    ]);
    assert.deepEqual(plain([component.rows[0].trxId, component.rows[0].createdAt, component.rows[0].paidAt, component.rows[0].nominal]), [
        'jadiasn-476770', '12 Nov 2026, 10.00 WIB', '12 Nov 2026, 10.05 WIB', 'Rp 183.000',
    ]);
    assert.equal(component.pageSummary, '1–25 dari 60 transaksi');

    component.toggleStatus('pending');
    await flush();
    component.goToPage(2);
    await flush();
    assert.equal(lastList(calls), 'platforms[]=jadiasn&preset=this_month&statuses[]=pending&page=2');
    assert.equal(component.page, 2);

    component.setDateBasis('paid');
    await flush();
    assert.equal(lastList(calls), 'platforms[]=jadiasn&preset=this_month&date_basis=paid&statuses[]=pending', 'a new date basis starts at page 1');
    assert.equal(calls.options.at(-1), 'platforms[]=jadiasn&preset=this_month&date_basis=paid', 'options follow the date basis');
});

test('FIX-13 AC-02: local filters combine, and Reset keeps the main period and platform', async () => {
    const { component, calls } = await mount();

    component.toggleStatus('pending');
    component.toggleMore('payment_methods', 'QRIS');
    component.local.search = ' rina ';
    component.applyLocal();
    await flush();
    assert.equal(lastList(calls), 'platforms[]=jadiasn&preset=this_month&search=rina&statuses[]=pending&payment_methods[]=QRIS');
    assert.equal(component.moreFilterCount, 1);
    assert.equal(component.hasLocalFilters, true);

    component.setDateBasis('paid');
    component.resetLocal();
    await flush();
    assert.equal(lastList(calls), 'platforms[]=jadiasn&preset=this_month');
    assert.deepEqual(plain(component.local), plain(list.defaultLocalFilter()));
    assert.equal(component.local.date_basis, 'created');
});

test('FIX-13 AC-03: a new main filter keeps the active local filters visible and applied', async () => {
    const { component, calls } = await mount();

    component.toggleMore('payment_methods', 'QRIS');
    await flush();
    component.applyFilter('platforms%5B%5D=jadiasn&preset=last_7_days');
    await flush();

    assert.equal(lastList(calls), 'platforms[]=jadiasn&preset=last_7_days&payment_methods[]=QRIS');
    assert.deepEqual(plain(component.local.payment_methods), ['QRIS']);
    assert.equal(calls.options.at(-1), 'platforms[]=jadiasn&preset=last_7_days');
});

test('FIX-13 AC-04 / AC-09: Tidak Berhasil selects Expired, Canceled and Failed; rows show the report status', async () => {
    const { component, calls } = await mount();

    component.toggleUnsuccessful();
    await flush();
    assert.equal(lastList(calls), 'platforms[]=jadiasn&preset=this_month&statuses[]=expired&statuses[]=canceled&statuses[]=failed');
    assert.equal(component.statusSummary, 'Tidak Berhasil');
    assert.equal(component.unsuccessfulSelected, true);

    component.toggleUnsuccessful();
    assert.deepEqual(plain(component.local.statuses), [], 'a second press clears the three');

    const expired = component.rows.find(({ id }) => id === 830);
    assert.deepEqual(plain([expired.status, expired.statusTone, expired.paidAt]), ['Expired', 'unsuccessful', 'Belum tersedia']);
    assert.equal(list.transactionRow({ status: { code: null, label: 'Status belum tersedia' } }).status, 'Status belum tersedia');
    assert.equal(list.STATUS_OPTIONS.find(({ value }) => value === 'expired').label, expired.status, 'filter and row use the same label');
});

test('FIX-13 AC-05: an ID opens the detail with product type, promo code and the contact that is available', async () => {
    const { component, calls } = await mount();

    component.openDetail(812);
    await flush();
    assert.equal(component.detailOpen, true);
    assert.deepEqual(calls.detail, [812]);

    const { info, customer } = component.detail;
    assert.equal(info.find(({ label }) => label === 'Tipe Produk').value, 'Perdana');
    assert.equal(info.find(({ label }) => label === 'Kode Promo').value, 'CPNS10');
    assert.deepEqual(plain(customer.map(({ value }) => value)), ['Rina', 'rina@example.test', '081200000001']);

    component.openDetail(820);
    await flush();
    assert.equal(component.detail.customer.find(({ label }) => label === 'Telepon').value, 'Tidak tersedia');

    component.openRelated(813);
    await flush();
    assert.deepEqual(plain(component.detail.customer.map(({ value }) => value)), ['Dewi', 'Tidak tersedia', 'Tidak tersedia'], 'without contact access');
});

test('FIX-13 AC-06: an empty search keeps the input, a failed detail keeps the list and its filters', async () => {
    const empty = await mount({ list: () => plain(fixtures.transactionListResponse({ rows: [], total: 0 })) });

    empty.component.local.search = 'tidak-ada';
    empty.component.applyLocal();
    await flush();
    assert.equal(empty.component.isEmpty, true);
    assert.equal(empty.component.local.search, 'tidak-ada');
    assert.equal(empty.component.hasLocalFilters, true, 'Reset Filter Daftar is offered');

    let fail = true;
    const { component, calls } = await mount({ detail: (id) => (fail ? Promise.reject({ status: 500, data: {} }) : plain(fixtures.transactionDetails[id])) });
    component.toggleStatus('success');
    await flush();
    const rowsBefore = plain(component.rows);
    const listCalls = calls.list.length;

    component.openDetail(812);
    await flush();
    assert.equal(component.detailError, 'Gagal memuat detail transaksi.');
    assert.equal(component.detailOpen, true);
    assert.deepEqual(plain(component.rows), rowsBefore);
    assert.deepEqual(plain(component.local.statuses), ['success']);
    assert.equal(calls.list.length, listCalls, 'the list is not reloaded');

    fail = false;
    component.retryDetail();
    await flush();
    assert.equal(component.detailError, '');
    assert.equal(component.detail.trxId, 'jadiasn-476770');

    component.closeDetail();
    assert.deepEqual([component.detailOpen, component.detail], [false, null]);
    assert.deepEqual(plain(component.local.statuses), ['success']);
});

test('FIX-13 AC-08: one row per group buyer; the detail shows members, the claim marker and the five amounts', async () => {
    const { component } = await mount();

    assert.equal(component.rows.length, 4);
    assert.deepEqual(plain(component.rows.filter(({ id }) => id === 813 || id === 814)), [], 'members are not list rows');
    assert.equal(component.rows.find(({ id }) => id === 812).groupMembers, 2);
    assert.equal(component.rows.find(({ id }) => id === 840).isClaim, true);

    component.openDetail(812);
    await flush();
    assert.equal(component.detail.group.label, 'Pembeli paket grup');
    assert.deepEqual(plain(component.detail.group.members.map(({ trxId, phone }) => [trxId, phone])), [
        ['jadiasn-476771', '081200000002'],
        ['jadiasn-476772', 'Tidak tersedia'],
    ]);

    component.openDetail(840);
    await flush();
    assert.equal(component.detail.isClaim, true);
    assert.equal(component.detail.financials.length, 5);

    component.openDetail(813);
    await flush();
    assert.equal(component.detail.group.buyer.trxId, 'jadiasn-476770');
});

test('FIX-13 AC-10: the five financial rows; Pending keeps Nominal and shows Kontribusi Omzet as —', async () => {
    const { component } = await mount();
    const amounts = () => plain(component.detail.financials.map(({ label, value }) => [label, value]));

    component.openDetail(812);
    await flush();
    assert.deepEqual(amounts(), [
        ['Harga Paket', 'Rp 200.000'],
        ['Potongan Voucher', 'Rp 20.000'],
        ['Biaya Admin', 'Rp 3.000'],
        ['Nominal', 'Rp 183.000'],
        ['Kontribusi Omzet', 'Rp 180.000'],
    ]);

    component.openDetail(820);
    await flush();
    assert.deepEqual(amounts().slice(3), [['Nominal', 'Rp 183.000'], ['Kontribusi Omzet', '—']]);
});

test('promo codes and Tanpa Promo exclude each other, so the backend never answers 422 for them', () => {
    let local = list.withPromoCodes(list.defaultLocalFilter(), ['CPNS10']);
    local = list.withPromoChoice(local, 'without');
    assert.deepEqual(plain([local.promo, local.promo_codes]), ['without', []]);

    local = list.withPromoCodes(local, ['RES1']);
    assert.deepEqual(plain([local.promo, local.promo_codes]), ['', ['RES1']]);
});

test('a late list response does not replace the latest filter, a 422 keeps the filters and shows the message', async () => {
    let release;
    const { component } = await mount({
        list: (query) => (query.includes('search=lama')
            ? new Promise((resolve) => { release = () => resolve(plain(fixtures.transactionListResponse({ rows: [] }))); })
            : plain(fixtures.transactionListResponse())),
    });

    component.local.search = 'lama';
    component.applyLocal();
    component.local.search = '';
    component.applyLocal();
    await flush();
    release();
    await flush();
    assert.equal(component.rows.length, 4);

    const invalid = await mount({ list: () => Promise.reject({ status: 422, data: { message: 'Data tidak valid.', errors: { search: ['Pencarian maksimal 100.'] } } }) });
    assert.deepEqual(plain(invalid.component.listErrors), { search: 'Pencarian maksimal 100.' });
    assert.equal(invalid.component.hasRows, false);
});

test('a selected value the new period no longer offers stays visible in Filter Lainnya', async () => {
    const { component } = await mount();

    component.toggleMore('products', 'Paket Lama');
    assert.deepEqual(plain(component.optionValues('products')), ['Bimbel SKD CPNS 2026 - Masa Aktif 1 Tahun', 'Paket B', 'Paket Lama']);
});

test('the list query without the page is sent for the export (FIX-14)', async () => {
    const { component, events } = await mount({ list: (query) => plain(fixtures.transactionListResponse({ total: 60, page: query.includes('page=2') ? 2 : 1 })) });

    component.toggleStatus('success');
    await flush();
    component.goToPage(2);
    await flush();
    assert.equal(component.page, 2);
    const sent = events.filter(({ name }) => name === LIST_EVENT).map(({ detail }) => decodeURIComponent(detail.query));
    assert.equal(sent.at(-1), 'platforms[]=jadiasn&preset=this_month&statuses[]=success');
});
