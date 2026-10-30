import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

async function gridFixture() {
    const requests = [];
    const context = vm.createContext({ window: { dispatchEvent() {} }, CustomEvent: class {} });
    const mocks = {
        '../../components/toast': { showToast() {} },
        '../../http': {
            errorMessage: () => 'Request failed',
            validationErrors: ({ data }) => Object.fromEntries(Object.entries(data.errors ?? {}).map(([key, messages]) => [key, messages[0]])),
        },
        './api': {
            bulkCreatePromoCodes: (payload) => new Promise((resolve, reject) => requests.push({ payload, resolve, reject })),
            fetchMasters: async () => ({ data: [] }),
            fetchPlatforms: async () => ({ data: [] }),
        },
    };
    const code = await readFile(new URL('../../resources/js/pages/promo/bulk-entry.js', import.meta.url), 'utf8');
    const module = new vm.SourceTextModule(code, { context });
    await module.link((specifier) => {
        const mock = mocks[specifier];
        return new vm.SyntheticModule(Object.keys(mock), function () {
            for (const [key, value] of Object.entries(mock)) this.setExport(key, value);
        }, { context });
    });
    await module.evaluate();
    const grid = module.namespace.default();
    grid.reset();
    grid.options = {
        platforms: [{ id: 1, name: 'JADIASN' }],
        funnels: [{ id: 2, name: 'Marketing' }],
        sources: [{ id: 3, name: 'Instagram' }],
    };
    const paste = (text, index = 0) => grid.paste({ clipboardData: { getData: () => text }, preventDefault() {} }, index);
    const response = (request, results) => request.resolve({ data: {
        rows: results,
        summary: { total: results.length, succeeded: results.filter(row => row.status === 'created').length, failed: results.filter(row => row.status === 'failed').length },
    } });

    return { grid, requests, paste, response };
}

test('unknown pasted Funnel/Source stays visible and cannot be sent as null', async () => {
    const { grid, requests, paste } = await gridFixture();
    paste('HEMAT\tJADIASN\tMarketng\tInstgram');
    await grid.submit();
    assert.equal(requests.length, 0);
    assert.equal(grid.rows[0].unmatched.funnel_id, 'Marketng');
    assert.equal(grid.rows[0].unmatched.source_id, 'Instgram');
    assert.match(grid.rows[0].errors.funnel_id, /Marketng/);
    assert.equal(grid.rows[0].status, 'failed');
});

test('valid rows save while pasted errors remain on the invalid row', async () => {
    const { grid, requests, paste, response } = await gridFixture();
    paste('BAD\tJADIASN\tMarketng\tInstagram\nGOOD\tJADIASN\tMarketing\tInstagram');
    const saving = grid.submit();
    assert.equal(Object.keys(requests[0].payload[0]).length, 0);
    const valid = requests[0].payload[1];
    assert.equal(valid.funnel_id, 2);
    response(requests[0], [{ row: 2, client_row_id: valid.client_row_id, status: 'created', errors: {}, promo_code: { code: 'GOOD' } }]);
    await saving;
    assert.equal(grid.rows[0].status, 'failed');
    assert.match(grid.rows[0].errors.funnel_id, /Marketng/);
    assert.equal(grid.rows[1].status, 'created');
    assert.equal(grid.summary.total, 2);
    assert.equal(grid.summary.succeeded, 1);
    assert.equal(grid.summary.failed, 1);
});

test('explicitly clearing an unknown value allows intentionally empty metadata', async () => {
    const { grid, requests, paste, response } = await gridFixture();
    paste('HEMAT\tJADIASN\tMarketng\t');
    grid.rows[0].funnel_id = '';
    grid.clearError(grid.rows[0], 'funnel_id');
    const saving = grid.submit();
    assert.equal(requests[0].payload[0].funnel_id, null);
    assert.equal(requests[0].payload[0].source_id, null);
    response(requests[0], [{ row: 1, client_row_id: String(grid.rows[0].key), status: 'created', errors: {}, promo_code: { code: 'HEMAT' } }]);
    await saving;
    assert.equal(grid.rows[0].status, 'created');
});
