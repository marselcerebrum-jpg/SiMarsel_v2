import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

async function taskApiFixture() {
    const requests = [];
    const context = vm.createContext({});
    const http = (url, options) => {
        requests.push({ url, options });

        return Promise.resolve({ data: {} });
    };
    const source = await readFile(new URL('../../resources/js/services/tasks.js', import.meta.url), 'utf8');
    const module = new vm.SourceTextModule(source, {
        context,
        initializeImportMeta(meta) {
            meta.env = { VITE_TASK_API_READY: 'false' };
        },
    });

    await module.link(() => new vm.SyntheticModule(['http'], function () {
        this.setExport('http', http);
    }, { context }));
    await module.evaluate();

    return { api: module.namespace, requests };
}

test('assignment options and updates use the existing backend endpoints', async () => {
    const { api, requests } = await taskApiFixture();
    const payload = { pic_id: 23, division_id: 4, version: 7 };

    await api.fetchAssignmentOptions(12);
    await api.assignTask(12, payload);

    assert.equal(requests[0].url, '/api/tasks/12/assignment-options');
    assert.equal(requests[0].options, undefined);
    assert.equal(requests[1].url, '/api/tasks/12/assignment');
    assert.equal(requests[1].options.method, 'PATCH');
    assert.equal(requests[1].options.body, payload);
});

test('status changes send the version to the status workflow endpoint', async () => {
    const { api, requests } = await taskApiFixture();
    const payload = { status: 'in_progress', version: 7 };

    await api.changeTaskStatus(12, payload);

    assert.equal(requests[0].url, '/api/tasks/12/status');
    assert.equal(requests[0].options.method, 'PATCH');
    assert.equal(requests[0].options.body, payload);
});
