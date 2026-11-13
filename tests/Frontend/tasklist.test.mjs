import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

/**
 * Runs the real Tasklist page module with the real services/tasks.js and http.js, with only fetch mocked,
 * so the assertions are about the HTTP request the browser actually sends and how the page reacts to the
 * backend's responses:
 * - PATCH /api/tasks/{id}/status (ChangeTaskStatusRequest: status + version) from the Board and the detail;
 * - POST /api/tasks/{id}/reviews (ReviewTaskRequest: decision, note, version) for QA decisions, with the default VITE_TASK_API_READY=false;
 * - POST /api/tasks (StoreTaskRequest) from the Create Task form, until the task shows on the Board and Table;
 * - PATCH /api/tasks/{id}/assignment (AssignTaskRequest: pic_id, division_id, version) from the detail;
 * - POST /api/tasks/{id}/evidences (StoreTaskEvidenceRequest: file or url, note) with the default VITE_TASK_API_READY=false;
 * - GET /api/tasks/{id}/activities for the detail's history, with its loading, error, and empty states;
 * - GET /api/tasks/monitoring?scope=tasklist for the summary KPIs, with the same filters as the list.
 */
const PIC = { id: 10, role: { code_role: 'EMP' }, division: { code_division: 'MKT' } };
const ADMIN = { id: 1, role: { code_role: 'ADM' }, division: null };

async function tasklistPage(routes, sessionUser = PIC, apiReady = 'false') {
    const requests = [];
    const toasts = [];
    const components = {};

    const fetch = async (url, options = {}) => {
        const method = options.method ?? 'GET';
        const body = typeof options.body === 'string' ? JSON.parse(options.body) : options.body;
        requests.push({ method, url, body, headers: options.headers });

        const handler = routes[`${method} ${url.split('?')[0]}`];
        if (!handler) throw new Error(`Unexpected request ${method} ${url}`);
        const { status = 200, json } = await handler(body, requests);

        return { ok: status >= 200 && status < 300, status, json: async () => json };
    };

    // Evidence is sent as multipart; the mock keeps the appended fields so the test can read them.
    class FormData {
        fields = {};

        append(key, value) {
            this.fields[key] = value;
        }
    }

    const context = vm.createContext({
        fetch,
        FormData,
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
        'GET /api/tasks/7': () => ({ json: { data: task({ version: 2, description: null, history: [], next_statuses: [{ value: 'in_progress', label: 'On Process' }] }) } }),
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
            return { json: { data: detailCalls === 1 ? task({ next_statuses: [{ value: 'in_progress', label: 'On Process' }] }) : task({ status: 'in_progress', version: 5 }) } };
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
        'GET /api/tasks/7': () => ({ json: { data: task({ status: 'done', evidence: [] }) } }),
        'GET /api/tasks/7/activities': () => ({ json: { data: history } }),
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

test('Detail buttons follow next_statuses, and Approve/Revision only show when the backend sends can_review', async () => {
    let detail = task({ next_statuses: [{ value: 'in_progress', label: 'On Process' }] });
    const { board, detail: createDetail } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task()], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: detail } }),
    }, ADMIN);
    await board.load();
    board.detailId = 7;

    detail = task({ next_statuses: [] });
    const blocked = createDetail();
    await blocked.open(7);
    assert.deepEqual([...blocked.transitions], [], 'no button when the backend offers no next status');

    detail = task({ status: 'on_qa', requires_review: true, can_review: false, next_statuses: [] });
    const notReviewer = createDetail();
    await notReviewer.open(7);
    assert.deepEqual([...notReviewer.transitions], [], 'QA buttons need can_review');

    detail = task({ status: 'on_qa', requires_review: true, can_review: true, next_statuses: [] });
    const reviewer = createDetail();
    await reviewer.open(7);
    assert.deepEqual([...reviewer.transitions].map(({ review }) => review).sort(), ['approve', 'revision']);
});

test('A status move rejected in the detail shows the server reason and keeps the task where it was', async () => {
    const working = task({ status: 'in_progress', next_statuses: [{ value: 'done', label: 'Done' }] });
    const { board, detail: createDetail, toasts, requests } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [working], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: working } }),
        'PATCH /api/tasks/7/status': () => ({ status: 422, json: { message: 'Data tidak valid.', errors: { status: ['Unggah evidence terlebih dahulu sebelum mengubah status ke Done.'] } } }),
    });
    await board.load();
    board.detailId = 7;
    const detail = createDetail();
    await detail.open(7);

    assert.deepEqual([...detail.transitions].map(({ to }) => to), ['done']);
    await detail.runTransition(detail.transitions[0]);

    assert.deepEqual(statusRequests(requests)[0].body, { status: 'done', version: 4 });
    assert.equal(toasts.at(-1).title, 'Gagal');
    assert.equal(toasts.at(-1).text, 'Unggah evidence terlebih dahulu sebelum mengubah status ke Done.');
    assert.equal(detail.task.status, 'in_progress');
    assert.deepEqual(board.tasksIn('in_progress').map(({ id }) => id), [7]);
    assert.equal(detail.acting, null);
});

test('With the default config, Approve and Request Revision go to the review endpoint, never the status endpoint, from the Board and the detail', async () => {
    let current = task({ status: 'on_qa', requires_review: true, can_review: true, next_statuses: [], version: 8 });
    const decided = (body) => {
        const approved = body.decision === 'approve';
        const review = { id: 1, task_id: 7, cycle: 1, decision: body.decision, decision_label: approved ? 'Approve' : 'Request Revision', note: body.note, reviewer: { id: ADMIN.id, fullname: 'Admin' }, created_at: '2026-11-11T03:00:00Z' };
        const history = [{ id: 9, action: approved ? 'approved' : 'revision_requested', action_label: review.decision_label, description: body.note ?? '', changes: {}, actor: null, created_at: review.created_at }];
        current = { ...current, status: approved ? 'done' : 'in_progress', version: body.version + 1, can_review: false, last_review: review, history };
        return current;
    };
    const { board, detail: createDetail, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [current], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: current } }),
        'POST /api/tasks/7/reviews': (body) => ({ json: { data: decided(body), message: body.decision === 'approve' ? 'Tugas berhasil disetujui.' : 'Permintaan revisi berhasil dikirim.' } }),
    }, ADMIN);
    await board.load();
    const reviews = () => requests.filter(({ url }) => url === '/api/tasks/7/reviews');

    // Board: On QA → On Process opens the revision form in the detail; the card stays until the note is sent.
    board.draggingId = 7;
    await board.drop('in_progress');
    assert.equal(board.detailId, 7);
    assert.deepEqual(board.tasksIn('on_qa').map(({ id }) => id), [7]);
    const detail = createDetail();
    await detail.open(7);
    assert.equal(detail.reviewMode, 'revision', 'the form opens because the backend sent can_review');
    detail.reviewNote = '  Perbaiki ukuran banner.  ';
    await detail.submitReview();
    assert.deepEqual(reviews()[0].body, { decision: 'revision', note: 'Perbaiki ukuran banner.', version: 8 });
    assert.equal(reviews()[0].headers['X-CSRF-TOKEN'], 'csrf-test');
    assert.equal(detail.task.status, 'in_progress');
    assert.equal(detail.task.last_review.note, 'Perbaiki ukuran banner.');
    assert.deepEqual(detail.activities.map(({ action }) => action), ['revision_requested']);
    assert.equal(detail.reviewMode, null);
    assert.equal(toasts.at(-1).title, 'Revisi diminta');
    assert.equal(toasts.at(-1).text, 'Permintaan revisi berhasil dikirim.');
    assert.deepEqual(board.tasksIn('in_progress').map(({ id }) => id), [7]);
    assert.equal(board.tasks[0].version, 9);

    // Board: On QA → Done approves through the review endpoint and moves the card with the new version.
    current = { ...current, status: 'on_qa', can_review: true };
    await board.load();
    board.draggingId = 7;
    await board.drop('done');
    assert.deepEqual(reviews()[1].body, { decision: 'approve', version: 9 });
    assert.deepEqual(board.tasksIn('done').map(({ id }) => id), [7]);
    assert.equal(board.tasks[0].version, 10);
    assert.equal(toasts.at(-1).text, 'Tugas berhasil disetujui.');

    // Detail: the Approve button opens the form; without a note it sends note null.
    current = { ...current, status: 'on_qa', can_review: true };
    await detail.open(7);
    const approve = detail.transitions.find(({ review }) => review === 'approve');
    await detail.runTransition(approve);
    assert.equal(detail.reviewMode, 'approve');
    await detail.submitReview();
    assert.deepEqual(reviews()[2].body, { decision: 'approve', note: null, version: 10 });
    assert.equal(detail.task.status, 'done');
    assert.deepEqual(board.tasksIn('done').map(({ id }) => id), [7]);

    assert.equal(requests.some(({ url }) => url.endsWith('/status')), false);
    assert.equal(toasts.some(({ title }) => title === 'Menunggu Back End'), false);
});

test('Review rejections show a clear message: a missing note stays in the form, a decided task or a stale version reloads and the card goes back', async () => {
    let current = task({ status: 'on_qa', requires_review: true, can_review: true, next_statuses: [], version: 8 });
    let reply;
    const { board, detail: createDetail, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [current], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: current } }),
        'POST /api/tasks/7/reviews': () => reply(),
    }, ADMIN);
    await board.load();
    board.detailId = 7;
    const detail = createDetail();
    await detail.open(7);

    // 422 on the note: the form stays open with the reason next to the field.
    reply = () => ({ status: 422, json: { message: 'Data tidak valid.', errors: { note: ['Catatan review maksimal 2000 karakter.'] } } });
    detail.startReview('revision');
    detail.reviewNote = 'x';
    await detail.submitReview();
    assert.equal(detail.reviewMode, 'revision');
    assert.equal(detail.reviewError, 'Catatan review maksimal 2000 karakter.');
    assert.equal(detail.task.status, 'on_qa');

    // A revision without a note is stopped before any request.
    const sent = requests.length;
    detail.reviewNote = '   ';
    await detail.submitReview();
    assert.equal(requests.length, sent);
    assert.equal(detail.reviewError, 'Catatan alasan revisi wajib diisi.');

    // 422 on the decision: another reviewer already decided. The reason is shown and both views reload.
    reply = () => {
        current = { ...current, status: 'done', can_review: false, version: 9 };
        return { status: 422, json: { message: 'Data tidak valid.', errors: { decision: ['Keputusan review hanya dapat diberikan pada tugas berstatus On QA, bukan Done.'] } } };
    };
    detail.startReview('approve');
    await detail.submitReview();
    await new Promise((resolve) => setTimeout(resolve));
    assert.equal(toasts.at(-1).title, 'Gagal');
    assert.equal(toasts.at(-1).text, 'Keputusan review hanya dapat diberikan pada tugas berstatus On QA, bukan Done.');
    assert.equal(detail.reviewMode, null);
    assert.equal(detail.task.status, 'done');
    assert.deepEqual(board.tasksIn('done').map(({ id }) => id), [7]);

    // 409 from the Board: the card stays in On QA, the latest data is loaded and the message is clear.
    current = { ...current, status: 'on_qa', can_review: true, version: 9 };
    await board.load();
    board.tasks[0].version = 8;
    reply = () => {
        current = { ...current, version: 10 };
        return { status: 409, json: { message: 'Tugas telah diubah oleh pengguna lain.', current_version: 10 } };
    };
    board.draggingId = 7;
    await board.drop('done');
    await new Promise((resolve) => setTimeout(resolve));
    assert.match(toasts.at(-1).text, /sudah diperbarui oleh pengguna lain/);
    assert.deepEqual(board.tasksIn('on_qa').map(({ id }) => id), [7]);
    assert.equal(board.tasks[0].version, 10);

    // 403 from the Board: the backend message is shown and the card stays in On QA.
    reply = () => ({ status: 403, json: { message: 'This action is unauthorized.' } });
    board.draggingId = 7;
    await board.drop('done');
    assert.equal(toasts.at(-1).title, 'Gagal');
    assert.deepEqual(board.tasksIn('on_qa').map(({ id }) => id), [7]);

    assert.equal(requests.some(({ url }) => url.endsWith('/status')), false);
});

test('Dragging On QA to On Process does not open the revision form when the backend does not send can_review', async () => {
    const reviewed = task({ status: 'on_qa', requires_review: true, can_review: false, next_statuses: [] });
    const { board, detail: createDetail, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [reviewed], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: reviewed } }),
    }, ADMIN);
    await board.load();

    board.draggingId = 7;
    await board.drop('in_progress');
    const detail = createDetail();
    await detail.open(7);

    assert.equal(detail.reviewMode, null);
    assert.equal(toasts.at(-1).title, 'Revisi tidak dapat diminta');
    assert.deepEqual(board.tasksIn('on_qa').map(({ id }) => id), [7]);
    assert.equal(requests.some(({ method }) => method !== 'GET'), false);
});

const activityOf = (id, action, action_label, description, actor = { id: 1, fullname: 'Admin' }, created_at = '2026-11-10T02:00:00Z') => ({ id, action, action_label, description, changes: {}, actor, created_at });

test('With the default config, the detail loads its history from the history API, newest first, with the actor, time, and change', async () => {
    const history = [
        activityOf(5, 'evidence_submitted', 'Evidence dikirim', 'Evidence: desain-final.pdf', { id: PIC.id, fullname: 'Staff Marketing' }, '2026-11-10T04:00:00Z'),
        activityOf(4, 'status_changed', 'Status diubah', 'Status: To Do → On Process', { id: PIC.id, fullname: 'Staff Marketing' }, '2026-11-10T03:00:00Z'),
        activityOf(3, 'reassigned', 'PIC diganti', 'PIC: Budi → Staff Marketing', { id: 2, fullname: 'Manajer Marketing' }, '2026-11-10T02:30:00Z'),
        activityOf(2, 'updated', 'Informasi tugas diubah', 'Deadline: 20 Nov 2026 → 25 Nov 2026', { id: 2, fullname: 'Manajer Marketing' }, '2026-11-10T02:10:00Z'),
        activityOf(1, 'created', 'Tugas dibuat', 'Membuat tugas.', { id: null, fullname: 'Akun Terhapus' }, '2026-11-10T02:00:00Z'),
    ];
    let states = [];
    const { board, detail: createDetail, requests } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task()], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: task({ evidence: [] }) } }),
        'GET /api/tasks/7/activities': () => {
            states.push(detail.activityState);
            return { json: { data: history } };
        },
    });
    await board.load();
    board.detailId = 7;
    const detail = createDetail();
    await detail.open(7);

    const gets = requests.filter(({ method }) => method === 'GET').map(({ url }) => url);
    assert.deepEqual(gets.slice(-2), ['/api/tasks/7', '/api/tasks/7/activities'], 'history is fetched although VITE_TASK_API_READY is false');
    assert.equal(requests.at(-1).headers.Accept, 'application/json');
    assert.deepEqual(states, ['loading'], 'the history section shows its loading state while the history is fetched');
    assert.equal(detail.state, 'ready');
    assert.equal(detail.activityState, 'ready');
    assert.deepEqual([...detail.activities].map(({ id }) => id), [5, 4, 3, 2, 1], 'the order of the backend (newest first) is kept');
    assert.deepEqual([...detail.activities].map((item) => [item.action_label, item.actor.fullname, detail.activityDetail(item)]), [
        ['Evidence dikirim', 'Staff Marketing', 'Evidence: desain-final.pdf'],
        ['Status diubah', 'Staff Marketing', 'Status: To Do → On Process'],
        ['PIC diganti', 'Manajer Marketing', 'PIC: Budi → Staff Marketing'],
        ['Informasi tugas diubah', 'Manajer Marketing', 'Deadline: 20 Nov 2026 → 25 Nov 2026'],
        ['Tugas dibuat', 'Akun Terhapus', ''],
    ]);
    assert.equal(detail.activities[0].created_at, '2026-11-10T04:00:00Z');

    // A task without activity shows the empty state, not a loading or error state.
    history.length = 0;
    states = [];
    await detail.open(7);
    assert.equal(detail.activityState, 'ready');
    assert.equal(detail.activities.length, 0);
});

test('A failed history request shows its own error with a retry and keeps the detail open', async () => {
    let reply = () => ({ status: 500, json: { message: 'Server Error' } });
    const { board, detail: createDetail } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task()], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: task({ title: 'Tetap tampil' }) } }),
        'GET /api/tasks/7/activities': () => reply(),
    });
    await board.load();
    board.detailId = 7;
    const detail = createDetail();
    await detail.open(7);

    assert.equal(detail.state, 'ready', 'the rest of the detail stays usable');
    assert.equal(detail.task.title, 'Tetap tampil');
    assert.equal(detail.activityState, 'error');
    assert.equal(detail.activityError, 'Histori aktivitas gagal dimuat.');

    reply = () => ({ status: 403, json: { message: 'This action is unauthorized.' } });
    await detail.loadActivities();
    assert.equal(detail.activityState, 'error');
    assert.equal(detail.activityError, 'Anda tidak memiliki akses untuk tindakan ini.');

    reply = () => ({ json: { data: [activityOf(1, 'created', 'Tugas dibuat', 'Membuat tugas.')] } });
    await detail.loadActivities();
    assert.equal(detail.activityState, 'ready');
    assert.equal(detail.activityError, '');
    assert.deepEqual([...detail.activities].map(({ id }) => id), [1]);
});

test('History updated by a change is not overwritten by an older history response, and closing the detail clears it', async () => {
    let releaseHistory;
    const { board, detail: createDetail } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task()], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: task() } }),
        'GET /api/tasks/7/activities': () => new Promise((resolve) => {
            releaseHistory = () => resolve({ json: { data: [activityOf(1, 'created', 'Tugas dibuat', 'Membuat tugas.')] } });
        }),
    }, MANAGER);
    await board.load();
    board.detailId = 7;
    const detail = createDetail();
    const opening = detail.open(7);
    await new Promise((resolve) => setTimeout(resolve));
    assert.equal(detail.activityState, 'loading');

    // The change's response carries the newest history before the first history request returns.
    detail.applyTask(task({ title: 'Judul baru', version: 5, history: [activityOf(2, 'updated', 'Informasi tugas diubah', 'Judul: Konten promo November → Judul baru'), activityOf(1, 'created', 'Tugas dibuat', 'Membuat tugas.')] }));
    releaseHistory();
    await opening;
    assert.equal(detail.activityState, 'ready');
    assert.deepEqual([...detail.activities].map(({ id }) => id), [2, 1]);

    detail.reset();
    assert.equal(detail.activityState, 'idle');
    assert.equal(detail.activities.length, 0);
});

const metrics = (overrides = {}) => ({
    total_tasks: 0, backlog: 0, todo: 0, on_process: 0, ready_for_qa: 0, on_qa: 0,
    completed: 0, unfinished: 0, overdue: 0, due_today: 0, urgent: 0, completion_rate: 0,
    ...overrides,
});
const monitoringOf = (values, label = 'Tugas divisi Anda') => ({
    scope: { key: 'tasklist', label },
    as_of: '2026-11-11',
    metrics: metrics(values),
    by_priority: {},
    by_division: [],
});
const monitoringRequests = (requests) => requests.filter(({ url }) => url.startsWith('/api/tasks/monitoring'));

test('With the default config, the summary comes from the monitoring API with the Tasklist scope and the same filters as the list', async () => {
    const backend = metrics({ total_tasks: 12, on_process: 3, overdue: 2, due_today: 1, completed: 3, unfinished: 9, urgent: 4, completion_rate: 25 });
    const { board, requests } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task()], meta: { status_counts: {} } } }),
        'GET /api/tasks/monitoring': () => ({ json: { data: { ...monitoringOf(), metrics: backend } } }),
    }, MANAGER);
    board.filters = { search: '  promo ', division: 'MKT', priority: 'urgent', status: 'todo' };
    await board.load();

    const list = requests.find(({ url }) => url.startsWith('/api/tasks?'));
    const summary = monitoringRequests(requests);
    assert.equal(summary.length, 1, 'the summary is requested although VITE_TASK_API_READY is false');
    assert.equal(summary[0].url, '/api/tasks/monitoring?scope=tasklist&search=promo&division=MKT&priority=urgent&status=todo');
    assert.equal(list.url, '/api/tasks?search=promo&division=MKT&priority=urgent&status=todo', 'the list and the summary use the same filters');
    assert.equal(summary[0].headers.Accept, 'application/json');

    // The numbers are the backend's, not counted from the single task loaded in the list.
    assert.equal(board.summaryState, 'ready');
    assert.deepEqual({ ...board.summary }, backend);
    assert.equal(board.summary.completion_rate, 25);

    // Reset Filter asks for the default configuration again.
    board.resetFilters();
    await board.load();
    assert.equal(monitoringRequests(requests).at(-1).url, '/api/tasks/monitoring?scope=tasklist&division=MKT');
});

test('Without tasks the summary shows zero, a failed summary shows its own error with a retry, and an older response is ignored', async () => {
    let reply = () => ({ json: { data: monitoringOf({}) } });
    let states = [];
    const { board } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [], meta: { status_counts: {} } } }),
        'GET /api/tasks/monitoring': () => {
            states.push(board.summaryState);
            return reply();
        },
    }, ADMIN);
    await board.load();
    assert.deepEqual(states, ['loading'], 'loading while the summary is fetched');
    assert.equal(board.summaryState, 'ready');
    assert.equal(board.summary.total_tasks, 0);
    assert.equal(board.summary.completion_rate, 0);

    reply = () => ({ status: 500, json: { message: 'Server Error' } });
    await board.load();
    assert.equal(board.summaryState, 'error');
    assert.equal(board.summaryError, 'Ringkasan tugas gagal dimuat.');
    assert.equal(board.status, 'ready', 'the list stays usable when only the summary fails');

    reply = () => ({ status: 422, json: { message: 'Data tidak valid.', errors: { division: ['Divisi tidak ditemukan.'] } } });
    await board.loadSummary();
    assert.equal(board.summaryState, 'error');

    reply = () => ({ json: { data: monitoringOf({ total_tasks: 5, unfinished: 5 }) } });
    await board.loadSummary();
    assert.equal(board.summaryState, 'ready');
    assert.equal(board.summaryError, '');
    assert.equal(board.summary.total_tasks, 5);

    // A response for an older filter that arrives last does not replace the newer summary.
    let releaseOld;
    reply = () => new Promise((resolve) => {
        releaseOld = () => resolve({ json: { data: monitoringOf({ total_tasks: 99 }) } });
    });
    const older = board.loadSummary();
    reply = () => ({ json: { data: monitoringOf({ total_tasks: 7 }) } });
    await board.loadSummary();
    releaseOld();
    await older;
    assert.equal(board.summary.total_tasks, 7);
});

test('A status change from the Board reloads the summary, and opening a detail without changes does not', async () => {
    let current = metrics({ total_tasks: 1, todo: 1, unfinished: 1 });
    const { board, detail: createDetail, requests } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task()], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: task() } }),
        'GET /api/tasks/7/activities': () => ({ json: { data: [] } }),
        'GET /api/tasks/monitoring': () => ({ json: { data: { ...monitoringOf({}, 'Tugas yang ditugaskan kepada Anda'), metrics: current } } }),
        'PATCH /api/tasks/7/status': (body) => {
            current = metrics({ total_tasks: 1, on_process: 1, unfinished: 1 });
            return { json: { data: task({ status: body.status, version: body.version + 1 }), message: 'Status tugas berhasil diperbarui.' } };
        },
    });
    await board.load();
    assert.equal(monitoringRequests(requests).length, 1);
    assert.equal(board.summary.todo, 1);

    board.detailId = 7;
    await createDetail().open(7);
    await new Promise((resolve) => setTimeout(resolve));
    assert.equal(monitoringRequests(requests).length, 1, 'no summary request when nothing changed');

    board.draggingId = 7;
    await board.drop('in_progress');
    await new Promise((resolve) => setTimeout(resolve));
    await new Promise((resolve) => setTimeout(resolve));
    assert.equal(monitoringRequests(requests).length, 2, 'the summary is reloaded after the status change');
    assert.equal(board.summary.on_process, 1);
    assert.equal(board.summary.todo, 0);
});

test('Overdue marks on the cards use the same today as the KPI: the backend as_of, the browser date only before it loads', async () => {
    let asOf = '2020-01-01';
    const late = task({ status: 'todo', deadline: '2020-01-02' });
    const { board } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [late], meta: { status_counts: {} } } }),
        'GET /api/tasks/monitoring': () => ({ json: { data: { ...monitoringOf({ total_tasks: 1, todo: 1, unfinished: 1 }), as_of: asOf } } }),
    }, ADMIN);

    assert.equal(board.isOverdue(late), true, 'before the summary loads, the browser date is used');
    await board.load();
    assert.equal(board.isOverdue(late), false, 'on the backend date the deadline has not passed, as in the Terlambat KPI');

    asOf = '2020-01-03';
    await board.loadSummary();
    assert.equal(board.isOverdue(late), true);
    assert.equal(board.isOverdue({ ...late, status: 'done' }), false);
});

test('Removing the PIC or the division reloads the summary, while an update without summary fields does not', async () => {
    const { board, requests } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task()], meta: { status_counts: {} } } }),
        'GET /api/tasks/monitoring': () => ({ json: { data: monitoringOf({ total_tasks: 1 }) } }),
    }, ADMIN);
    await board.load();
    const tick = () => new Promise((resolve) => setTimeout(resolve));

    board.syncTask({ id: 7, evidence_count: 1 });
    await tick();
    assert.equal(monitoringRequests(requests).length, 1, 'fields the summary does not use change nothing');

    board.syncTask({ ...task(), pic: null });
    await tick();
    assert.equal(monitoringRequests(requests).length, 2, 'a PIC set to null is a change');

    board.syncTask({ ...task(), pic: null, division: null });
    await tick();
    assert.equal(monitoringRequests(requests).length, 3, 'a division set to null is a change');
});

test('The count of every status is in the summary in every view, zero included', async () => {
    const { board } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task()], meta: { status_counts: {} } } }),
        'GET /api/tasks/monitoring': () => ({ json: { data: monitoringOf({ total_tasks: 9, backlog: 0, todo: 2, on_process: 3, ready_for_qa: 0, on_qa: 1, completed: 3, unfinished: 6 }) } }),
    }, ADMIN);
    await board.load();

    const expected = [['Back Log', 0], ['To Do', 2], ['On Process', 3], ['Ready for QA', 0], ['On QA', 1], ['Done', 3]];
    for (const view of ['board', 'table', 'timeline', 'calendar']) {
        board.view = view;
        assert.deepEqual([...board.statusSummary].map(({ label, value }) => [label, value]), expected, `status counts in the ${view} view`);
    }
});

test('While a new filter is loading the summary shows — instead of the previous filter numbers, then the new numbers', async () => {
    const pending = [];
    const { board } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task()], meta: { status_counts: {} } } }),
        'GET /api/tasks/monitoring': () => (pending.length || board.filters.division === ''
            ? { json: { data: monitoringOf({ total_tasks: 100, todo: 40, on_process: 20, completed: 40, unfinished: 60, overdue: 5, completion_rate: 40 }, 'Seluruh divisi') } }
            : new Promise((resolve) => pending.push(resolve))),
    }, ADMIN);
    board.divisions = [{ code_division: 'SLS', name: 'Sales' }];
    await board.load();
    assert.equal(board.metric('total_tasks'), 100);

    // Divisi Sales is chosen; the server has not answered yet.
    board.filters.division = 'SLS';
    const loading = board.load();
    await new Promise((resolve) => setTimeout(resolve));
    assert.equal(board.scopeLabel, 'Divisi Sales');
    assert.equal(board.summaryState, 'loading');
    for (const key of ['total_tasks', 'on_process', 'overdue', 'due_today', 'completed', 'unfinished', 'urgent']) {
        assert.equal(board.metric(key), '—', `${key} hides the number of the previous filter`);
    }
    assert.equal(board.completionRateText, '—');
    assert.ok(board.statusSummary.every(({ value }) => value === '—'));

    // Switching the view while it loads keeps the summary hidden too.
    board.view = 'table';
    assert.equal(board.metric('total_tasks'), '—');

    pending[0]({ json: { data: monitoringOf({ total_tasks: 7, todo: 4, on_process: 3, unfinished: 7, completion_rate: 0 }) } });
    await loading;
    assert.equal(board.metric('total_tasks'), 7);
    assert.equal(board.completionRateText, '0%');
    assert.deepEqual([...board.statusSummary].map(({ value }) => value), [0, 4, 3, 0, 0, 0]);
});

test('With the default config, the PIC sends a file and a link as evidence and the saved evidence shows again in the detail', async () => {
    const saved = [];
    const evidence = (id, fields) => ({
        id,
        task_id: 7,
        type: fields.file ? 'file' : 'link',
        file_name: fields.file?.name ?? null,
        file_url: fields.file ? `/api/tasks/7/evidences/${id}/download` : null,
        url: fields.url ?? null,
        note: fields.note ?? null,
        submitter: { id: PIC.id, fullname: 'Staff Marketing' },
        created_at: '2026-11-11T02:00:00Z',
    });
    const detail = () => task({
        status: 'in_progress',
        evidence_required: true,
        evidence_count: saved.length,
        evidence: [...saved].reverse(),
        history: saved.map((item) => ({ id: item.id, action: 'evidence_submitted', action_label: 'Evidence dikirim', description: `Evidence: ${item.file_name ?? item.url}`, changes: {}, actor: null, created_at: item.created_at })),
    });
    const { board, detail: createDetail, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task({ status: 'in_progress', evidence_required: true })], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: detail() } }),
        'POST /api/tasks/7/evidences': (form) => {
            const created = evidence(saved.length + 1, form.fields);
            saved.push(created);
            return { status: 201, json: { data: created, message: 'Bukti tugas berhasil dikirim.' } };
        },
    });
    await board.load();
    board.detailId = 7;
    const panel = createDetail();
    await panel.open(7);
    assert.equal(panel.canSubmitEvidence, true);
    assert.equal(panel.evidences.length, 0);

    const file = { name: 'desain-final.pdf', size: 1024 };
    panel.evidenceForm = { type: 'file', file, url: '', note: 'Versi final' };
    await panel.sendEvidence();

    panel.evidenceForm = { type: 'link', file: null, url: ' https://drive.example.com/banner ', note: '' };
    await panel.sendEvidence();

    const posts = requests.filter(({ method, url }) => method === 'POST' && url === '/api/tasks/7/evidences');
    assert.equal(posts.length, 2, 'evidence is sent although VITE_TASK_API_READY is false');
    assert.deepEqual({ ...posts[0].body.fields }, { file, note: 'Versi final' });
    assert.deepEqual({ ...posts[1].body.fields }, { url: 'https://drive.example.com/banner' });
    assert.equal(posts[0].headers['X-CSRF-TOKEN'], 'csrf-test');
    assert.equal(toasts.at(-1).title, 'Evidence terkirim');

    // After each submission the detail is fetched again: evidence and history come from the backend.
    assert.deepEqual([...panel.evidences].map(({ id, type }) => [id, type]), [[2, 'link'], [1, 'file']]);
    assert.deepEqual([...panel.activities].map(({ description }) => description), ['Evidence: desain-final.pdf', 'Evidence: https://drive.example.com/banner']);
    assert.equal(board.tasks[0].evidence_count, 2);

    // Opening the detail again shows the saved evidence.
    const reopened = createDetail();
    await reopened.open(7);
    assert.deepEqual([...reopened.evidences].map((item) => item.file_name ?? item.url), ['https://drive.example.com/banner', 'desain-final.pdf']);
    assert.equal(reopened.evidences[1].file_url, '/api/tasks/7/evidences/1/download');
});

test('Evidence uploaded but the next detail request fails: the history shows a clear message and Coba lagi brings the activity back', async () => {
    const created = activityOf(1, 'created', 'Tugas dibuat', 'Membuat tugas.');
    const submitted = activityOf(2, 'evidence_submitted', 'Evidence dikirim', 'Evidence: desain-final.pdf', { id: PIC.id, fullname: 'Staff Marketing' }, '2026-11-11T02:00:00Z');
    let history = [created];
    let detailFails = false;
    const { board, detail: createDetail, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task({ status: 'in_progress' })], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => (detailFails ? { status: 500, json: { message: 'Server Error' } } : { json: { data: task({ status: 'in_progress', evidence: [], history }) } }),
        'GET /api/tasks/7/activities': () => ({ json: { data: history } }),
        'POST /api/tasks/7/evidences': () => {
            history = [submitted, created];
            detailFails = true;
            return { status: 201, json: { data: { id: 1, task_id: 7, type: 'file', file_name: 'desain-final.pdf', file_url: '/api/tasks/7/evidences/1/download', url: null, note: null, submitter: { id: PIC.id, fullname: 'Staff Marketing' }, created_at: submitted.created_at }, message: 'Bukti tugas berhasil dikirim.' } };
        },
    });
    await board.load();
    board.detailId = 7;
    const detail = createDetail();
    await detail.open(7);
    assert.deepEqual([...detail.activities].map(({ id }) => id), [1]);

    detail.evidenceForm = { type: 'file', file: { name: 'desain-final.pdf', size: 1024 }, url: '', note: '' };
    await detail.sendEvidence();

    // The upload succeeded: the evidence shows, but the history could not be refreshed and says so.
    assert.equal(toasts.at(-1).title, 'Evidence terkirim');
    assert.deepEqual([...detail.evidences].map(({ file_name }) => file_name), ['desain-final.pdf']);
    assert.equal(requests.filter(({ url }) => url === '/api/tasks/7').length, 2, 'the detail was requested again after the upload');
    assert.equal(detail.activityState, 'error');
    assert.equal(detail.activityError, 'Evidence tersimpan, tetapi histori aktivitas gagal diperbarui.');
    assert.equal(detail.state, 'ready', 'the detail stays open');

    // Coba lagi on the history section loads the history again, now with the evidence activity.
    await detail.loadActivities();
    assert.equal(detail.activityState, 'ready');
    assert.equal(detail.activityError, '');
    assert.deepEqual([...detail.activities].map(({ action_label }) => action_label), ['Evidence dikirim', 'Tugas dibuat']);
});

test('Evidence rejected by the backend shows the reason in the form and keeps the list unchanged', async () => {
    let reply = { status: 422, json: { message: 'Data tidak valid.', errors: { url: ['Tautan harus berupa URL yang valid dan diawali http:// atau https://.'] } } };
    const { board, detail: createDetail, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task()], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: task({ evidence: [], history: [] }) } }),
        'POST /api/tasks/7/evidences': () => reply,
    });
    await board.load();
    board.detailId = 7;
    const panel = createDetail();
    await panel.open(7);

    panel.evidenceForm = { type: 'link', file: null, url: 'https://contoh.test/a', note: '' };
    await panel.sendEvidence();
    assert.equal(panel.evidenceErrors.url, 'Tautan harus berupa URL yang valid dan diawali http:// atau https://.');

    reply = { status: 422, json: { message: 'Data tidak valid.', errors: { evidence: ['Evidence hanya dapat dikirim saat tugas berstatus To Do atau On Process, bukan Done.'] } } };
    await panel.sendEvidence();
    assert.equal(panel.evidenceErrors.evidence, 'Evidence hanya dapat dikirim saat tugas berstatus To Do atau On Process, bukan Done.');

    reply = { status: 403, json: { message: 'This action is unauthorized.' } };
    await panel.sendEvidence();
    assert.equal(toasts.at(-1).text, 'Anda tidak memiliki akses untuk tindakan ini.');

    assert.equal(panel.evidences.length, 0);
    assert.equal(panel.sendingEvidence, false);
});
