import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

async function managementFixture({ update } = {}) {
    const events = [];
    const context = vm.createContext({
        window: { dispatchEvent: (event) => events.push(event.type) },
        CustomEvent: class {
            constructor(type) {
                this.type = type;
            }
        },
    });
    const mocks = {
        '../../components/toast': { showToast() {} },
        '../../http': {
            errorMessage: () => 'Request failed',
            validationErrors: ({ data }) => Object.fromEntries(Object.entries(data.errors ?? {}).map(([key, messages]) => [key, messages[0]])),
        },
        './api': {
            createPromoCode: async (body) => ({ data: { id: 2, ...body, usages_count: 0 } }),
            deletePromoCode: async () => ({}),
            fetchMasters: async () => ({ data: [] }),
            fetchPlatforms: async () => ({ data: [] }),
            fetchPromoCodes: async () => ({ data: [], meta: null }),
            updatePromoCode: update ?? (async (id, body) => ({ data: { ...saved, ...body, id } })),
        },
        './filter': {
            defaultFilter: () => ({ search: '', platform_id: '', page: 1 }),
            takePrefillFromUrl: () => null,
            toQueryString: () => '',
        },
    };
    const code = await readFile(new URL('../../resources/js/pages/promo/promo-code.js', import.meta.url), 'utf8');
    const module = new vm.SourceTextModule(code, { context });
    await module.link((specifier) => {
        const mock = mocks[specifier];
        return new vm.SyntheticModule(Object.keys(mock), function () {
            for (const [key, value] of Object.entries(mock)) this.setExport(key, value);
        }, { context });
    });
    await module.evaluate();

    const management = module.namespace.default();
    management.items = [saved];

    return { management, events };
}

const saved = { id: 1, code: 'LAMA', platform: { id: 1, name: 'JADIASN' }, funnel: null, source: null };

test('a successful edit refreshes the unregistered code alert', async () => {
    const { management, events } = await managementFixture();

    management.openEdit(saved);
    management.form.code = 'HEMAT';
    await management.save();

    assert.deepEqual(events, ['promo-codes-changed']);
    assert.equal(management.items[0].code, 'HEMAT');
});

test('a failed edit does not refresh the alert', async () => {
    const { management, events } = await managementFixture({
        update: async () => {
            throw { status: 422, data: { errors: { code: ['Kode sudah terdaftar.'] } } };
        },
    });

    management.openEdit(saved);
    management.form.code = 'HEMAT';
    await management.save();

    assert.deepEqual(events, []);
    assert.equal(management.errors.code, 'Kode sudah terdaftar.');
});
