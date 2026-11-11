import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

async function alertFixture(totals) {
    const requests = [];
    const context = vm.createContext({ Intl, Date });
    const mocks = {
        '../../http': { errorMessage: () => 'Request failed' },
        './api': {
            fetchUnregisteredCodes: async (query) => {
                requests.push(query);
                const total = totals.shift();
                if (total instanceof Error) throw total;
                return { data: Array.from({ length: total }, (_, i) => ({ code: `KODE${i}` })), meta: { total, last_page: 1 } };
            },
        },
        './filter': {
            defaultFilter: () => ({ search: '', platform_id: '', page: 1 }),
            toQueryString: (filter) => filter.search,
        },
    };
    const code = await readFile(new URL('../../resources/js/pages/promo/unregistered.js', import.meta.url), 'utf8');
    const module = new vm.SourceTextModule(code, { context });
    await module.link((specifier) => {
        const mock = mocks[specifier];
        return new vm.SyntheticModule(Object.keys(mock), function () {
            for (const [key, value] of Object.entries(mock)) this.setExport(key, value);
        }, { context });
    });
    await module.evaluate();

    return { alert: module.namespace.default(), requests };
}

async function search(alert, value) {
    alert.filter.search = value;
    alert.filter.page = 1;
    await alert.fetchItems();
}

test('without a search the badge shows the unregistered count', async () => {
    const { alert } = await alertFixture([5]);

    await alert.fetchItems();

    assert.equal(alert.status.text, '5 Belum Terdaftar');
});

test('All Registered only shows without a search and with no unregistered codes', async () => {
    const { alert } = await alertFixture([0]);

    await alert.fetchItems();

    assert.equal(alert.status.text, 'Semua Terdaftar');
});

test('a search without matches shows 0 Results, not All Registered', async () => {
    const { alert } = await alertFixture([5, 0]);

    await alert.fetchItems();
    await search(alert, 'TIDAKADA');

    assert.equal(alert.status.text, '0 Hasil');
    assert.notEqual(alert.status.tone, 'bg-emerald-100 text-emerald-700');
});

test('a search with matches shows the result count', async () => {
    const { alert } = await alertFixture([5, 1, 2]);

    await alert.fetchItems();
    await search(alert, 'HEMAT');
    assert.equal(alert.status.text, '1 Hasil');

    await search(alert, 'CPNS');
    assert.equal(alert.status.text, '2 Hasil');
});

test('clearing the search shows the unregistered count again', async () => {
    const { alert } = await alertFixture([5, 0, 5]);

    await alert.fetchItems();
    await search(alert, 'TIDAKADA');
    await search(alert, '');

    assert.equal(alert.status.text, '5 Belum Terdaftar');
});

test('the badge keeps the loaded status until the search response arrives', async () => {
    const { alert } = await alertFixture([5, 0]);

    await alert.fetchItems();
    alert.filter.search = 'TIDAKADA';

    assert.equal(alert.status.text, '5 Belum Terdaftar');
});

test('the alert stays hidden while loading and when no code needs a review', async () => {
    const { alert } = await alertFixture([0]);
    assert.equal(alert.visible, false);

    await alert.fetchItems();

    assert.equal(alert.visible, false);
});

test('the alert shows for one or more codes and a search does not change the codes to review', async () => {
    const { alert } = await alertFixture([1, 0]);

    await alert.fetchItems();
    assert.equal(alert.visible, true);

    alert.reviewOpen = true;
    await search(alert, 'TIDAKADA');

    assert.equal(alert.pendingTotal, 1);
    assert.equal(alert.visible, true);
});

test('the alert hides once the last code is registered', async () => {
    const { alert } = await alertFixture([1, 0]);

    await alert.fetchItems();
    // What refresh() runs after a registration.
    await alert.fetchItems();

    assert.equal(alert.pendingTotal, 0);
    assert.equal(alert.visible, false);
});

test('registering the last code from a search hides the alert and keeps the search', async () => {
    const { alert, requests } = await alertFixture([1, 1, 0, 0]);

    await alert.fetchItems();
    alert.reviewOpen = true;
    await search(alert, 'KODE0');
    assert.equal(alert.visible, true);

    // What @promo-codes-changed runs after the registration succeeds.
    await alert.refresh();

    assert.deepEqual(requests.slice(2), ['KODE0', '']);
    assert.equal(alert.filter.search, 'KODE0');
    assert.equal(alert.status.text, '0 Hasil');
    assert.equal(alert.pendingTotal, 0);
    assert.equal(alert.visible, false);
});

test('registering from a search with codes left keeps the alert with the new total', async () => {
    const { alert } = await alertFixture([3, 1, 0, 2]);

    await alert.fetchItems();
    await search(alert, 'KODE0');
    await alert.refresh();

    assert.equal(alert.pendingTotal, 2);
    assert.equal(alert.visible, true);
});

test('a failed check after a total of zero shows the error with a retry', async () => {
    const { alert } = await alertFixture([0, new Error('down'), 2]);

    await alert.fetchItems();
    assert.equal(alert.totalError, '');

    await alert.refresh();
    assert.equal(alert.totalError, 'Request failed');
    assert.equal(alert.visible, false);

    // Coba Lagi.
    await alert.refresh();
    assert.equal(alert.totalError, '');
    assert.equal(alert.visible, true);
});

test('a failed total check during a search also shows the error', async () => {
    const { alert } = await alertFixture([0, 1, 1, new Error('down')]);

    await alert.fetchItems();
    await search(alert, 'KODE0');
    await alert.refresh();

    assert.equal(alert.totalError, 'Request failed');
    assert.equal(alert.loadError, '');
});
