import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

/**
 * Runs the real Tasklist page module with the real services/tasks.js and http.js, with only fetch mocked,
 * so the assertions are about the HTTP request the browser actually sends and how the page reacts to the
 * backend's responses:
 * - PATCH /api/tasks/{id}/status (ChangeTaskStatusRequest: status + version) from the Board and the detail;
 * - POST /api/tasks/{id}/reviews (ReviewTaskRequest: decision, note, version) for QA decisions;
 * - POST /api/tasks (StoreTaskRequest) from the Create Task form, until the task shows on the Board and Table;
 * - PATCH /api/tasks/{id}/assignment (AssignTaskRequest: pic_id, division_id, version) from the detail.
 */
const PIC = { id: 10, role: { code_role: 'EMP' }, division: { code_division: 'MKT' } };
const ADMIN = { id: 1, role: { code_role: 'ADM' }, division: null };

async function tasklistPage(routes, sessionUser = PIC, apiReady = 'false') {
    const requests = [];
    const toasts = [];
    const components = {};

    const fetch = async (url, options = {}) => {
        const method = options.method ?? 'GET';
        const body = options.body ? JSON.parse(options.body) : undefined;
        requests.push({ method, url, body, headers: options.headers });

        const handler = routes[`${method} ${url.split('?')[0]}`];
        if (!handler) throw new Error(`Unexpected request ${method} ${url}`);
        const { status = 200, json } = handler(body, requests);

        return { ok: status >= 200 && status < 300, status, json: async () => json };
    };

    const context = vm.createContext({
        fetch,
        URL,
        URLSearchParams,
        setTimeout,
        console,
        document: { querySelector: (selector) => (selector === 'meta[name="csrf-token"]' ? { content: 'csrf-test' } : null) },
        location: { search: '', href: 'http://localhost/tasks' },
        history: { replaceState() {} },
    });
    const source = (path) => readFile(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
    const load = async (path, meta = {}) => {
        const module = new vm.SourceTextModule(await source(path), {
            context,
            initializeImportMeta: (importMeta) => Object.assign(importMeta, meta),
        });
        return module;
    };
    const synthetic = (exports) => new vm.SyntheticModule(Object.keys(exports), function () {
        for (const [key, value] of Object.entries(exports)) this.setExport(key, value);
    }, { context });

    const http = await load('http.js');
    const workflow = await load('pages/tasks/workflow.js');
    const services = await load('services/tasks.js', { env: { VITE_TASK_API_READY: apiReady } });
    const page = await load('pages/tasks/index.js');
    const alpine = synthetic({
        default: {
            data: (name, factory) => (components[name] = factory),
            store: () => ({ user: sessionUser }),
        },
    });
    const toast = synthetic({ showToast: (options) => toasts.push(options) });

    const resolve = (specifier) => ({
        alpinejs: alpine,
        '../../components/toast': toast,
        '../../http': http,
        '../http': http,
        '../../services/tasks': services,
        './workflow': workflow,
    })[specifier];

    for (const module of [http, workflow, services, page]) await module.link(resolve);
    await page.evaluate();

    // Alpine merges nested x-data scopes: the detail panel reads and calls the Tasklist parent.
    const instance = (factory, parent = null) => {
        const object = Object.create(parent);
        Object.defineProperties(object, Object.getOwnPropertyDescriptors(factory()));
        object.$nextTick = (callback) => callback?.();
        object.$watch = () => {};
        object.$refs = {};
        if (!parent) return object;

        // Writes to a key the child does not own go to the parent scope, as in Alpine.
        return new Proxy(object, {
            set(target, key, value) {
                if (!Object.hasOwn(target, key) && key in parent) parent[key] = value;
                else target[key] = value;
                return true;
            },
        });
    };
    const board = instance(components.tasklist);
    const detail = () => instance(components.taskDetail, board);

    return { board, detail, requests, toasts, instance: (name) => instance(components[name], board) };
}

const task = (overrides = {}) => ({
    id: 7,
    title: 'Konten promo November',
    division: { code_division: 'MKT', name: 'Marketing' },
    pic: { id: PIC.id, fullname: 'Staff Marketing' },
    priority: 'high',
    status: 'todo',
    start_date: '2026-11-01',
    deadline: '2026-11-20',
    requires_review: false,
    evidence_required: false,
    evidence_count: 0,
    can_view_detail: true,
    version: 4,
    updated_at: '2026-11-09T03:00:00Z',
    ...overrides,
});

const statusRequests = (requests) => requests.filter((request) => request.method === 'PATCH');

test('Board drag sends exactly { status, version } to the status endpoint and moves the card with the new version', async () => {
    const { board, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task()], meta: { status_counts: {} } } }),
        'PATCH /api/tasks/7/status': (body) => ({ json: { data: task({ status: body.status, version: body.version + 1 }), message: 'Status tugas berhasil diperbarui.' } }),
    });
    await board.load();

    board.draggingId = 7;
    await board.drop('in_progress');

    const [first] = statusRequests(requests);
    assert.equal(first.url, '/api/tasks/7/status');
    assert.deepEqual(first.body, { status: 'in_progress', version: 4 });
    assert.equal(first.headers['X-CSRF-TOKEN'], 'csrf-test');
    assert.equal(first.headers.Accept, 'application/json');

    assert.deepEqual(board.tasksIn('todo').map(({ id }) => id), []);
    assert.deepEqual(board.tasksIn('in_progress').map(({ id }) => id), [7]);
    assert.equal(board.tasks[0].version, 5);
    assert.equal(toasts.at(-1).text, 'Status tugas berhasil diperbarui.');

    // The next move is based on the version the server returned, not the one first loaded.
    board.draggingId = 7;
    await board.drop('done');
    assert.deepEqual(statusRequests(requests)[1].body, { status: 'done', version: 5 });
    assert.deepEqual(board.tasksIn('done').map(({ id }) => id), [7]);
});

test('Board drag based on an outdated version keeps the card, explains the conflict, and reloads the list', async () => {
    let listCalls = 0;
    const { board, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => {
            listCalls += 1;
            // After the conflict the server has the other user's change: version 6, already On Process.
            return { json: { data: [listCalls === 1 ? task() : task({ status: 'in_progress', version: 6 })], meta: { status_counts: {} } } };
        },
        'PATCH /api/tasks/7/status': () => ({
            status: 409,
            json: { message: 'Tugas telah diubah oleh pengguna lain. Muat ulang detail tugas sebelum menyimpan perubahan.', current_version: 6 },
        }),
    });
    await board.load();

    board.draggingId = 7;
    await board.drop('in_progress');
    await new Promise((resolve) => setTimeout(resolve));

    assert.deepEqual(statusRequests(requests)[0].body, { status: 'in_progress', version: 4 });
    assert.equal(toasts.at(-1).title, 'Gagal');
    assert.match(toasts.at(-1).text, /sudah diperbarui oleh pengguna lain/);
    assert.equal(listCalls, 2);
    assert.equal(board.tasks[0].version, 6);
    assert.equal(board.movingId, null);
});

test('Board drag rejected by a backend business rule shows the server reason and leaves the card in place', async () => {
    const { board, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task({ status: 'in_progress' })], meta: { status_counts: {} } } }),
        'PATCH /api/tasks/7/status': () => ({
            status: 422,
            json: { message: 'Unggah evidence terlebih dahulu sebelum mengubah status ke Done.', errors: { status: ['Unggah evidence terlebih dahulu sebelum mengubah status ke Done.'] } },
        }),
    });
    await board.load();

    board.draggingId = 7;
    await board.drop('done');

    assert.equal(toasts.at(-1).text, 'Unggah evidence terlebih dahulu sebelum mengubah status ke Done.');
    assert.deepEqual(board.tasksIn('in_progress').map(({ id }) => id), [7]);
});

test('Detail status button sends the displayed version and updates the panel and the Board', async () => {
    const { board, detail: createDetail, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task({ version: 2 })], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: task({ version: 2, description: null, history: [] }) } }),
        'PATCH /api/tasks/7/status': (body) => ({ json: { data: task({ status: body.status, version: 3 }), message: 'Status tugas berhasil diperbarui.' } }),
    });
    await board.load();
    board.detailId = 7;
    const detail = createDetail();
    await detail.open(7);

    assert.deepEqual([...detail.transitions].map(({ to }) => to), ['in_progress']);
    await detail.runTransition(detail.transitions[0]);

    assert.deepEqual(statusRequests(requests)[0].body, { status: 'in_progress', version: 2 });
    assert.equal(detail.task.status, 'in_progress');
    assert.equal(detail.task.version, 3);
    assert.deepEqual(board.tasksIn('in_progress').map(({ id }) => id), [7]);
    assert.equal(board.tasks[0].version, 3);
    assert.equal(toasts.at(-1).title, 'Status diperbarui');
    assert.equal(detail.acting, null);
});

test('Detail status change based on an outdated version is rejected with a clear message and both views reload', async () => {
    let detailCalls = 0;
    let listCalls = 0;
    const { board, detail: createDetail, toasts } = await tasklistPage({
        'GET /api/tasks': () => {
            listCalls += 1;
            return { json: { data: [listCalls === 1 ? task() : task({ status: 'in_progress', version: 5 })], meta: { status_counts: {} } } };
        },
        'GET /api/tasks/7': () => {
            detailCalls += 1;
            return { json: { data: detailCalls === 1 ? task() : task({ status: 'in_progress', version: 5 }) } };
        },
        'PATCH /api/tasks/7/status': () => ({
            status: 409,
            json: { message: 'Tugas telah diubah oleh pengguna lain. Muat ulang detail tugas sebelum menyimpan perubahan.', current_version: 5 },
        }),
    });
    await board.load();
    board.detailId = 7;
    const detail = createDetail();
    await detail.open(7);

    await detail.runTransition(detail.transitions[0]);
    await new Promise((resolve) => setTimeout(resolve));

    assert.match(toasts.at(-1).text, /sudah diperbarui oleh pengguna lain/);
    assert.equal(detailCalls, 2);
    assert.equal(listCalls, 2);
    assert.equal(detail.task.version, 5);
    assert.equal(detail.task.status, 'in_progress');
    assert.equal(board.tasks[0].version, 5);
});

test('Dragging On QA back to On Process opens the revision note form instead of changing the status', async () => {
    const reviewed = task({ status: 'on_qa', requires_review: true });
    const { board, requests } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [reviewed], meta: { status_counts: {} } } }),
    }, ADMIN);
    await board.load();

    board.draggingId = 7;
    await board.drop('in_progress');

    assert.equal(board.detailId, 7);
    assert.equal(board.detailFocus, 'revision');
    assert.equal(statusRequests(requests).length, 0);
});

test('Create button follows meta.can_create, falling back to TaskPolicy::create: Admin, or a Manager with a division', async () => {
    const create = async (user) => (await tasklistPage({}, user)).board.canCreate;

    assert.equal(await create(ADMIN), true);
    assert.equal(await create({ id: 2, role: { code_role: 'MGR' }, division: { code_division: 'MKT' } }), true);
    assert.equal(await create({ id: 3, role: { code_role: 'MGR' }, division: null }), false);
    assert.equal(await create(PIC), false);

    // Once meta is loaded, the backend's can_create decides.
    const { board } = await tasklistPage({
        'GET /api/tasks/meta': () => ({ json: { data: { role: 'admin', division: null, default_division: null, divisions: [], can_create: false } } }),
        'GET /api/products/filters': () => ({ json: { data: { platforms: [] } } }),
    }, ADMIN);
    await board.loadMeta();
    assert.equal(board.canCreate, false);
});

test('QA decisions send { decision, note, version } to the review endpoint, from the Board and from the detail', async () => {
    const reviewed = task({ status: 'on_qa', requires_review: true, version: 8 });
    const { board, detail: createDetail, requests } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [reviewed], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: reviewed } }),
        'GET /api/tasks/7/evidences': () => ({ json: { data: [] } }),
        'GET /api/tasks/7/activities': () => ({ json: { data: [] } }),
        'POST /api/tasks/7/reviews': (body) => ({ json: { data: task({ ...reviewed, status: body.decision === 'approve' ? 'done' : 'in_progress', version: body.version + 1 }), message: 'ok' } }),
    }, ADMIN, 'true');
    await board.load();

    board.draggingId = 7;
    await board.drop('done');
    const reviews = () => requests.filter(({ url }) => url === '/api/tasks/7/reviews');
    assert.deepEqual(reviews()[0].body, { decision: 'approve', version: 8 });
    assert.equal(board.tasks[0].version, 9);

    board.detailId = 7;
    const detail = createDetail();
    await detail.open(7);
    detail.startReview('revision');
    detail.reviewNote = '  Lampirkan versi final.  ';
    await detail.submitReview();
    assert.deepEqual(reviews()[1].body, { decision: 'revision', note: 'Lampirkan versi final.', version: 8 });
    assert.equal(requests.some(({ body }) => body && 'updated_at' in body), false);
});

const createOptions = {
    divisions: [{ id: 3, code_division: 'MKT', name: 'Marketing', pics: [{ id: 10, fullname: 'Staff Marketing' }] }],
    categories: [{ id: 5, name: 'Konten', requires_review: true, evidence_required: true }],
    platforms: [{ id: 2, name: 'JadiASN' }],
    priorities: [{ value: 'low', label: 'Low' }, { value: 'medium', label: 'Medium' }],
    statuses: [{ value: 'backlog', label: 'Back Log' }, { value: 'todo', label: 'To Do' }],
    defaults: { priority: 'medium', status: 'backlog' },
};

async function createFormPage(postHandler, user = ADMIN) {
    const page = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [], meta: { status_counts: {} } } }),
        'GET /api/tasks/create-options': () => ({ json: { data: createOptions } }),
        'POST /api/tasks': postHandler,
    }, user);
    await page.board.load();
    page.board.createOpen = true;
    const form = page.instance('taskCreateForm');
    await form.loadOptions();
    return { ...page, form };
}

test('Create Task posts the StoreTaskRequest fields and the new task appears on the Board and in the Table', async () => {
    const { board, form, requests, toasts } = await createFormPage((body) => ({
        status: 201,
        json: {
            data: task({ id: 21, title: body.title, status: body.status, pic: { id: 10, fullname: 'Staff Marketing' }, requires_review: true, version: 1 }),
            message: 'Tugas berhasil dibuat.',
        },
    }));

    // <select x-model> yields strings, exactly as in the browser.
    Object.assign(form.form, { title: '  Banner promo November  ', description: '', category_id: '5', division_id: '3', pic_id: '10', platform_id: '', status: 'todo', deadline: '2026-11-30' });
    await form.submit();

    const post = requests.find(({ method }) => method === 'POST');
    assert.equal(post.url, '/api/tasks');
    assert.deepEqual(post.body, {
        title: 'Banner promo November', description: '', category_id: '5', division_id: '3', pic_id: '10', platform_id: '',
        priority: 'medium', start_date: '', deadline: '2026-11-30', status: 'todo', requires_review: true,
    });
    assert.equal(board.createOpen, false);
    assert.deepEqual(board.tasksIn('todo').map(({ id }) => id), [21]);
    assert.deepEqual([...board.pageRows].map(({ id }) => id), [21]);
    assert.equal(toasts.at(-1).title, 'Tugas Dibuat');
});

test('Create Task shows 422 errors on the fields and the duplicate-submission message on 409', async () => {
    const invalid = await createFormPage(() => ({
        status: 422,
        json: { message: 'invalid', errors: { deadline: ['Deadline tidak boleh sebelum hari ini.'], pic_id: ['PIC tidak valid.'] } },
    }));
    Object.assign(invalid.form.form, { title: 'A', division_id: '3' });
    await invalid.form.submit();
    assert.equal(invalid.form.errors.deadline, 'Deadline tidak boleh sebelum hari ini.');
    assert.equal(invalid.form.errors.pic_id, 'PIC tidak valid.');
    assert.equal(invalid.board.createOpen, true);

    const duplicate = await createFormPage(() => ({
        status: 409,
        json: { message: 'Tugas yang sama baru saja dikirim. Periksa daftar tugas sebelum mengirim ulang.' },
    }));
    Object.assign(duplicate.form.form, { title: 'A', division_id: '3' });
    const listCalls = () => duplicate.requests.filter(({ url, method }) => url === '/api/tasks' && method === 'GET').length;
    const listCallsBefore = listCalls();
    await duplicate.form.submit();
    assert.equal(duplicate.form.formError, 'Tugas yang sama baru saja dikirim. Periksa daftar tugas sebelum mengirim ulang.');
    assert.equal(listCalls(), listCallsBefore + 1);
});

test('History shows the backend sentence for approval, revision request, and resubmission, never raw codes', async () => {
    const activity = (id, action, action_label, description) => ({ id, action, action_label, description, changes: {}, actor: { id: 1, fullname: 'Admin' }, created_at: '2026-11-10T02:00:00Z' });
    const history = [
        activity(4, 'approved', 'Tugas disetujui', 'Status: On QA → Done'),
        activity(3, 'resubmitted', 'Hasil revisi dikirim ulang', 'Status: On Process → Ready for QA'),
        activity(2, 'revision_requested', 'Revisi diminta', 'Status: On QA → On Process; Catatan: Perbaiki warna; ukuran banner.'),
        activity(1, 'created', 'Tugas dibuat', 'Membuat tugas.'),
    ];
    const { board, detail: createDetail } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task()], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: task({ status: 'done', history, evidence: [] }) } }),
    }, ADMIN);
    await board.load();
    board.detailId = 7;
    const detail = createDetail();
    await detail.open(7);

    assert.deepEqual([...detail.activities].map((item) => [item.action_label, detail.activityDetail(item)]), [
        ['Tugas disetujui', 'Status: On QA → Done'],
        ['Hasil revisi dikirim ulang', 'Status: On Process → Ready for QA'],
        ['Revisi diminta', 'Status: On QA → On Process; Catatan: Perbaiki warna; ukuran banner.'],
        ['Tugas dibuat', ''],
    ]);
});

const MANAGER = { id: 2, role: { code_role: 'MGR' }, division: { code_division: 'MKT' } };
const assignmentOptions = {
    current_pic: { id: PIC.id, fullname: 'Staff Marketing' },
    current_division: { id: 3, code_division: 'MKT', name: 'Marketing' },
    can_change_division: false,
    locked_reason: null,
    divisions: [{ id: 3, code_division: 'MKT', name: 'Marketing', pics: [{ id: PIC.id, fullname: 'Staff Marketing' }, { id: 11, fullname: 'Staff Baru' }] }],
};

test('Changing the PIC sends { pic_id, division_id, version } to the assignment endpoint and updates the detail and the Board', async () => {
    const { board, detail: createDetail, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task({ can_assign: true })], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: task({ can_assign: true, history: [] }) } }),
        'GET /api/tasks/7/assignment-options': () => ({ json: { data: assignmentOptions } }),
        'PATCH /api/tasks/7/assignment': () => ({ json: { data: task({ can_assign: true, pic: { id: 11, fullname: 'Staff Baru' }, version: 5 }), message: 'PIC berhasil diperbarui.' } }),
    }, MANAGER);
    await board.load();
    board.detailId = 7;
    const detail = createDetail();
    await detail.open(7);

    assert.equal(detail.canAssignThis, true);
    await detail.openAssign();
    assert.equal(detail.assigneeState, 'ready');
    assert.deepEqual([...detail.eligibleAssignees].map(({ id }) => id), [11], 'the current PIC is not offered again');

    detail.assignForm.pic_id = '11';
    await detail.saveAssign();

    const sent = requests.find((request) => request.method === 'PATCH');
    assert.equal(sent.url, '/api/tasks/7/assignment');
    assert.deepEqual(sent.body, { pic_id: '11', division_id: '3', version: 4 });
    assert.equal(detail.task.pic.fullname, 'Staff Baru');
    assert.equal(detail.task.version, 5);
    assert.equal(board.tasks[0].pic.fullname, 'Staff Baru');
    assert.equal(board.tasks[0].version, 5);
    assert.equal(detail.assigneeState, 'idle');
    assert.equal(toasts.at(-1).text, 'PIC berhasil diperbarui.');
});

test('Assignment conflicts reload both views and a rejected PIC shows the server reason without changing the shown PIC', async () => {
    let detailCalls = 0;
    let assignStatus = 409;
    const { board, detail: createDetail, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task({ can_assign: true, version: detailCalls > 1 ? 6 : 4 })], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => {
            detailCalls += 1;
            return { json: { data: task({ can_assign: true, version: detailCalls > 1 ? 6 : 4 }) } };
        },
        'GET /api/tasks/7/assignment-options': () => ({ json: { data: assignmentOptions } }),
        'PATCH /api/tasks/7/assignment': () => (assignStatus === 409
            ? { status: 409, json: { message: 'Tugas telah diubah oleh pengguna lain.', current_version: 6 } }
            : { status: 422, json: { message: 'PIC tidak valid.', errors: { pic_id: ['PIC harus Staff aktif dari divisi tugas.'] } } }),
    }, MANAGER);
    await board.load();
    board.detailId = 7;
    const detail = createDetail();
    await detail.open(7);

    await detail.openAssign();
    detail.assignForm.pic_id = '11';
    await detail.saveAssign();
    await new Promise((resolve) => setTimeout(resolve));
    assert.match(toasts.at(-1).text, /sudah diperbarui oleh pengguna lain/);
    assert.equal(detail.task.version, 6);
    assert.equal(board.tasks[0].version, 6);

    assignStatus = 422;
    await detail.openAssign();
    detail.assignForm.pic_id = '11';
    await detail.saveAssign();
    assert.equal(detail.assigneeState, 'error');
    assert.equal(detail.assignmentError, 'PIC harus Staff aktif dari divisi tugas.');
    assert.equal(detail.task.pic.id, PIC.id, 'a rejected assignment leaves the shown PIC unchanged');
});
