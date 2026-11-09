import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

async function taskApiFixture({ uploadResponse = { ok: true, status: 201, data: { data: { id: 5 } } } } = {}) {
    const requests = [];
    const uploads = [];
    class MockFormData {
        entries = [];

        append(...entry) {
            this.entries.push(entry);
        }
    }
    const context = vm.createContext({
        FormData: MockFormData,
        document: { querySelector: () => ({ content: 'csrf-token' }) },
        fetch: async (url, options) => {
            uploads.push({ url, options });

            return { ok: uploadResponse.ok, status: uploadResponse.status, json: async () => uploadResponse.data };
        },
    });
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

    return { api: module.namespace, requests, uploads };
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

test('evidence submission posts multipart data to the task evidence endpoint without the feature flag', async () => {
    const { api, uploads } = await taskApiFixture();
    const file = { name: 'hasil.pdf' };

    const result = await api.submitEvidence(12, { file, note: 'Hasil final' });

    assert.equal(result.data.id, 5);
    assert.equal(uploads[0].url, '/api/tasks/12/evidences');
    assert.equal(uploads[0].options.method, 'POST');
    assert.equal(uploads[0].options.headers.Accept, 'application/json');
    assert.equal(uploads[0].options.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(uploads[0].options.headers['X-CSRF-TOKEN'], 'csrf-token');
    assert.deepEqual(uploads[0].options.body.entries, [['file', file], ['note', 'Hasil final']]);
});

test('evidence submission preserves backend validation errors', async () => {
    const { api } = await taskApiFixture({
        uploadResponse: { ok: false, status: 422, data: { errors: { url: ['Tautan tidak valid.'] } } },
    });

    await assert.rejects(api.submitEvidence(12, { url: 'bad-url' }), (error) => {
        assert.equal(error.status, 422);
        assert.deepEqual(error.data.errors.url, ['Tautan tidak valid.']);

        return true;
    });
});
