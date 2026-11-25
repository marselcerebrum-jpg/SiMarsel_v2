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
 * - PATCH /api/tasks/{id}/assignment (AssignTaskRequest: pic_ids, division_id, version) from the detail;
 * - POST /api/tasks/{id}/evidences (StoreTaskEvidenceRequest: file or url, note) with the default VITE_TASK_API_READY=false;
 * - GET /api/tasks/{id}/activities for the detail's history, with its loading, error, and empty states;
 * - GET /api/tasks/monitoring?scope=tasklist for the summary KPIs, with the same filters as the list;
 * - GET /api/tasks/schedule for the Timeline, with its own division filter across divisions;
 * - GET /api/tasks?scope=all_tasks|my_tasks and DELETE /api/tasks/{id} for the role-based Tasklist (TASK-12).
 * - GET /api/tasks/calendar for the Calendar, agendas across divisions and deadlines in the user's scope.
 */
const PIC = { id: 10, role: { code_role: 'EMP' }, division: { code_division: 'MKT' } };
const ADMIN = { id: 1, role: { code_role: 'ADM' }, division: null };

async function tasklistPage(routes, sessionUser = PIC, apiReady = 'false') {
    const requests = [];
    const toasts = [];
    const alerts = [];
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
        alert: (message) => alerts.push(message),
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

    return { board, detail, requests, toasts, alerts, instance: (name) => instance(components[name], board) };
}

const task = (overrides = {}) => ({
    id: 7,
    title: 'Konten promo November',
    division: { code_division: 'MKT', name: 'Marketing' },
    pics: [{ id: PIC.id, fullname: 'Staff Marketing' }],
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
    const reviewed = task({ status: 'on_review', requires_review: true, can_review: true });
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

test('Create button follows meta.can_create, falling back to Admin and Manager when meta is unavailable', async () => {
    const create = async (user) => (await tasklistPage({}, user)).board.canCreate;

    assert.equal(await create(ADMIN), true);
    assert.equal(await create({ id: 2, role: { code_role: 'MGR' }, division: { code_division: 'MKT' } }), true);
    assert.equal(await create({ id: 3, role: { code_role: 'MGR' }, division: null }), true, 'a Manager covers every division');
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
    const reviewed = task({ status: 'on_review', requires_review: true, can_review: true, version: 8 });
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
    divisions: [{ id: 3, code_division: 'MKT', name: 'Marketing' }, { id: 4, code_division: 'FIN', name: 'Finance' }],
    pics: [{ id: 10, fullname: 'Staff Marketing', role: 'EMP', division: { id: 3, code_division: 'MKT', name: 'Marketing' } }, { id: 12, fullname: 'Freelance Finance', role: 'FRL', division: { id: 4, code_division: 'FIN', name: 'Finance' } }],
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
            data: task({ id: 21, title: body.title, status: body.status, pics: [{ id: 10, fullname: 'Staff Marketing' }], requires_review: true, version: 1 }),
            message: 'Tugas berhasil dibuat.',
        },
    }));

    // <select x-model> yields strings, exactly as in the browser.
    Object.assign(form.form, { title: '  Banner promo November  ', description: '', category_id: '5', division_id: '3', platform_id: '', status: 'todo', deadline: '2026-11-30' });
    form.togglePic(10);
    form.togglePic(12);
    await form.submit();

    const post = requests.find(({ method }) => method === 'POST');
    assert.equal(post.url, '/api/tasks');
    assert.deepEqual(post.body, {
        title: 'Banner promo November', description: '', category_id: '5', division_id: '3', pic_ids: [10, 12], platform_id: '',
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
        json: { message: 'invalid', errors: { deadline: ['Deadline tidak boleh sebelum hari ini.'], 'pic_ids.0': ['PIC tidak valid.'] } },
    }));
    Object.assign(invalid.form.form, { title: 'A', division_id: '3' });
    await invalid.form.submit();
    assert.equal(invalid.form.errors.deadline, 'Deadline tidak boleh sebelum hari ini.');
    assert.equal(invalid.form.errors.pic_ids, 'PIC tidak valid.');
    assert.equal(invalid.board.createOpen, true);

    const duplicate = await createFormPage(() => ({
        status: 409,
        json: { message: 'Tugas yang sama baru saja dikirim. Periksa daftar tugas sebelum mengirim ulang.' },
    }));
    Object.assign(duplicate.form.form, { title: 'A', division_id: '3' });
    const listCalls = () => duplicate.requests.filter(({ url, method }) => url.startsWith('/api/tasks?') && method === 'GET').length;
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
    current_pics: [{ id: PIC.id, fullname: 'Staff Marketing' }],
    current_division: { id: 3, code_division: 'MKT', name: 'Marketing' },
    can_change_division: false,
    locked_reason: null,
    divisions: [{ id: 3, code_division: 'MKT', name: 'Marketing' }],
    pics: [{ id: PIC.id, fullname: 'Staff Marketing', role: 'EMP', division: null }, { id: 11, fullname: 'Staff Baru', role: 'FRL', division: null }],
};

test('Changing the PICs sends the complete { pic_ids, division_id, version } to the assignment endpoint and updates the detail and the Board', async () => {
    const { board, detail: createDetail, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task({ can_assign: true })], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: task({ can_assign: true, history: [] }) } }),
        'GET /api/tasks/7/assignment-options': () => ({ json: { data: assignmentOptions } }),
        'PATCH /api/tasks/7/assignment': () => ({ json: { data: task({ can_assign: true, pics: [{ id: 11, fullname: 'Staff Baru' }], version: 5 }), message: 'PIC berhasil diperbarui.' } }),
    }, MANAGER);
    await board.load();
    board.detailId = 7;
    const detail = createDetail();
    await detail.open(7);

    assert.equal(detail.canAssignThis, true);
    await detail.openAssign();
    assert.equal(detail.assigneeState, 'ready');
    assert.deepEqual([...detail.assignForm.pic_ids], [PIC.id], 'the active PICs start selected');
    assert.equal(detail.canSaveAssignment, false, 'nothing changed yet');

    detail.toggleAssignPic(11);
    assert.equal(detail.canSaveAssignment, true);
    await detail.saveAssign();

    const sent = requests.find((request) => request.method === 'PATCH');
    assert.equal(sent.url, '/api/tasks/7/assignment');
    assert.deepEqual(sent.body, { pic_ids: [PIC.id, 11], division_id: 3, version: 4 });
    assert.equal(detail.task.pics[0].fullname, 'Staff Baru');
    assert.equal(detail.task.version, 5);
    assert.equal(board.tasks[0].pics[0].fullname, 'Staff Baru');
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
            : { status: 422, json: { message: 'PIC tidak valid.', errors: { 'pic_ids.1': ['PIC harus Staff aktif dari divisi tugas.'] } } }),
    }, MANAGER);
    await board.load();
    board.detailId = 7;
    const detail = createDetail();
    await detail.open(7);

    await detail.openAssign();
    detail.toggleAssignPic(11);
    await detail.saveAssign();
    await new Promise((resolve) => setTimeout(resolve));
    assert.match(toasts.at(-1).text, /sudah diperbarui oleh pengguna lain/);
    assert.equal(detail.task.version, 6);
    assert.equal(board.tasks[0].version, 6);

    assignStatus = 422;
    await detail.openAssign();
    detail.toggleAssignPic(11);
    await detail.saveAssign();
    assert.equal(detail.assigneeState, 'error');
    assert.equal(detail.assignmentError, 'PIC harus Staff aktif dari divisi tugas.');
    assert.equal(detail.task.pics[0].id, PIC.id, 'a rejected assignment leaves the shown PIC unchanged');
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

    detail = task({ status: 'on_review', requires_review: true, can_review: false, next_statuses: [] });
    const notReviewer = createDetail();
    await notReviewer.open(7);
    assert.deepEqual([...notReviewer.transitions], [], 'QA buttons need can_review');

    detail = task({ status: 'on_review', requires_review: true, can_review: true, next_statuses: [] });
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
    let current = task({ status: 'on_review', requires_review: true, can_review: true, next_statuses: [], version: 8 });
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
    assert.deepEqual(board.tasksIn('on_review').map(({ id }) => id), [7]);
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
    current = { ...current, status: 'on_review', can_review: true };
    await board.load();
    board.draggingId = 7;
    await board.drop('done');
    assert.deepEqual(reviews()[1].body, { decision: 'approve', version: 9 });
    assert.deepEqual(board.tasksIn('done').map(({ id }) => id), [7]);
    assert.equal(board.tasks[0].version, 10);
    assert.equal(toasts.at(-1).text, 'Tugas berhasil disetujui.');

    // Detail: the Approve button opens the form; without a note it sends note null.
    current = { ...current, status: 'on_review', can_review: true };
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
    let current = task({ status: 'on_review', requires_review: true, can_review: true, next_statuses: [], version: 8 });
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
    assert.equal(detail.task.status, 'on_review');

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
    current = { ...current, status: 'on_review', can_review: true, version: 9 };
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
    assert.deepEqual(board.tasksIn('on_review').map(({ id }) => id), [7]);
    assert.equal(board.tasks[0].version, 10);

    // 403 from the Board: the backend message is shown and the card stays in On QA.
    reply = () => ({ status: 403, json: { message: 'This action is unauthorized.' } });
    board.draggingId = 7;
    await board.drop('done');
    assert.equal(toasts.at(-1).title, 'Gagal');
    assert.deepEqual(board.tasksIn('on_review').map(({ id }) => id), [7]);

    assert.equal(requests.some(({ url }) => url.endsWith('/status')), false);
});

test('Dragging On QA to On Process does not open the revision form when the backend does not send can_review', async () => {
    const reviewed = task({ status: 'on_review', requires_review: true, can_review: false, next_statuses: [] });
    const { board, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [reviewed], meta: { status_counts: {} } } }),
    }, ADMIN);
    await board.load();

    board.draggingId = 7;
    await board.drop('in_progress');

    assert.equal(board.detailId, null, 'the Board does not open the revision form');
    assert.equal(toasts.at(-1).title, 'Perpindahan ditolak');
    assert.deepEqual(board.tasksIn('on_review').map(({ id }) => id), [7]);
    assert.equal(requests.some(({ method }) => method !== 'GET'), false);
});

test('A revision dragged on a list that still allowed it is refused when the fresh detail no longer sends can_review', async () => {
    const listed = task({ status: 'on_review', requires_review: true, can_review: true, next_statuses: [] });
    const { board, detail: createDetail, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [listed], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: { ...listed, can_review: false } } }),
    }, ADMIN);
    await board.load();

    board.draggingId = 7;
    await board.drop('in_progress');
    const detail = createDetail();
    await detail.open(7);

    assert.equal(detail.reviewMode, null);
    assert.equal(toasts.at(-1).title, 'Revisi tidak dapat diminta');
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
    assert.deepEqual(gets.slice(-3), ['/api/tasks/7', '/api/tasks/7/activities', '/api/tasks/7/comments'], 'history and comments are fetched although VITE_TASK_API_READY is false');
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
    total_tasks: 0, backlog: 0, todo: 0, on_process: 0, ready_review: 0, on_review: 0,
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
    assert.equal(list.url, '/api/tasks?scope=all_tasks&search=promo&division=MKT&priority=urgent&status=todo', 'the list and the summary use the same filters');
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

const periodPage = (data = [], user = ADMIN) => tasklistPage({
    'GET /api/tasks': () => ({ json: { data, meta: { status_counts: {} } } }),
    'GET /api/tasks/monitoring': () => ({ json: { data: monitoringOf({}) } }),
}, user);

const choosePeriod = (board, values) => Object.assign(board.periodFilter, values);

test('Terapkan Periode sends only the active mode to the list and the KPIs, with the same filters', async () => {
    const { board, requests } = await periodPage();
    board.filters = { search: 'promo', division: 'MKT', priority: '', status: 'todo' };
    await board.load();

    choosePeriod(board, { mode: 'range', basis: 'start_date', startDate: '2026-11-20', endDate: '2026-11-10' });
    assert.equal(board.canConfirmPeriodFilter, false);
    const before = requests.length;
    await board.confirmPeriodFilter();
    assert.equal(requests.length, before, 'an invalid range is not sent');

    const cases = [
        [{ mode: 'date', basis: 'deadline', date: '2026-11-18' }, 'filter_mode=date&date_field=deadline&date=2026-11-18'],
        [{ mode: 'month', basis: 'deadline', month: '2026-02' }, 'filter_mode=month&date_field=deadline&month=2&year=2026'],
        [{ mode: 'year', basis: 'start_date', year: 2009 }, 'filter_mode=year&date_field=start_date&year=2009'],
        [{ mode: 'range', basis: 'start_date', startDate: '2026-11-10', endDate: '2026-11-20' }, 'filter_mode=range&date_field=start_date&start_date=2026-11-10&end_date=2026-11-20'],
    ];

    for (const [values, period] of cases) {
        choosePeriod(board, values);
        await board.confirmPeriodFilter();

        assert.equal(listRequests(requests).at(-1).url, `/api/tasks?scope=all_tasks&search=promo&division=MKT&status=todo&${period}`, values.mode);
        assert.equal(monitoringRequests(requests).at(-1).url, `/api/tasks/monitoring?scope=tasklist&search=promo&division=MKT&status=todo&${period}`, `${values.mode}: the KPIs use the same period`);
    }

    assert.equal(board.appliedPeriodLabel, 'Tanggal Mulai: 10 Nov 2026 – 20 Nov 2026');
    choosePeriod(board, { mode: 'month', basis: 'deadline', month: '2026-02' });
    await board.confirmPeriodFilter();
    assert.equal(board.appliedPeriodLabel, 'Deadline: Februari 2026', 'the month is read with its own year');
    assert.equal(board.hasActiveFilter, true);
});

test('The applied period stays across the Board, the Table, and Tugas Saya until it is removed or reset', async () => {
    const { board, requests } = await periodPage();
    await board.load();
    choosePeriod(board, { mode: 'year', basis: 'deadline', year: '2026' });
    await board.confirmPeriodFilter();

    board.view = 'table';
    board.taskScope = 'my_tasks';
    await board.load();
    assert.equal(listRequests(requests).at(-1).url, '/api/tasks?scope=my_tasks&filter_mode=year&date_field=deadline&year=2026');
    assert.equal(board.periodFilter.mode, 'year', 'the form keeps the period between views');

    // Editing the form without applying it does not change the data.
    choosePeriod(board, { mode: 'date', date: '2026-11-18' });
    await board.load();
    assert.equal(listRequests(requests).at(-1).url, '/api/tasks?scope=my_tasks&filter_mode=year&date_field=deadline&year=2026');

    // "Tanpa filter periode" removes the applied period.
    choosePeriod(board, { mode: '' });
    assert.equal(board.canConfirmPeriodFilter, true);
    await board.confirmPeriodFilter();
    assert.equal(board.appliedPeriod, null);
    assert.equal(listRequests(requests).at(-1).url, '/api/tasks?scope=my_tasks');
    assert.equal(board.canConfirmPeriodFilter, false, 'nothing left to remove');

    choosePeriod(board, { mode: 'date', date: '2026-11-18' });
    await board.confirmPeriodFilter();
    board.resetFilters();
    await board.load();
    assert.equal(board.appliedPeriod, null);
    assert.equal(board.hasPeriodConfiguration, false);
    assert.equal(listRequests(requests).at(-1).url, '/api/tasks?scope=my_tasks');
    assert.equal(monitoringRequests(requests).length > 0, true);
});

test('A month with a year below 1000, which the browser month input accepts, is explained in the form and not sent', async () => {
    const { board, requests } = await periodPage();
    await board.load();
    const before = requests.length;

    choosePeriod(board, { mode: 'month', month: '0999-11' });
    assert.equal(board.periodYearError, 'Masukkan tahun 4 digit, misalnya 2009.');
    assert.equal(board.canConfirmPeriodFilter, false);
    await board.confirmPeriodFilter();
    assert.equal(requests.length, before);

    choosePeriod(board, { month: '' });
    assert.equal(board.periodYearError, '', 'an empty month is not an error yet');
    assert.equal(board.canConfirmPeriodFilter, false);

    for (const month of ['1000-01', '2026-11', '9999-12']) {
        choosePeriod(board, { month });
        assert.equal(board.canConfirmPeriodFilter, true, month);
    }
});

test('A period rejected by the backend shows its reason in the period form', async () => {
    const { board } = await tasklistPage({
        'GET /api/tasks': () => ({ status: 422, json: { message: 'Tanggal akhir tidak boleh sebelum tanggal awal.', errors: { end_date: ['Tanggal akhir tidak boleh sebelum tanggal awal.'] } } }),
        'GET /api/tasks/monitoring': () => ({ json: { data: monitoringOf({}) } }),
    }, ADMIN);

    choosePeriod(board, { mode: 'range', basis: 'deadline', startDate: '2026-11-01', endDate: '2026-11-30' });
    await board.confirmPeriodFilter();

    assert.equal(board.status, 'error');
    assert.equal(board.periodError, 'Tanggal akhir tidak boleh sebelum tanggal awal.');
});

test('Cards outside the applied period or without its date are not shown, and each task is counted once', async () => {
    const inPeriod = task({ id: 1, status: 'todo', deadline: '2026-11-30', pics: [{ id: PIC.id, fullname: 'A' }, { id: 11, fullname: 'B' }] });
    const { board } = await periodPage([inPeriod]);
    choosePeriod(board, { mode: 'month', basis: 'deadline', month: '2026-11' });
    await board.confirmPeriodFilter();
    assert.deepEqual(board.tasksIn('todo').map(({ id }) => id), [1], 'a Multi-PIC task is one card');

    // A card changed or opened outside the list follows the period on the selected date.
    board.syncTask(task({ id: 2, status: 'todo', deadline: '2026-12-01' }));
    board.syncTask(task({ id: 3, status: 'todo', deadline: null }));
    board.syncTask({ ...inPeriod, deadline: '2026-11-01' });
    assert.deepEqual(board.tasksIn('todo').map(({ id }) => id), [1]);

    // The fixture tasks start on 1 Nov 2026, outside this one-day period.
    choosePeriod(board, { mode: 'date', basis: 'start_date', date: '2026-11-02' });
    await board.confirmPeriodFilter();
    board.syncTask(task({ id: 4, status: 'todo', start_date: '2026-11-02' }));
    board.syncTask(task({ id: 5, status: 'todo', start_date: null }));
    assert.deepEqual(board.tasksIn('todo').map(({ id }) => id), [4]);
});

test('Opening a task hidden by the period removes the period so its card can be shown', async () => {
    const { board, requests } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [], meta: { status_counts: {} } } }),
        'GET /api/tasks/monitoring': () => ({ json: { data: monitoringOf({}) } }),
        'GET /api/tasks/7': () => ({ json: { data: task({ deadline: '2027-01-05' }) } }),
    }, ADMIN);
    choosePeriod(board, { mode: 'month', basis: 'deadline', month: '2026-11' });
    await board.confirmPeriodFilter();

    board.focusTask({ id: 7 });
    await new Promise((resolve) => setTimeout(resolve));

    assert.equal(board.appliedPeriod, null);
    assert.equal(board.periodFilter.mode, '');
    assert.equal(listRequests(requests).at(-1).url.includes('filter_mode'), false);
});

test('Opening a Calendar task inside the applied period keeps the period', async () => {
    const { board } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [], meta: { status_counts: {} } } }),
        'GET /api/tasks/monitoring': () => ({ json: { data: monitoringOf({}) } }),
        'GET /api/tasks/7': () => ({ json: { data: task({ deadline: '2026-11-20' }) } }),
    }, ADMIN);
    choosePeriod(board, { mode: 'month', basis: 'deadline', month: '2026-11' });
    await board.confirmPeriodFilter();

    board.focusTask(task({ deadline: '2026-11-20' }));
    await new Promise((resolve) => setTimeout(resolve));

    assert.equal(board.appliedPeriodLabel, 'Deadline: November 2026');
});

test('Changing the start date of a task reloads the KPIs, which may count it by its start date', async () => {
    const listed = task({ id: 1, start_date: '2026-11-05' });
    const { board, requests } = await periodPage([listed]);
    choosePeriod(board, { mode: 'month', basis: 'start_date', month: '2026-11' });
    await board.confirmPeriodFilter();
    const before = monitoringRequests(requests).length;

    board.syncTask({ ...listed, start_date: '2026-12-05' });
    await new Promise((resolve) => setTimeout(resolve));

    assert.equal(monitoringRequests(requests).length, before + 1);
    assert.match(monitoringRequests(requests).at(-1).url, /filter_mode=month&date_field=start_date&month=11&year=2026/);
    assert.deepEqual(board.tasksIn('todo').map(({ id }) => id), [], 'the card leaves the Board together with the KPIs');
});

test('Atur Ulang Filter resets the period and every Tasklist filter in the Board and the Table, keeping Tugas Saya', async () => {
    const { board, requests } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [], meta: { status_counts: {} } } }),
        'GET /api/tasks/monitoring': () => ({ json: { data: monitoringOf({}) } }),
    }, ADMIN);

    for (const view of ['board', 'table']) {
        board.view = view;
        board.taskScope = 'my_tasks';
        board.filters = { search: 'promo', division: 'FIN', priority: 'urgent', status: 'todo' };
        board.periodFilter.mode = 'month';
        board.periodFilter.basis = 'start_date';
        board.periodFilter.month = '2026-11';
        board.sort = { key: 'priority', dir: 'desc' };
        assert.equal(board.hasActiveFilter, true, view);

        board.resetFilters();
        await board.load();

        assert.equal(board.hasActiveFilter, false, view);
        assert.equal(board.hasPeriodConfiguration, false, view);
        assert.deepEqual({ ...board.sort }, { key: 'deadline', dir: 'asc' }, view);
        assert.equal(board.taskScope, 'my_tasks', `${view}: the Semua Tugas / Tugas Saya tab is not a filter`);
        assert.equal(listRequests(requests).at(-1).url, '/api/tasks?scope=my_tasks', view);

        // Only a period configuration still enables the button.
        board.periodFilter.basis = 'start_date';
        assert.equal(board.hasActiveFilter || board.hasPeriodConfiguration, true, view);
        board.resetFilters();
        assert.equal(board.hasPeriodConfiguration, false, view);
    }
});

const PIC_OPTIONS = [{ id: PIC.id, fullname: 'Staff Marketing' }, { id: 11, fullname: 'Staff Baru' }];

const picPage = (list, user = ADMIN, meta = { data: { role: 'admin', divisions: [], pics: PIC_OPTIONS } }) => tasklistPage({
    'GET /api/tasks/meta': () => ({ json: meta }),
    'GET /api/tasks': (body, requests) => ({ json: { data: list(requests.at(-1).url), meta: { status_counts: {} } } }),
    'GET /api/tasks/monitoring': () => ({ json: { data: monitoringOf({ total_tasks: 1 }) } }),
}, user);

test('The PIC options come from meta, and the PIC combines with the period, division, priority, and status in the list and the KPIs', async () => {
    const { board, requests } = await picPage(() => []);
    await board.loadMeta();
    assert.deepEqual([...board.picOptions], PIC_OPTIONS, 'only the PICs the backend offers this account');

    board.filters = { search: '', division: 'MKT', priority: 'high', status: 'todo', pic: '11' };
    choosePeriod(board, { mode: 'month', basis: 'deadline', month: '2026-11' });
    await board.confirmPeriodFilter();

    const filters = 'division=MKT&priority=high&status=todo&pic_id=11&filter_mode=month&date_field=deadline&month=11&year=2026';
    assert.equal(listRequests(requests).at(-1).url, `/api/tasks?scope=all_tasks&${filters}`);
    assert.equal(monitoringRequests(requests).at(-1).url, `/api/tasks/monitoring?scope=tasklist&${filters}`, 'the KPIs count the same tasks');
    assert.equal(board.hasActiveFilter, true);

    // Without meta the filter has no options to offer and sends nothing.
    const { board: fallback } = await picPage(() => [], ADMIN, null);
    await fallback.loadMeta().catch(() => {});
    assert.deepEqual([...fallback.picOptions], []);
});

test('The PIC filter stays across the Board, the Table, and Tugas Saya, where only tasks of the signed-in PIC are shown, each once', async () => {
    const shared = task({ id: 1, status: 'todo', deadline: '2026-11-20', pics: PIC_OPTIONS });
    const othersOnly = task({ id: 2, status: 'todo', deadline: '2026-11-21', pics: [{ id: 11, fullname: 'Staff Baru' }] });
    const { board, requests } = await picPage((url) => (url.includes('my_tasks') ? [shared] : [shared, othersOnly]), PIC);
    board.monitoring = { as_of: '2026-11-16' };

    board.filters.pic = '11';
    choosePeriod(board, { mode: 'month', basis: 'deadline', month: '2026-11' });
    await board.confirmPeriodFilter();
    assert.deepEqual(board.tasksIn('todo').map(({ id }) => id), [1, 2], 'Semua Tugas: every task of that PIC');
    assert.equal(board.metric('total_tasks'), 1, 'Semua Tugas: the KPIs are the backend numbers for the same filters');

    board.view = 'table';
    assert.deepEqual([...board.pageRows].map(({ id }) => id), [1, 2], 'the Table shows the same tasks as the Board');

    board.taskScope = 'my_tasks';
    await board.load();
    assert.equal(listRequests(requests).at(-1).url, '/api/tasks?scope=my_tasks&pic_id=11&filter_mode=month&date_field=deadline&month=11&year=2026');
    assert.equal(board.filters.pic, '11');
    assert.equal(board.appliedPeriodLabel, 'Deadline: November 2026');

    // A task of the chosen PIC without the signed-in account as PIC stays out of Tugas Saya, even when synced in.
    board.syncTask(othersOnly);
    assert.deepEqual([...board.pageRows].map(({ id }) => id), [1], 'a Multi-PIC task is one row');
    board.view = 'board';
    assert.deepEqual(board.tasksIn('todo').map(({ id }) => id), [1]);
    assert.equal(board.metric('total_tasks'), 1, 'Tugas Saya counts the Multi-PIC task once');

    // Releasing the chosen PIC removes the card, together with the KPIs.
    board.syncTask({ ...shared, pics: [{ id: PIC.id, fullname: 'Staff Marketing' }] });
    assert.deepEqual(board.tasksIn('todo').map(({ id }) => id), []);
    assert.equal(board.metric('total_tasks'), 0);
});

test('Atur Ulang Filter clears the PIC together with the period and the other filters', async () => {
    const { board, requests } = await picPage(() => []);
    board.filters = { search: '', division: '', priority: '', status: '', pic: '11' };
    assert.equal(board.hasActiveFilter, true, 'a chosen PIC alone enables the button');
    choosePeriod(board, { mode: 'year', basis: 'deadline', year: '2026' });
    await board.confirmPeriodFilter();

    board.resetFilters();
    await board.load();

    assert.equal(board.filters.pic, '');
    assert.equal(board.hasActiveFilter, false);
    assert.equal(listRequests(requests).at(-1).url, '/api/tasks?scope=all_tasks');
    assert.equal(monitoringRequests(requests).at(-1).url, '/api/tasks/monitoring?scope=tasklist');
});

test('Opening a task hidden by the PIC filter removes the PIC so its card can be shown', async () => {
    const { board } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task({ id: 7, pics: [{ id: PIC.id, fullname: 'Staff Marketing' }] })], meta: { status_counts: {} } } }),
        'GET /api/tasks/monitoring': () => ({ json: { data: monitoringOf({}) } }),
        'GET /api/tasks/7': () => ({ json: { data: task() } }),
    }, ADMIN);
    await board.load();
    board.filters.pic = '11';

    board.focusTask({ id: 7 });

    assert.equal(board.filters.pic, '');
});

test('Period filter rejects a date with a year beyond four digits, whose text order would pass as a valid range', async () => {
    const { board } = await tasklistPage({}, ADMIN);

    board.periodFilter.mode = 'range';
    board.periodFilter.startDate = '12026-01-01';
    board.periodFilter.endDate = '2026-12-31';
    assert.equal(board.canConfirmPeriodFilter, false, 'year 12026 is after 2026 although its text sorts first');
    assert.equal(board.periodRangeError, 'Format tanggal tidak valid.');
    assert.equal(await board.confirmPeriodFilter(), undefined);
    assert.equal(board.appliedPeriod, null);

    board.periodFilter.startDate = '2026-12-31';
    board.periodFilter.endDate = '2026-01-01';
    assert.equal(board.periodRangeError, 'Tanggal akhir harus sama dengan atau setelah tanggal awal.');

    board.periodFilter.endDate = '2026-12-31';
    assert.equal(board.periodRangeError, '', 'a one-day range includes both ends');
    assert.equal(board.canConfirmPeriodFilter, true);

    board.periodFilter.mode = 'date';
    board.periodFilter.date = '12026-01-01';
    assert.equal(board.canConfirmPeriodFilter, false);
    board.periodFilter.date = '2026-11-18';
    assert.equal(board.canConfirmPeriodFilter, true);
});

test('Period filter accepts any four-digit year, including years older than the previous ten-year list', async () => {
    const { board, requests } = await periodPage();
    const currentYear = Number(board.periodFilter.year);

    board.periodFilter.mode = 'year';

    for (const year of [String(currentYear - 11), '2009', '1998', '1000', '9999', 1998]) {
        board.periodFilter.year = year;
        assert.equal(board.periodYearError, '', `${year} is a selectable year`);
        assert.equal(board.canConfirmPeriodFilter, true, `${year} can be confirmed`);
    }

    board.periodFilter.year = '1998';
    await board.confirmPeriodFilter();
    assert.equal(listRequests(requests).at(-1).url, '/api/tasks?scope=all_tasks&filter_mode=year&date_field=deadline&year=1998', 'an older year is sent as it is');

    for (const year of ['', '199', '0999', '12026', '-199', '1e3', '2026.5']) {
        board.periodFilter.year = year;
        assert.equal(board.periodYearError, 'Masukkan tahun 4 digit, misalnya 2009.', `"${year}" is rejected`);
        assert.equal(board.canConfirmPeriodFilter, false, `"${year}" cannot be confirmed`);
    }

    board.periodFilter.year = '1998';
    assert.equal(board.hasPeriodConfiguration, true, 'an older year can be reset');
    board.resetFilters();
    assert.equal(board.periodFilter.year, String(currentYear));

    board.periodFilter.year = currentYear;
    board.periodFilter.mode = '';
    assert.equal(board.hasPeriodConfiguration, false, 'a numeric input value for the current year is still the default');
    assert.equal(board.periodYearError, '', 'the year is only validated while the year mode is selected');
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

    board.syncTask({ ...task(), pics: [] });
    await tick();
    assert.equal(monitoringRequests(requests).length, 2, 'a PIC set to null is a change');

    board.syncTask({ ...task(), pics: [], division: null });
    await tick();
    assert.equal(monitoringRequests(requests).length, 3, 'a division set to null is a change');
});

test('The count of every status is in the summary in every view, zero included', async () => {
    const { board } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task()], meta: { status_counts: {} } } }),
        'GET /api/tasks/monitoring': () => ({ json: { data: monitoringOf({ total_tasks: 9, backlog: 0, todo: 2, on_process: 3, ready_review: 0, on_review: 1, completed: 3, unfinished: 6 }) } }),
    }, ADMIN);
    await board.load();

    const expected = [['Back Log', 0], ['To Do', 2], ['On Process', 3], ['Ready Review', 0], ['On Review', 1], ['Done', 3]];
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

const scheduleItem = (overrides = {}) => ({
    id: 7,
    type: 'task',
    title: 'Konten promo November',
    division: { code_division: 'MKT', name: 'Marketing' },
    target_divisions: [],
    start_date: '2026-11-17',
    end_date: '2026-11-19',
    status: 'in_progress',
    priority: 'high',
    task_id: 7,
    can_view_detail: true,
    ...overrides,
});
const divisionList = [{ id: 1, code_division: 'FIN', name: 'Finance' }, { id: 2, code_division: 'MKT', name: 'Marketing' }, { id: 3, code_division: 'SLS', name: 'Sales' }];
const scheduleRequests = (requests) => requests.filter(({ url }) => url.startsWith('/api/tasks/schedule'));

async function timelinePage(items, user = ADMIN, extraRoutes = {}) {
    const page = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [], meta: { status_counts: {} } } }),
        'GET /api/divisions': () => ({ json: { data: divisionList } }),
        'GET /api/tasks/schedule': () => ({ json: { data: typeof items === 'function' ? items() : items } }),
        ...extraRoutes,
    }, user);
    page.board.view = 'timeline';
    const timeline = page.instance('taskTimeline');
    timeline.start = '2026-11-16';

    return { ...page, timeline };
}

test('With the default config, the Timeline loads the shared schedule of the week and draws each item as a date range', async () => {
    const { timeline, requests } = await timelinePage([
        scheduleItem(),
        scheduleItem({ id: 8, title: 'Laporan bulanan', division: { code_division: 'FIN', name: 'Finance' }, start_date: null, end_date: '2026-11-20', status: 'todo', task_id: 8, can_view_detail: false }),
        scheduleItem({ id: 3, type: 'event', title: 'Rapat koordinasi', division: null, start_date: '2026-11-16', end_date: '2026-11-22', status: null, priority: null, task_id: null, can_view_detail: false }),
        scheduleItem({ id: 9, title: 'Tanpa tanggal', start_date: null, end_date: null }),
    ]);
    await timeline.loadPeriod();

    const sent = scheduleRequests(requests);
    assert.equal(sent.length, 1, 'the schedule is requested although VITE_TASK_API_READY is false');
    assert.equal(sent[0].url, '/api/tasks/schedule?from=2026-11-16&to=2026-11-22', 'Admin: Semua Divisi by default');
    assert.equal(timeline.state, 'ready');
    assert.equal(timeline.timelineScopeLabel, 'Semua Divisi');

    const rows = Object.fromEntries([...timeline.rows].map((row) => [row.title, row]));
    assert.deepEqual(Object.keys(rows).sort(), ['Konten promo November', 'Laporan bulanan', 'Rapat koordinasi']);
    assert.deepEqual([rows['Konten promo November'].left, rows['Konten promo November'].width], [`${(1 / 7) * 100}%`, `${(3 / 7) * 100}%`]);
    assert.equal(rows['Konten promo November'].division.name, 'Marketing');
    assert.equal(rows['Konten promo November'].status, 'in_progress');
    assert.equal(rows['Laporan bulanan'].partial, true, 'a task with only a deadline is one day on the deadline');
    assert.equal(rows['Laporan bulanan'].width, `${(1 / 7) * 100}%`);
    assert.equal(rows['Rapat koordinasi'].width, '100%');
    assert.equal(timeline.undatedCount, 1);

    // Two-week mode and the next period ask the backend for the new range.
    timeline.setMode('biweek');
    await new Promise((resolve) => setTimeout(resolve));
    assert.equal(scheduleRequests(requests).at(-1).url, '/api/tasks/schedule?from=2026-11-16&to=2026-11-29');
    timeline.shift(1);
    await new Promise((resolve) => setTimeout(resolve));
    assert.equal(scheduleRequests(requests).at(-1).url, '/api/tasks/schedule?from=2026-11-30&to=2026-12-13');
});

test('Every role sees the schedule across divisions and can focus a division without changing the Tasklist scope', async () => {
    // Manager: own division by default, can switch to Semua Divisi or another division.
    const manager = await timelinePage([scheduleItem()], MANAGER);
    manager.board.filters.division = 'MKT';
    await manager.timeline.loadPeriod();
    assert.equal(scheduleRequests(manager.requests).at(-1).url, '/api/tasks/schedule?from=2026-11-16&to=2026-11-22&division=MKT');
    assert.equal(manager.timeline.timelineScopeLabel, 'Divisi Marketing');
    manager.timeline.division = '';
    await manager.timeline.loadPeriod();
    assert.equal(scheduleRequests(manager.requests).at(-1).url, '/api/tasks/schedule?from=2026-11-16&to=2026-11-22');
    manager.timeline.division = 'SLS';
    await manager.timeline.loadPeriod();
    assert.equal(scheduleRequests(manager.requests).at(-1).url, '/api/tasks/schedule?from=2026-11-16&to=2026-11-22&division=SLS');
    assert.equal(manager.timeline.timelineScopeLabel, 'Divisi Sales');
    assert.equal(manager.board.filters.division, 'MKT', 'the Board stays on the manager division');
    assert.deepEqual([...manager.timeline.timelineDivisionOptions].map(({ code_division }) => code_division), ['FIN', 'MKT', 'SLS'], 'every division can be chosen');

    // Staff: Semua Divisi by default and can focus any division.
    const staff = await timelinePage([scheduleItem({ division: { code_division: 'FIN', name: 'Finance' }, can_view_detail: false })], PIC);
    await staff.timeline.loadPeriod();
    assert.equal(scheduleRequests(staff.requests).at(-1).url, '/api/tasks/schedule?from=2026-11-16&to=2026-11-22');
    staff.timeline.division = 'FIN';
    await staff.timeline.loadPeriod();
    assert.equal(scheduleRequests(staff.requests).at(-1).url, '/api/tasks/schedule?from=2026-11-16&to=2026-11-22&division=FIN');
    assert.deepEqual([...staff.timeline.rows].map(({ title }) => title), ['Konten promo November'], 'tasks of another division are shown');

    // Before the division names load (or if they fail), the label still names the division that was requested.
    const early = await timelinePage([scheduleItem()], MANAGER, { 'GET /api/divisions': () => ({ status: 500, json: { message: 'Server Error' } }) });
    early.board.divisions = [];
    await early.timeline.loadPeriod();
    assert.equal(scheduleRequests(early.requests).at(-1).url, '/api/tasks/schedule?from=2026-11-16&to=2026-11-22&division=MKT');
    assert.equal(early.timeline.timelineScopeLabel, 'Divisi MKT', 'never Semua Divisi while a division is requested');
    early.timeline.division = '';
    assert.equal(early.timeline.timelineScopeLabel, 'Semua Divisi');

    // Search, priority, and status of the Tasklist still apply to tasks; agendas stay.
    const filtered = await timelinePage([scheduleItem(), scheduleItem({ id: 3, type: 'event', title: 'Rapat koordinasi', status: null, priority: null, task_id: null, can_view_detail: false })]);
    filtered.board.filters.status = 'done';
    await filtered.timeline.loadPeriod();
    assert.deepEqual([...filtered.timeline.rows].map(({ title }) => title), ['Rapat koordinasi']);
});

test('Choosing a schedule item opens the task detail only when the backend allows it, otherwise only the schedule information', async () => {
    const { board, timeline, toasts } = await timelinePage([]);

    timeline.select(scheduleItem({ id: 7, task_id: 7, can_view_detail: true }));
    assert.equal(board.detailId, 7);
    board.detailId = null;

    timeline.select(scheduleItem({ id: 3, type: 'event', title: 'Review kampanye', task_id: 12, can_view_detail: true }));
    assert.equal(board.detailId, 12, 'an agenda linked to a task opens that task');
    board.detailId = null;

    timeline.select(scheduleItem({ id: 8, title: 'Laporan bulanan', division: { code_division: 'FIN', name: 'Finance' }, can_view_detail: false }));
    assert.equal(board.detailId, null);
    assert.equal(toasts.at(-1).title, 'Laporan bulanan');
    assert.match(toasts.at(-1).text, /Finance · 17 Nov 2026 – 19 Nov 2026 · Detail hanya untuk pengguna berwenang\./);

    timeline.select(scheduleItem({ id: 4, type: 'event', title: 'Libur bersama', division: null, task_id: null, can_view_detail: false }));
    assert.equal(board.detailId, null);
    assert.match(toasts.at(-1).text, /^Lintas divisi · /);
});

test('The Timeline shows loading, empty, and error states, and ignores the response of an earlier period', async () => {
    let reply = () => ({ json: { data: [] } });
    const states = [];
    const { timeline } = await timelinePage([], ADMIN, {
        'GET /api/tasks/schedule': () => {
            states.push(timeline.state);
            return reply();
        },
    });
    await timeline.loadPeriod();
    assert.deepEqual(states, ['loading']);
    assert.equal(timeline.state, 'ready');
    assert.equal(timeline.rows.length, 0, 'empty state');

    reply = () => ({ status: 500, json: { message: 'Server Error' } });
    await timeline.loadPeriod();
    assert.equal(timeline.state, 'error');
    assert.equal(timeline.message, 'Jadwal gagal dimuat.');

    // The previous week answers after the next week: the next week stays on screen.
    let releaseOld;
    reply = () => new Promise((resolve) => {
        releaseOld = () => resolve({ json: { data: [scheduleItem({ title: 'Minggu lalu', start_date: '2026-11-09', end_date: '2026-11-10' })] } });
    });
    const older = timeline.loadPeriod();
    timeline.start = '2026-11-23';
    reply = () => ({ json: { data: [scheduleItem({ title: 'Minggu depan', start_date: '2026-11-24', end_date: '2026-11-25' })] } });
    await timeline.loadPeriod();
    releaseOld();
    await older;
    assert.equal(timeline.state, 'ready');
    assert.deepEqual([...timeline.rows].map(({ title }) => title), ['Minggu depan']);
});

const calendarEvent = (overrides = {}) => ({
    id: 3,
    title: 'Rapat koordinasi',
    division: { code_division: 'FIN', name: 'Finance' },
    target_divisions: [],
    start_date: '2026-11-17',
    end_date: '2026-11-18',
    description: 'Koordinasi akhir bulan',
    task_id: null,
    can_view_detail: false,
    ...overrides,
});
const calendarRequests = (requests) => requests.filter(({ url }) => url.startsWith('/api/tasks/calendar'));

async function calendarPage(reply, user = ADMIN) {
    const page = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [], meta: { status_counts: {} } } }),
        'GET /api/tasks/calendar': () => (typeof reply === 'function' ? reply() : { json: { data: reply } }),
    }, user);
    page.board.divisions = divisionList.map(({ code_division, name }) => ({ code_division, name }));
    page.board.monitoring = { as_of: '2026-11-16' };
    page.board.view = 'calendar';
    const calendar = page.instance('taskCalendar');
    calendar.month = '2026-11';
    calendar.selected = '2026-11-17';

    return { ...page, calendar };
}

test('With the default config, the Calendar loads agendas and deadlines of the month as two lists and marks the chosen date', async () => {
    const { calendar, requests } = await calendarPage({
        events: [calendarEvent(), calendarEvent({ id: 4, title: 'Agenda internal Sales', division: { code_division: 'SLS', name: 'Sales' }, target_divisions: [{ code_division: 'SLS', name: 'Sales' }], start_date: '2026-11-20', end_date: null })],
        deadlines: [
            { ...task({ deadline: '2026-11-17', status: 'in_progress' }), description: null, is_overdue: false },
            { ...task({ id: 8, title: 'Laporan bulanan', deadline: '2026-11-10', status: 'todo' }), description: null, is_overdue: true },
        ],
    });
    await calendar.loadPeriod();

    const sent = calendarRequests(requests);
    assert.equal(sent.length, 1, 'the calendar is requested although VITE_TASK_API_READY is false');
    assert.equal(sent[0].url, '/api/tasks/calendar?month=2026-11', 'Admin: Semua Divisi by default');
    assert.equal(calendar.state, 'ready');
    assert.equal(calendar.calendarScopeLabel, 'Semua Divisi');

    assert.deepEqual([...calendar.selectedEvents].map(({ title }) => title), ['Rapat koordinasi']);
    assert.deepEqual([...calendar.selectedDeadlines].map(({ title }) => title), ['Konten promo November']);
    const cell = (iso) => calendar.cells.find((item) => item.iso === iso);
    assert.deepEqual([cell('2026-11-18').events, cell('2026-11-18').deadlines], [1, 0], 'an agenda is marked on every day of its range');
    assert.equal(cell('2026-11-10').overdue, true, 'an active deadline that has passed is marked late');
    assert.equal(cell('2026-11-17').overdue, false);

    calendar.selected = '2026-11-19';
    assert.equal(calendar.selectedDeadlines.length, 0, 'a date without deadlines shows the empty message');

    // The Admin division filter is sent, and another month asks the backend again.
    calendar.filters.division = 'FIN';
    await calendar.loadPeriod();
    assert.equal(calendarRequests(requests).at(-1).url, '/api/tasks/calendar?month=2026-11&division=FIN');
    assert.equal(calendar.calendarScopeLabel, 'Divisi Finance');
    calendar.shift(1);
    await new Promise((resolve) => setTimeout(resolve));
    assert.equal(calendarRequests(requests).at(-1).url, '/api/tasks/calendar?month=2026-12&division=FIN');
    assert.equal(calendar.selected, '2026-12-01');
});

test('Every role sees agendas across divisions: the starting division is not sent, and a chosen division focuses the Calendar', async () => {
    const manager = await calendarPage({ events: [calendarEvent()], deadlines: [] }, MANAGER);
    manager.board.filters.division = 'MKT';
    await manager.calendar.loadPeriod();
    assert.equal(calendarRequests(manager.requests).at(-1).url, '/api/tasks/calendar?month=2026-11', 'shared agendas of other divisions are not filtered out');
    assert.equal(manager.calendar.calendarScopeLabel, 'Agenda lintas divisi · Deadline Divisi Marketing');
    assert.deepEqual([...manager.calendar.selectedEvents].map(({ title }) => title), ['Rapat koordinasi']);

    const staff = await calendarPage({ events: [calendarEvent()], deadlines: [] }, PIC);
    await staff.calendar.loadPeriod();
    assert.equal(calendarRequests(staff.requests).at(-1).url, '/api/tasks/calendar?month=2026-11');
    assert.equal(staff.calendar.calendarScopeLabel, 'Semua Divisi');
    staff.board.filters.division = 'FIN';
    await staff.calendar.loadPeriod();
    assert.equal(calendarRequests(staff.requests).at(-1).url, '/api/tasks/calendar?month=2026-11&division=FIN', 'Staff may focus any division');
    assert.equal(staff.calendar.calendarScopeLabel, 'Divisi Finance');
});

test('Calendar items open the task detail only when the backend allows it', async () => {
    const { board, calendar, toasts } = await calendarPage({ events: [], deadlines: [] });

    assert.equal(calendar.canOpenEventTask(calendarEvent({ task_id: 12, can_view_detail: true })), true);
    assert.equal(calendar.canOpenEventTask(calendarEvent({ task_id: 12, can_view_detail: false })), false, 'a linked task without access is not offered');
    assert.equal(calendar.canOpenEventTask(calendarEvent()), false, 'an agenda without a task has no detail');

    calendar.focusTask({ ...task({ id: 9 }), can_view_detail: false });
    assert.equal(board.detailId, null);
    assert.equal(toasts.at(-1).title, 'Akses terbatas');

    calendar.focusTask({ id: 12, can_view_detail: true });
    assert.equal(board.detailId, 12);
    assert.equal(board.view, 'board');
});

test('A day of another month shows no partial marks and choosing it opens that month from the backend', async () => {
    const { calendar, requests } = await calendarPage(() => ({ json: { data: {
        events: [calendarEvent({ title: 'Rapat akhir bulan', start_date: '2026-11-30', end_date: '2026-12-02' })],
        deadlines: calendarRequests(requests).at(-1).url.includes('2026-12')
            ? [{ ...task({ title: 'Laporan Desember', deadline: '2026-12-01' }), description: null, is_overdue: false }]
            : [],
    } } }));
    await calendar.loadPeriod();

    const cell = (iso) => calendar.cells.find((item) => item.iso === iso);
    assert.deepEqual([cell('2026-11-30').events, cell('2026-12-01').events, cell('2026-12-01').deadlines], [1, 0, 0], 'November data is not shown as the whole of 1 Dec');

    calendar.choose('2026-12-01');
    await new Promise((resolve) => setTimeout(resolve));
    assert.equal(calendarRequests(requests).at(-1).url, '/api/tasks/calendar?month=2026-12');
    assert.equal(calendar.month, '2026-12');
    assert.equal(calendar.selected, '2026-12-01');
    assert.deepEqual([...calendar.selectedDeadlines].map(({ title }) => title), ['Laporan Desember']);

    calendar.choose('2026-12-02');
    assert.equal(calendarRequests(requests).length, 2, 'a day of the shown month does not reload');
});

test('The summary of a chosen item follows the filters and the latest data of the month', async () => {
    let status = 'todo';
    const { calendar } = await calendarPage(() => ({ json: { data: {
        events: [],
        deadlines: [{ ...task({ deadline: '2026-11-17', status }), description: null, is_overdue: false }],
    } } }));
    await calendar.loadPeriod();
    calendar.pick('task', calendar.selectedDeadlines[0]);
    assert.equal(calendar.pickedVisible, true);

    calendar.filters.status = 'done';
    assert.equal(calendar.pickedVisible, false, 'a task hidden by the filter has no summary');
    calendar.filters.status = '';

    status = 'in_progress';
    await calendar.loadPeriod();
    assert.equal(calendar.pickedVisible, true);
    assert.equal(calendar.picked.item.status, 'in_progress', 'the summary shows the reloaded task');
});

test('The Calendar shows loading and error states, and ignores the response of an earlier month', async () => {
    let reply = () => ({ json: { data: { events: [], deadlines: [] } } });
    const states = [];
    const { calendar } = await calendarPage(() => {
        states.push(calendar.state);
        return reply();
    });
    await calendar.loadPeriod();
    assert.deepEqual(states, ['loading']);
    assert.equal(calendar.state, 'ready');

    reply = () => ({ status: 500, json: { message: 'Server Error' } });
    await calendar.loadPeriod();
    assert.equal(calendar.state, 'error');
    assert.equal(calendar.message, 'Kalender gagal dimuat.');

    // November answers after December: December stays on screen.
    let releaseOld;
    reply = () => new Promise((resolve) => {
        releaseOld = () => resolve({ json: { data: { events: [calendarEvent({ title: 'Agenda November' })], deadlines: [] } } });
    });
    const older = calendar.loadPeriod();
    calendar.month = '2026-12';
    calendar.selected = '2026-12-01';
    reply = () => ({ json: { data: { events: [calendarEvent({ title: 'Agenda Desember', start_date: '2026-12-01', end_date: null })], deadlines: [] } } });
    await calendar.loadPeriod();
    releaseOld();
    await older;
    assert.equal(calendar.state, 'ready');
    assert.deepEqual([...calendar.events].map(({ title }) => title), ['Agenda Desember']);
});

// --- TASK-12: role, Semua Tugas / Tugas Saya, dan tampilan hanya-lihat ---------------------------

const SUPERVISOR = { id: 30, role: { code_role: 'SPV' }, division: { code_division: 'MKT' } };
const FREELANCE = { id: 40, role: { code_role: 'FRL' }, division: { code_division: 'MKT' } };
const metaOf = (role, role_code, division, default_division = null) => ({
    role, role_code, division, default_division, can_create: ['ADM', 'MGR', 'SPV'].includes(role_code), permissions: [],
    divisions: [{ code_division: 'FIN', name: 'Finance' }, { code_division: 'MKT', name: 'Marketing' }],
    statuses: [], priorities: [],
});
const readOnly = { can_edit: false, can_assign: false, can_delete: false };
const listRequests = (requests) => requests.filter(({ method, url }) => method === 'GET' && (url === '/api/tasks' || url.startsWith('/api/tasks?')));

test('All five roles are named from the backend role code and every role can choose any division', async () => {
    const expected = [
        [ADMIN, metaOf('admin', 'ADM', null), 'Admin'],
        [MANAGER, metaOf('manager', 'MGR', 'MKT', 'MKT'), 'Manajer'],
        [SUPERVISOR, metaOf('staff', 'SPV', 'MKT'), 'Supervisor'],
        [PIC, metaOf('staff', 'EMP', 'MKT'), 'Staff'],
        [FREELANCE, metaOf('staff', 'FRL', 'MKT'), 'Freelance'],
    ];

    for (const [user, meta, label] of expected) {
        const { board } = await tasklistPage({ 'GET /api/tasks/meta': () => ({ json: { data: meta } }) }, user);
        await board.loadMeta();
        assert.equal(board.roleAccess.label, label);
        assert.deepEqual([...board.divisionOptions].map(({ code_division }) => code_division), ['FIN', 'MKT'], `${label} can filter every division`);
        assert.equal(board.filters.division, meta.default_division ?? '', 'the starting division follows the backend');
    }
});

test('Edit and PIC actions follow the management scope: a Supervisor only for their division, Staff and Freelance never', async () => {
    const own = task({ can_edit: true });
    const other = task({ division: { code_division: 'FIN', name: 'Finance' }, can_edit: true });
    const canEdit = async (user, item) => (await tasklistPage({}, user)).board.canEdit(item);

    assert.equal(await canEdit(MANAGER, other), true, 'a Manager covers every division');
    assert.equal(await canEdit(SUPERVISOR, own), true);
    assert.equal(await canEdit(SUPERVISOR, other), false);
    assert.equal(await canEdit(PIC, own), false, 'a PIC only moves the status');
    assert.equal(await canEdit(FREELANCE, own), false);
    assert.equal(await canEdit(ADMIN, { ...own, can_edit: false }), false, 'the backend flag always wins');
});

test('Tugas Saya asks the backend for my_tasks, keeps the filters, counts each task once, and drops a released PIC', async () => {
    const mine = task({ id: 1, pics: [{ id: PIC.id, fullname: 'Staff Marketing' }, { id: 11, fullname: 'Staff Baru' }], status: 'todo', priority: 'urgent', deadline: '2026-11-10' });
    const done = task({ id: 2, status: 'done', deadline: '2026-11-10' });
    const { board, requests } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: board.taskScope === 'my_tasks' ? [mine, done] : [mine, done, task({ id: 3, pics: [] })], meta: { status_counts: {} } } }),
        'GET /api/tasks/monitoring': () => ({ json: { data: monitoringOf({ total_tasks: 99 }) } }),
    }, PIC);
    board.monitoring = { as_of: '2026-11-16' };

    await board.load();
    assert.equal(listRequests(requests).at(-1).url, '/api/tasks?scope=all_tasks');
    assert.equal(board.filteredTasks.length, 3, 'Semua Tugas shows every task');
    assert.equal(board.metric('total_tasks'), 99);

    board.filters.division = 'MKT';
    board.taskScope = 'my_tasks';
    const monitoringBefore = monitoringRequests(requests).length;
    await board.load();
    assert.equal(listRequests(requests).at(-1).url, '/api/tasks?scope=my_tasks&division=MKT', 'the active filters stay');
    assert.equal(monitoringRequests(requests).length, monitoringBefore, 'monitoring has no my_tasks scope');
    assert.deepEqual([...board.filteredTasks].map(({ id }) => id), [1, 2], 'a task with several PICs appears once');
    assert.deepEqual(['total_tasks', 'todo', 'completed', 'unfinished', 'overdue', 'urgent'].map((key) => board.metric(key)), [2, 1, 1, 1, 1, 1]);
    assert.equal(board.completionRateText, '50%');
    assert.equal(board.scopeLabel, 'Tugas Saya · Divisi MKT');

    board.syncTask({ ...mine, pics: [{ id: 11, fullname: 'Staff Baru' }] });
    assert.deepEqual([...board.filteredTasks].map(({ id }) => id), [2], 'a released PIC no longer sees the task in Tugas Saya');
});

test('Tasks outside the account authority are read-only: no drag, a clear mark, and every active PIC can still move the task', async () => {
    const { board } = await tasklistPage({}, PIC);
    const outside = task({ pics: [{ id: 11, fullname: 'Staff Baru' }], ...readOnly });
    const shared = task({ pics: [{ id: 11, fullname: 'Staff Baru' }, { id: PIC.id, fullname: 'Staff Marketing' }], can_edit: true, can_assign: false, can_delete: false });

    assert.equal(board.isReadOnly(outside), true);
    assert.equal(board.canDrag(outside), false);
    assert.equal(board.isReadOnly(shared), false);
    assert.equal(board.canDrag(shared), true, 'each active PIC of a Multi-PIC task runs the shared workflow');
    assert.equal(board.isReadOnly({ ...outside, status: 'done' }), false, 'Done tasks need no mark');
    assert.equal(board.picNames(shared), 'Staff Baru, Staff Marketing');
});

test('An active PIC without the status permission cannot drag the card, and a drop sends no request', async () => {
    const revoked = task({ can_edit: false, can_change_status: false, can_assign: false, can_delete: false });
    const { board, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [revoked], meta: { status_counts: {} } } }),
    }, PIC);
    await board.load();

    assert.equal(board.canDrag(revoked), false, 'still the active PIC, but the backend does not allow the status change');
    assert.equal(board.canDrag({ ...revoked, can_change_status: true }), true);

    board.draggingId = 7;
    assert.equal(board.dropState('in_progress'), 'denied');
    assert.equal(board.dropReason('in_progress'), 'Anda tidak memiliki izin untuk mengubah status tugas ini.');

    await board.drop('in_progress');
    assert.deepEqual(statusRequests(requests), []);
    assert.equal(toasts.at(-1).title, 'Perpindahan ditolak');
    assert.deepEqual(board.tasksIn('todo').map(({ id }) => id), [7]);
});

test('A Manager or Supervisor who sees an On QA task without the review permission cannot drag it to Approve or Revision, and no review is sent', async () => {
    for (const user of [MANAGER, SUPERVISOR]) {
        const reviewed = task({ status: 'on_review', requires_review: true, can_edit: true, can_change_status: false, can_review: false });
        const { board, requests, toasts } = await tasklistPage({
            'GET /api/tasks': () => ({ json: { data: [reviewed], meta: { status_counts: {} } } }),
        }, user);
        await board.load();

        assert.equal(board.canDrag(reviewed), false, `${user.role.code_role}: no QA decision is offered on the Board`);
        assert.equal(board.canDrag({ ...reviewed, can_review: true }), true, `${user.role.code_role}: the review permission allows the drag`);

        board.draggingId = 7;
        for (const status of ['done', 'in_progress']) {
            assert.equal(board.dropState(status), 'denied');
            assert.equal(board.dropReason(status), 'Anda tidak memiliki izin untuk memberi keputusan QA pada tugas ini.');
        }

        await board.drop('done');
        board.draggingId = 7;
        await board.drop('in_progress');

        assert.equal(requests.some(({ url }) => url.includes('/reviews')), false, `${user.role.code_role}: no review request`);
        assert.equal(requests.some(({ method }) => method !== 'GET'), false);
        assert.equal(board.detailId, null, 'the revision form is not opened');
        assert.equal(toasts.at(-1).title, 'Perpindahan ditolak');
        assert.deepEqual(board.tasksIn('on_review').map(({ id }) => id), [7]);
    }
});

test('The detail explains a read-only task, and a PIC keeps evidence access while no longer read-only', async () => {
    let current = task({ pics: [{ id: 11, fullname: 'Staff Baru' }], ...readOnly, can_review: false, editable_fields: [], next_statuses: [] });
    const { board, detail: createDetail } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [current], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: current } }),
        'GET /api/tasks/7/activities': () => ({ json: { data: [] } }),
    }, PIC);
    board.detailId = 7;
    const detail = createDetail();
    await detail.open(7);
    assert.equal(detail.isReadOnlyThis, true);
    assert.equal(detail.canAssignThis, false);

    current = { ...current, pics: [{ id: PIC.id, fullname: 'Staff Marketing' }], editable_fields: ['status'] };
    await detail.open(7);
    assert.equal(detail.isReadOnlyThis, false);
    assert.equal(detail.canSubmitEvidence, true);
});

test('Deleting sends the version, removes the card, and a rejected delete keeps the task with the server reason', async () => {
    let reply = () => ({ json: { message: 'Tugas berhasil dihapus.' } });
    const { board, detail: createDetail, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task({ can_delete: true })], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: task({ can_delete: true }) } }),
        'GET /api/tasks/7/activities': () => ({ json: { data: [] } }),
        'GET /api/tasks/monitoring': () => ({ json: { data: monitoringOf({}) } }),
        'DELETE /api/tasks/7': () => reply(),
    }, ADMIN);
    await board.load();
    board.detailId = 7;
    const detail = createDetail();
    await detail.open(7);

    reply = () => ({ status: 422, json: { message: 'invalid', errors: { status: ['Tugas yang sudah selesai tidak dapat dihapus.'] } } });
    detail.confirmingDelete = true;
    await detail.removeTask();
    assert.equal(toasts.at(-1).text, 'Tugas yang sudah selesai tidak dapat dihapus.');
    assert.equal(detail.confirmingDelete, false);
    assert.equal(board.tasks.length, 1);

    reply = () => ({ json: { message: 'Tugas berhasil dihapus.' } });
    await detail.removeTask();
    assert.deepEqual(requests.filter(({ method }) => method === 'DELETE').at(-1).body, { version: 4 });
    assert.equal(board.tasks.length, 0);
    assert.equal(board.detailId, null);
    assert.equal(toasts.at(-1).title, 'Tugas dihapus');

    // Without the backend flag nothing is sent.
    const before = requests.filter(({ method }) => method === 'DELETE').length;
    detail.task = task({ can_delete: false });
    await detail.removeTask();
    assert.equal(requests.filter(({ method }) => method === 'DELETE').length, before);
});

test('The PIC list may only be empty in Back Log', async () => {
    const { board, detail: createDetail } = await tasklistPage({
        'GET /api/tasks/7': () => ({ json: { data: task({ can_assign: true, status: 'todo' }) } }),
        'GET /api/tasks/7/activities': () => ({ json: { data: [] } }),
        'GET /api/tasks/7/assignment-options': () => ({ json: { data: assignmentOptions } }),
    }, MANAGER);
    board.detailId = 7;
    const detail = createDetail();
    await detail.open(7);
    await detail.openAssign();

    detail.toggleAssignPic(PIC.id);
    assert.equal(detail.canSaveAssignment, false, 'To Do needs at least one PIC');
    detail.task = { ...detail.task, status: 'backlog' };
    assert.equal(detail.canSaveAssignment, true);
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

// --- Hapus evidence sendiri (TASK-13) -----------------------------------------------------------

const ownEvidence = (id, name, overrides = {}) => ({
    id,
    task_id: 7,
    type: 'file',
    file_name: name,
    file_url: `/api/tasks/7/evidences/${id}/download`,
    url: null,
    note: null,
    submitter: { id: PIC.id, fullname: 'Staff Marketing' },
    can_delete: true,
    created_at: '2026-11-11T02:00:00Z',
    ...overrides,
});

test('A PIC deletes their own evidence after confirming: DELETE is sent, the list, history, and Board count follow the backend', async () => {
    let evidence = [
        ownEvidence(2, 'banner-v2.png'),
        ownEvidence(1, 'brief-pic-lain.pdf', { submitter: { id: 11, fullname: 'Staff Baru' }, can_delete: false }),
    ];
    let history = [];
    const { board, detail: createDetail, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task({ status: 'in_progress', evidence_count: 2 })], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: task({ status: 'in_progress', evidence, evidence_count: evidence.length, history }) } }),
        'GET /api/tasks/7/activities': () => ({ json: { data: history } }),
        'DELETE /api/tasks/7/evidences/2': () => {
            evidence = evidence.filter(({ id }) => id !== 2);
            history = [{ id: 9, action: 'evidence_deleted', action_label: 'Evidence dihapus', description: 'Evidence: banner-v2.png', changes: {}, actor: { id: PIC.id, fullname: 'Staff Marketing' }, created_at: '2026-11-12T02:00:00Z' }];
            return { json: { message: 'Evidence berhasil dihapus.' } };
        },
    });
    await board.load();
    board.detailId = 7;
    const panel = createDetail();
    await panel.open(7);

    // Evidence of another PIC has no delete action and sends nothing.
    await panel.removeEvidence(panel.evidences[1]);
    assert.equal(requests.some(({ method }) => method === 'DELETE'), false);

    panel.confirmingEvidenceId = 2;
    await panel.removeEvidence(panel.evidences[0]);

    const [deleted] = requests.filter(({ method }) => method === 'DELETE');
    assert.equal(deleted.url, '/api/tasks/7/evidences/2');
    assert.equal(deleted.body, undefined);
    assert.equal(deleted.headers['X-CSRF-TOKEN'], 'csrf-test');

    assert.deepEqual([...panel.evidences].map(({ id }) => id), [1], 'the evidence of the other PIC stays');
    assert.equal(board.tasks[0].evidence_count, 1);
    assert.equal(board.tasks[0].status, 'in_progress', 'the task and its status do not change');
    assert.deepEqual([...panel.activities].map(({ action_label }) => action_label), ['Evidence dihapus']);
    assert.equal(toasts.at(-1).title, 'Evidence dihapus');
    assert.equal(panel.confirmingEvidenceId, null);
    assert.equal(panel.deletingEvidenceId, null);
});

test('A rejected evidence deletion explains why and reloads the evidence with the current permissions', async () => {
    let reply;
    let evidence = [ownEvidence(2, 'banner-v2.png')];
    const { board, detail: createDetail, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task({ status: 'in_progress', evidence_count: 1 })], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: task({ status: 'in_progress', evidence, evidence_count: evidence.length, history: [] }) } }),
        'GET /api/tasks/7/activities': () => ({ json: { data: [] } }),
        'DELETE /api/tasks/7/evidences/2': () => reply,
    });
    await board.load();
    board.detailId = 7;
    const panel = createDetail();
    await panel.open(7);

    // The task was handed to QA meanwhile: the reason shows outside the hidden upload form and the flag is refreshed.
    reply = { status: 422, json: { message: 'Data tidak valid.', errors: { evidence: ['Evidence hanya dapat dihapus saat tugas berstatus To Do atau On Process, bukan Ready Review.'] } } };
    evidence = [ownEvidence(2, 'banner-v2.png', { can_delete: false })];
    await panel.removeEvidence(panel.evidences[0]);
    assert.equal(panel.evidenceErrors.evidence, 'Evidence hanya dapat dihapus saat tugas berstatus To Do atau On Process, bukan Ready Review.');
    assert.equal(panel.evidences[0].can_delete, false);
    assert.equal(board.tasks[0].evidence_count, 1);
    panel.reset();
    assert.deepEqual({ ...panel.evidenceErrors }, {}, 'the reason does not carry over to the next task opened');
    await panel.open(7);

    // The PIC was released meanwhile.
    evidence = [ownEvidence(2, 'banner-v2.png')];
    await panel.refreshDetail();
    reply = { status: 403, json: { message: 'This action is unauthorized.' } };
    await panel.removeEvidence(panel.evidences[0]);
    assert.equal(toasts.at(-1).text, 'Anda tidak memiliki akses untuk tindakan ini.');

    // Already deleted in another tab: the message names the evidence, not the task, and the list follows the backend.
    evidence = [ownEvidence(2, 'banner-v2.png')];
    await panel.refreshDetail();
    reply = { status: 404, json: { message: 'Not Found' } };
    evidence = [];
    await panel.removeEvidence(panel.evidences[0]);
    assert.equal(toasts.at(-1).text, 'Evidence tidak ditemukan atau sudah dihapus.');
    assert.deepEqual(panel.evidences, []);
    assert.equal(panel.deletingEvidenceId, null);
});

test('An evidence deletion that finishes after the detail was closed or moved on only updates its own card', async () => {
    let release;
    const { board, detail: createDetail, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task({ status: 'in_progress', evidence_count: 1 }), task({ id: 8, title: 'Tugas lain', status: 'in_progress', evidence_count: 3 })], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: task({ status: 'in_progress', evidence: [ownEvidence(2, 'banner-v2.png')], evidence_count: 1, history: [] }) } }),
        'GET /api/tasks/8': () => ({ json: { data: task({ id: 8, title: 'Tugas lain', status: 'in_progress', evidence: [], evidence_count: 3, history: [] }) } }),
        'GET /api/tasks/7/activities': () => ({ json: { data: [] } }),
        'GET /api/tasks/8/activities': () => ({ json: { data: [] } }),
        'DELETE /api/tasks/7/evidences/2': async () => {
            await new Promise((resolve) => (release = resolve));
            return { json: { message: 'Evidence berhasil dihapus.' } };
        },
    });
    await board.load();
    board.detailId = 7;
    const panel = createDetail();

    // Closed while the request runs.
    await panel.open(7);
    let running = panel.removeEvidence(panel.evidences[0]);
    panel.reset();
    release();
    await running;
    assert.equal(toasts.at(-1).title, 'Evidence dihapus', 'the deletion succeeded, so no failure is reported');
    assert.equal(board.tasks.find(({ id }) => id === 7).evidence_count, 0);
    assert.equal(requests.some(({ url }) => url.includes('undefined')), false);

    // Another task opened while the request runs: its card and panel stay untouched.
    board.tasks.find(({ id }) => id === 7).evidence_count = 1;
    await panel.open(7);
    running = panel.removeEvidence(panel.evidences[0]);
    await panel.open(8);
    release();
    await running;
    assert.equal(board.tasks.find(({ id }) => id === 8).evidence_count, 3);
    assert.equal(panel.task.evidence_count, 3);
    assert.equal(board.tasks.find(({ id }) => id === 7).evidence_count, 0);
    assert.equal(panel.deletingEvidenceId, null);
});

test('A rejected evidence deletion that returns after another task was opened shows nothing on that task', async () => {
    let release;
    const { board, detail: createDetail, requests, toasts } = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task({ status: 'in_progress', evidence_count: 1 }), task({ id: 8, title: 'Tugas lain', status: 'in_progress', evidence_count: 3 })], meta: { status_counts: {} } } }),
        'GET /api/tasks/7': () => ({ json: { data: task({ status: 'in_progress', evidence: [ownEvidence(2, 'banner-v2.png')], evidence_count: 1, history: [] }) } }),
        'GET /api/tasks/8': () => ({ json: { data: task({ id: 8, title: 'Tugas lain', status: 'in_progress', evidence: [], evidence_count: 3, history: [] }) } }),
        'GET /api/tasks/7/activities': () => ({ json: { data: [] } }),
        'GET /api/tasks/8/activities': () => ({ json: { data: [] } }),
        'DELETE /api/tasks/7/evidences/2': async () => {
            await new Promise((resolve) => (release = resolve));
            return { status: 422, json: { message: 'Data tidak valid.', errors: { evidence: ['Evidence hanya dapat dihapus saat tugas berstatus To Do atau On Process, bukan Ready Review.'] } } };
        },
    });
    await board.load();
    board.detailId = 7;
    const panel = createDetail();
    await panel.open(7);

    const running = panel.removeEvidence(panel.evidences[0]);
    await panel.open(8);
    const toastsBefore = toasts.length;
    const detailRequestsBefore = requests.filter(({ url }) => url === '/api/tasks/7').length;
    release();
    await running;

    assert.equal(panel.task.id, 8);
    assert.deepEqual({ ...panel.evidenceErrors }, {}, 'the reason for Task A is not shown on Task B');
    assert.equal(toasts.length, toastsBefore, 'no error toast while Task B is open');
    assert.equal(panel.task.evidence_count, 3);
    assert.deepEqual(board.tasks.map(({ id, evidence_count }) => [id, evidence_count]), [[7, 1], [8, 3]], 'nothing was deleted, so no count changes');
    assert.equal(requests.filter(({ url }) => url === '/api/tasks/7').length, detailRequestsBefore, 'Task A is not reloaded into the panel');
    assert.equal(panel.deletingEvidenceId, null);
});

// --- Diskusi (TASK-16) ------------------------------------------------------------------------

const MANAGER_AUTHOR = { id: 2, fullname: 'Manajer Marketing', role: { code_role: 'MGR', name: 'Manajer' } };
const PIC_AUTHOR = { id: PIC.id, fullname: 'Staff Marketing', role: { code_role: 'EMP', name: 'Staff' } };
const commentOf = (id, content, author, parent = null, at = `2026-11-10T0${id}:00:00Z`) => ({
    id,
    task_id: 7,
    parent_comment_id: parent?.id ?? null,
    content,
    author,
    reply_to: parent ? { id: parent.id, author: { id: parent.author.id, fullname: parent.author.fullname } } : null,
    created_at: at,
});
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const commentPosts = (requests) => requests.filter(({ method, url }) => method === 'POST' && url === '/api/tasks/7/comments');

async function discussionPage({ comments = [], detail = {}, post, refreshed } = {}) {
    let detailReplies = 0;
    const page = await tasklistPage({
        'GET /api/tasks': () => ({ json: { data: [task()], meta: { status_counts: {} } } }),
        'GET /api/tasks/monitoring': () => ({ json: { data: monitoringOf({}) } }),
        'GET /api/tasks/7': () => ({ json: { data: detailReplies++ && refreshed ? refreshed() : task({ evidence: [], history: [], can_comment: true, ...detail }) } }),
        'GET /api/tasks/7/activities': () => ({ json: { data: [] } }),
        'GET /api/tasks/7/comments': () => ({ json: { data: typeof comments === 'function' ? comments() : comments } }),
        ...(post ? { 'POST /api/tasks/7/comments': post } : {}),
    });
    await page.board.load();
    page.board.detailId = 7;
    const view = page.detail();
    await view.open(7);

    return { ...page, view };
}

test('The detail shows one discussion thread per comment in time order, with each author, role, time, and the comment a reply answers', async () => {
    const first = commentOf(1, 'Mohon cek ulang ukuran banner.', MANAGER_AUTHOR);
    const answer = commentOf(2, 'Sudah saya perbaiki.\nMohon dicek.', PIC_AUTHOR, first);
    const second = commentOf(3, 'Jadwal tayang mundur sehari.', MANAGER_AUTHOR);
    const followUp = commentOf(4, 'Terima kasih, sudah sesuai.', MANAGER_AUTHOR, answer);
    const { view, requests } = await discussionPage({ comments: [first, answer, second, followUp] });

    assert.ok(requests.some(({ method, url }) => method === 'GET' && url === '/api/tasks/7/comments'));
    assert.equal(view.commentState, 'ready');
    assert.deepEqual([...view.commentThreads].map(({ root, replies }) => [root.id, [...replies].map(({ id }) => id)]), [[1, [2, 4]], [3, []]],
        'a reply to a reply stays in the thread of its first comment, in time order');
    assert.equal(view.commentThreads[0].replies[1].reply_to.author.fullname, 'Staff Marketing', 'the reply shows whom it answers');
    assert.equal(view.commentThreads[0].replies[0].author.role.name, 'Staff');

    // A reply whose parent is not in the list is shown on its own instead of disappearing.
    view.comments = [commentOf(9, 'Balasan tanpa induk', PIC_AUTHOR, { id: 99, author: MANAGER_AUTHOR })];
    assert.deepEqual([...view.commentThreads].map(({ root }) => root.id), [9]);
});

test('A comment is sent with its content, a new client_request_id, and the parent of a reply, and shows only after the backend saved it', async () => {
    let release;
    const { view, requests } = await discussionPage({
        post: (body) => new Promise((resolve) => (release = () => resolve({ status: 201, json: { data: commentOf(5, body.content, PIC_AUTHOR, body.parent_comment_id ? commentOf(1, 'x', MANAGER_AUTHOR) : null), message: 'Komentar berhasil dikirim.' } }))),
        comments: [commentOf(1, 'Mohon cek ulang ukuran banner.', MANAGER_AUTHOR)],
    });
    const statusBefore = view.task.status;

    view.startReply(view.comments[0]);
    assert.equal(view.replyTarget.id, 1);
    view.commentForm.content = '  Sudah saya perbaiki.  ';
    const sending = view.sendComment();
    await new Promise((resolve) => setTimeout(resolve));

    assert.equal(view.sendingComment, true);
    assert.equal(view.comments.length, 1, 'nothing is shown before the backend confirms');
    const [sent] = commentPosts(requests);
    assert.equal(sent.body.content, 'Sudah saya perbaiki.');
    assert.equal(sent.body.parent_comment_id, 1);
    assert.match(sent.body.client_request_id, UUID);

    release();
    await sending;
    await new Promise((resolve) => setTimeout(resolve));
    assert.deepEqual([...view.comments].map(({ id }) => id), [1, 5]);
    assert.deepEqual({ ...view.commentForm }, { content: '', parentId: null }, 'the form is cleared for the next comment');
    assert.equal(view.sendingComment, false);
    assert.equal(requests.filter(({ method, url }) => method === 'GET' && url === '/api/tasks/7').length, 2, 'the detail is read again for the history and can_comment');
    assert.equal(requests.filter(({ method }) => method === 'PATCH').length, 0, 'a comment is not a status change or review');
    assert.equal(view.task.status, statusBefore);

    // A new comment is a new send, even with the same text.
    view.commentForm.content = 'Sudah saya perbaiki.';
    release = null;
    const next = view.sendComment();
    await new Promise((resolve) => setTimeout(resolve));
    release();
    await next;
    const [firstSend, secondSend] = commentPosts(requests);
    assert.notEqual(secondSend.body.client_request_id, firstSend.body.client_request_id);
    assert.equal('parent_comment_id' in secondSend.body, false, 'a main comment has no parent');
});

test('A failed send keeps the text and retries with the same client_request_id, so it cannot be saved twice; a changed text is a new send', async () => {
    const replies = [
        { status: 500, json: { message: 'Server Error' } },
        { status: 200, json: { data: commentOf(5, 'Revisi sudah diunggah.', PIC_AUTHOR), message: 'Komentar berhasil dikirim.' } },
    ];
    const { view, requests } = await discussionPage({ post: () => replies.shift() ?? { status: 500, json: {} } });

    view.commentForm.content = 'Revisi sudah diunggah.';
    await view.sendComment();
    assert.equal(view.commentFormError, 'Komentar gagal dikirim. Periksa koneksi Anda, lalu kirim lagi.');
    assert.equal(view.commentForm.content, 'Revisi sudah diunggah.', 'the text is kept for the retry');
    assert.equal(view.comments.length, 0);

    await view.sendComment();
    const [failed, retried] = commentPosts(requests);
    assert.equal(retried.body.client_request_id, failed.body.client_request_id, 'the retry is the same send');
    assert.deepEqual([...view.comments].map(({ id }) => id), [5]);

    // The backend answering the same send again (200) does not add it twice.
    replies.push({ status: 200, json: { data: commentOf(5, 'Revisi sudah diunggah.', PIC_AUTHOR) } });
    view.commentForm.content = 'Revisi sudah diunggah.';
    view.commentSend = { key: JSON.stringify([7, null, 'Revisi sudah diunggah.']), id: failed.body.client_request_id };
    await view.sendComment();
    assert.deepEqual([...view.comments].map(({ id }) => id), [5]);

    // Editing the text after a failure makes a new send.
    replies.push({ status: 500, json: {} });
    view.commentForm.content = 'Teks pertama';
    await view.sendComment();
    view.commentForm.content = 'Teks yang diubah';
    replies.push({ status: 500, json: {} });
    await view.sendComment();
    const [, , , edited1, edited2] = commentPosts(requests);
    assert.notEqual(edited2.body.client_request_id, edited1.body.client_request_id);
});

test('An empty comment is not sent, and a task without can_comment or already Done shows the discussion read-only', async () => {
    const { view, requests } = await discussionPage({ post: () => ({ status: 201, json: { data: commentOf(5, 'x', PIC_AUTHOR) } }) });

    view.commentForm.content = '   \n  ';
    await view.sendComment();
    assert.equal(view.commentFormError, 'Komentar tidak boleh kosong.');
    assert.equal(commentPosts(requests).length, 0);
    assert.equal(view.commentReadOnlyNote, '');

    view.task = { ...view.task, can_comment: false, status: 'in_progress' };
    view.commentForm.content = 'Boleh saya bantu?';
    await view.sendComment();
    assert.equal(commentPosts(requests).length, 0, 'read access only: nothing is sent');
    assert.equal(view.canComment, false);
    assert.match(view.commentReadOnlyNote, /hanya dapat dikirim oleh Admin, Manajer, Supervisor divisi tugas, dan PIC aktif/);

    view.task = { ...view.task, can_comment: false, status: 'done' };
    assert.equal(view.commentReadOnlyNote, 'Tugas sudah selesai. Diskusi tetap dapat dibaca, tetapi komentar baru tidak dapat ditambahkan.');
});

test('Rejections show the backend reason: a task that became Done or a released PIC refreshes can_comment, and a reply outside the task drops its target', async () => {
    const replies = [
        { status: 422, json: { message: 'x', errors: { content: ['Tugas yang sudah selesai tidak dapat dikomentari.'] } } },
        { status: 403, json: { message: 'This action is unauthorized.' } },
        { status: 422, json: { message: 'x', errors: { parent_comment_id: ['Komentar yang dibalas tidak ditemukan pada tugas ini.'] } } },
    ];
    let refreshedStatus = 'done';
    const { view, requests } = await discussionPage({
        comments: [commentOf(1, 'Mohon cek ulang.', MANAGER_AUTHOR)],
        post: () => replies.shift(),
        refreshed: () => task({ evidence: [], history: [], status: refreshedStatus, can_comment: false }),
    });
    const settle = () => new Promise((resolve) => setTimeout(resolve));

    view.commentForm.content = 'Sudah saya perbaiki.';
    await view.sendComment();
    await settle();
    assert.equal(view.commentFormError, 'Tugas yang sudah selesai tidak dapat dikomentari.');
    assert.equal(view.task.status, 'done', 'the detail is read again');
    assert.equal(view.canComment, false, 'the form is replaced by the read-only note');
    assert.equal(view.commentForm.content, 'Sudah saya perbaiki.');

    view.task = { ...view.task, status: 'in_progress', can_comment: true };
    refreshedStatus = 'in_progress';
    await view.sendComment();
    await settle();
    assert.equal(view.commentFormError, 'Anda tidak lagi dapat mengirim komentar pada tugas ini.');
    assert.equal(view.canComment, false);

    view.task = { ...view.task, can_comment: true };
    view.startReply(view.comments[0]);
    const commentLoads = requests.filter(({ method, url }) => method === 'GET' && url === '/api/tasks/7/comments').length;
    await view.sendComment();
    assert.equal(view.commentFormError, 'Komentar yang dibalas tidak ditemukan pada tugas ini.');
    assert.equal(view.commentForm.parentId, null);
    assert.equal(requests.filter(({ method, url }) => method === 'GET' && url === '/api/tasks/7/comments').length, commentLoads + 1, 'the thread is loaded again');
});

test('The discussion has its own loading and error state with a retry, and ignores comments of a task that is no longer open', async () => {
    let fail = true;
    const { view } = await discussionPage({ comments: () => {
        if (fail) throw Object.assign(new Error('offline'), { offline: true });
        return [commentOf(1, 'Halo', MANAGER_AUTHOR)];
    } });
    assert.equal(view.commentState, 'error');
    assert.equal(view.state, 'ready', 'the detail stays open');

    fail = false;
    await view.loadComments();
    assert.equal(view.commentState, 'ready');
    assert.equal(view.comments.length, 1);

    const pending = view.loadComments();
    view.reset();
    await pending;
    assert.deepEqual([...view.comments], [], 'a closed detail does not receive the comments');
});

test('After a send succeeds the thread follows the backend: a comment saved while its answer was lost, and comments of others, also show', async () => {
    const saved = [commentOf(1, 'Mohon cek ulang.', MANAGER_AUTHOR)];
    let threadFails = true;
    const { view, requests } = await discussionPage({
        comments: () => {
            if (threadFails) throw new Error('offline');
            return saved;
        },
        post: (body) => {
            const comment = commentOf(saved.length + 1, body.content, PIC_AUTHOR, null, `2026-11-10T0${saved.length + 1}:30:00Z`);
            saved.push(comment);
            // The first send is stored, but its answer never reaches the browser.
            if (saved.length === 2) throw new Error('connection reset');
            return { status: 201, json: { data: comment } };
        },
    });
    assert.equal(view.commentState, 'error', 'the thread failed to load');
    threadFails = false;

    view.commentForm.content = 'Draf pertama';
    await view.sendComment();
    assert.match(view.commentFormError, /Komentar gagal dikirim/);
    assert.equal(view.comments.length, 0, 'an unconfirmed comment is not shown');

    // The text is changed, so this is a new send; the backend now holds both.
    view.commentForm.content = 'Draf yang diperbaiki';
    await view.sendComment();
    await new Promise((resolve) => setTimeout(resolve));

    assert.equal(view.commentState, 'ready', 'the thread that failed to load is shown after the send');
    assert.deepEqual([...view.comments].map(({ content }) => content), ['Mohon cek ulang.', 'Draf pertama', 'Draf yang diperbaiki']);
    assert.equal(commentPosts(requests).length, 2);
});
