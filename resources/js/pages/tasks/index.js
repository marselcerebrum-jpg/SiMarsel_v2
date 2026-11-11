import Alpine from 'alpinejs';
import { showToast } from '../../components/toast';
import { errorMessage, http, validationErrors } from '../../http';
import {
    assignTask,
    changeTaskStatus,
    createTask,
    fetchAssignmentOptions,
    fetchCalendar,
    fetchSchedule,
    fetchTask,
    fetchTaskCreateOptions,
    fetchTaskMeta,
    fetchTasks,
    reviewTask,
    submitEvidence,
    updateTask,
} from '../../services/tasks';
import {
    PRIORITIES,
    STATUSES,
    addDays,
    assignmentRule,
    availableTransitions,
    canEditTask,
    formatDate,
    formatDateTime,
    isOverdue,
    parseISODate,
    priorityOf,
    statusOf,
    summarize,
    toISODate,
    today,
    transitionRule,
    userContext,
} from './workflow';

const VIEWS = ['board', 'table', 'timeline', 'calendar'];
const PAGE_SIZE = 10;
const BACKEND_PENDING = 'Menunggu pengerjaan Back End.';

const EVIDENCE_MAX_MB = 10;
const EVIDENCE_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'];

const conflictMessage = 'Tugas ini sudah diperbarui oleh pengguna lain. Data terbaru dimuat ulang, silakan periksa kembali.';

// Pesan untuk respons umum operasi tugas: 403 akses ditolak, 404 tidak ditemukan, 409 data lama.
function taskErrorMessage(error, fallback) {
    if (error?.unavailable) return BACKEND_PENDING;
    if (error?.status === 403) return 'Anda tidak memiliki akses untuk tindakan ini.';
    if (error?.status === 404) return 'Tugas tidak ditemukan atau sudah dihapus.';
    if (error?.status === 409) return conflictMessage;

    return errorMessage(error, fallback);
}

const notifyFailure = (error, fallback) =>
    showToast({ type: 'info', title: error?.unavailable ? 'Menunggu Back End' : 'Gagal', text: taskErrorMessage(error, fallback), duration: 5000 });

const isValidUrl = (value) => {
    try {
        const url = new URL(value);

        return ['http:', 'https:'].includes(url.protocol);
    } catch {
        return false;
    }
};

const SORTERS = {
    title: (task) => task.title?.toLowerCase() ?? '',
    division: (task) => task.division?.name?.toLowerCase() ?? '',
    pic: (task) => task.pic?.fullname?.toLowerCase() ?? '',
    priority: (task) => priorityOf(task.priority).rank,
    deadline: (task) => task.deadline ?? '9999-12-31',
    status: (task) => STATUSES.findIndex((status) => status.key === task.status),
};

/**
 * Induk halaman Tasklist: data tugas, filter, dan tampilan Board/Table dipakai bersama
 * oleh semua tab, panel detail, dan form pembuatan tugas.
 */
Alpine.data('tasklist', () => ({
    statuses: STATUSES,
    priorities: PRIORITIES,
    views: [
        { key: 'board', label: 'Papan' },
        { key: 'table', label: 'Tabel' },
        { key: 'timeline', label: 'Linimasa' },
        { key: 'calendar', label: 'Kalender' },
    ],
    view: 'board',

    user: userContext(Alpine.store('session').user),
    meta: null,
    divisions: [],

    tasks: [],
    status: null,
    loading: true,
    error: '',
    loadSequence: 0,

    filters: { search: '', division: '', priority: '', status: '' },
    sort: { key: 'deadline', dir: 'asc' },
    page: 1,

    draggingId: null,
    dropTarget: null,
    movingId: null,

    detailId: null,
    createOpen: false,

    async init() {
        const requested = new URLSearchParams(location.search).get('view');
        if (VIEWS.includes(requested)) this.view = requested;
        this.$watch('view', (view) => {
            const url = new URL(location.href);
            url.searchParams.set('view', view);
            history.replaceState(null, '', url);
        });

        // ?task=ID membuka detail tugas tersebut di Board; parameter mengikuti panel detail yang terbuka.
        const requestedTask = Number(new URLSearchParams(location.search).get('task'));
        this.$watch('detailId', (id) => {
            const url = new URL(location.href);
            if (id === null) url.searchParams.delete('task');
            else url.searchParams.set('task', id);
            history.replaceState(null, '', url);
        });

        this.filters.division = this.defaultDivision();
        this.$watch('filters', () => {
            this.page = 1;
            this.load();
        });

        await this.loadMeta();
        await this.load();
        if (Number.isInteger(requestedTask) && requestedTask > 0) this.focusTask({ id: requestedTask });
    },

    async loadMeta() {
        try {
            const { data } = await fetchTaskMeta();
            this.meta = data;
            this.user = userContext(Alpine.store('session').user, data);
            this.divisions = data.divisions ?? [];
            this.filters.division = this.defaultDivision();
        } catch {
            // Meta tugas gagal dimuat: pakai master divisi agar filter tetap tersedia.
            await this.loadDivisions();
        }
    },

    async loadDivisions() {
        try {
            const { data } = await http('/api/divisions');
            this.divisions = data.map(({ code_division, name }) => ({ code_division, name }));
        } catch {
            this.divisions = [];
        }
    },

    // Admin: Semua Divisi. Manajer: divisinya sendiri. Staff: tugas miliknya (tanpa filter divisi).
    defaultDivision() {
        return this.user.role === 'manager' ? (this.user.division ?? '') : '';
    },

    get divisionOptions() {
        if (this.user.role === 'admin') return this.divisions;
        if (this.user.role === 'manager') return this.divisions.filter((division) => division.code_division === this.user.division);

        return [];
    },

    get scopeLabel() {
        if (this.user.role === 'staff') return 'Tugas yang ditugaskan kepada Anda';

        const division = this.divisions.find((item) => item.code_division === this.filters.division);
        if (division) return `Divisi ${division.name}`;

        if (this.user.role === 'admin') return 'Semua Divisi';

        return this.user.division ? 'Divisi Anda' : 'Divisi Anda (belum ditetapkan)';
    },

    get canCreate() {
        // Diputuskan backend lewat meta; bila meta gagal dimuat, pakai aturan TaskPolicy::create yang sama.
        return this.meta?.can_create ?? (this.user.role === 'admin' || (this.user.role === 'manager' && Boolean(this.user.division)));
    },

    get hasActiveFilter() {
        return ['search', 'priority', 'status'].some((key) => this.filters[key] !== '') || this.filters.division !== this.defaultDivision();
    },

    resetFilters() {
        this.filters = { search: '', division: this.defaultDivision(), priority: '', status: '' };
        this.sort = { key: 'deadline', dir: 'asc' };
    },

    async load() {
        const sequence = ++this.loadSequence;
        this.loading = true;
        this.error = '';

        try {
            const { data } = await fetchTasks({
                search: this.filters.search.trim(),
                division: this.filters.division,
                priority: this.filters.priority,
                status: this.filters.status,
            });
            if (sequence !== this.loadSequence) return;

            this.tasks = data;
            this.status = 'ready';
        } catch (error) {
            if (sequence !== this.loadSequence) return;

            this.tasks = [];
            this.status = error?.unavailable ? 'unavailable' : 'error';
            this.error = taskErrorMessage(error, 'Gagal memuat daftar tugas.');
        } finally {
            if (sequence === this.loadSequence) {
                this.loading = false;
                if (this.highlightId) this.$nextTick(() => this.revealHighlight());
            }
        }
    },

    /**
     * Filter tambahan untuk item Timeline/Calendar yang tidak diambil oleh daftar Task.
     */
    matchesFilters(item) {
        const keyword = this.filters.search.trim().toLowerCase();

        return (!keyword || (item.title ?? '').toLowerCase().includes(keyword))
            && (!this.filters.division || item.division?.code_division === this.filters.division)
            && (!this.filters.priority || !item.priority || item.priority === this.filters.priority)
            && (!this.filters.status || !item.status || item.status === this.filters.status);
    },

    get filteredTasks() {
        return this.tasks.filter((task) => this.matchesFilters(task));
    },

    get summary() {
        return summarize(this.filteredTasks);
    },

    // 'none' = belum ada tugas sama sekali, 'filtered' = filter tidak menemukan tugas.
    get emptyKind() {
        if (this.status !== 'ready') return null;
        if (!this.tasks.length) return this.hasActiveFilter ? 'filtered' : 'none';

        return this.filteredTasks.length ? null : 'filtered';
    },

    tasksIn(status) {
        return this.filteredTasks.filter((task) => task.status === status);
    },

    // --- Table ----------------------------------------------------------------------------

    sortBy(key) {
        this.sort = this.sort.key === key ? { key, dir: this.sort.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' };
    },

    ariaSort(key) {
        if (this.sort.key !== key) return 'none';

        return this.sort.dir === 'asc' ? 'ascending' : 'descending';
    },

    get sortedTasks() {
        const value = SORTERS[this.sort.key];
        const direction = this.sort.dir === 'asc' ? 1 : -1;

        return [...this.filteredTasks].sort((a, b) => (value(a) > value(b) ? 1 : value(a) < value(b) ? -1 : 0) * direction);
    },

    get pageCount() {
        return Math.max(1, Math.ceil(this.sortedTasks.length / PAGE_SIZE));
    },

    get currentPage() {
        return Math.min(this.page, this.pageCount);
    },

    get pageRows() {
        const start = (this.currentPage - 1) * PAGE_SIZE;

        return this.sortedTasks.slice(start, start + PAGE_SIZE);
    },

    get rangeLabel() {
        const total = this.sortedTasks.length;
        if (!total) return '0';

        return `${(this.currentPage - 1) * PAGE_SIZE + 1}–${Math.min(this.currentPage * PAGE_SIZE, total)}`;
    },

    // Nomor halaman: pertama, terakhir, dan sekitar halaman aktif; celah ditampilkan sebagai "…".
    get pageLinks() {
        const total = this.pageCount;
        const current = this.currentPage;
        const pages = new Set([1, total, current - 1, current, current + 1]);

        if (current <= 3) [2, 3, 4].forEach((page) => pages.add(page));
        if (current >= total - 2) [total - 3, total - 2, total - 1].forEach((page) => pages.add(page));

        const sorted = [...pages].filter((page) => page >= 1 && page <= total).sort((x, y) => x - y);

        return sorted.flatMap((page, index) => [
            ...(index > 0 && page - sorted[index - 1] > 1 ? [{ key: `gap-${page}`, page: null }] : []),
            { key: `page-${page}`, page },
        ]);
    },

    goTo(page) {
        this.page = Math.min(Math.max(1, page), this.pageCount);
    },

    // --- Tampilan & hak akses per tugas -------------------------------------------------------

    statusOf,
    priorityOf,
    formatDate,
    formatDateTime,

    isOverdue(task) {
        return isOverdue(task);
    },

    canOpen(task) {
        return task.can_view_detail !== false;
    },

    canEdit(task) {
        return canEditTask(task, this.user);
    },

    openTask(task, { focus = null } = {}) {
        if (!this.canOpen(task)) {
            showToast({ type: 'info', title: 'Akses terbatas', text: 'Detail tugas ini hanya dapat dibuka oleh pengguna yang berwenang.' });
            return;
        }

        this.detailFocus = focus;
        this.detailId = task.id;
    },

    detailFocus: null,
    highlightId: null,

    closeTask() {
        this.detailId = null;
    },

    /**
     * "Lihat Detail" dari Calendar/Timeline: pindah ke Board, buka detail berdasarkan ID tugas, dan
     * pastikan kartunya terlihat. Filter yang menyembunyikan tugas dilonggarkan sebatas hak akses
     * (Admin boleh ke Semua Divisi; Manajer dan Staff memang hanya menerima tugas dalam cakupannya).
     */
    focusTask(task) {
        if (!this.canOpen(task)) {
            this.openTask(task);
            return;
        }

        const loaded = this.tasks.find((item) => item.id === task.id);
        if (!loaded || !this.matchesFilters(loaded)) {
            this.filters.search = '';
            this.filters.priority = '';
            this.filters.status = '';
        }
        if (!loaded && this.user.role === 'admin') this.filters.division = '';

        this.highlightId = task.id;
        this.view = 'board';
        this.openTask(task);
        this.$nextTick(() => this.revealHighlight());
    },

    // Dipanggil juga dari scope Calendar/detail, jadi kartu dicari di dokumen, bukan $root komponen pemanggil.
    revealHighlight() {
        const card = document.querySelector(`[data-task-card="${this.highlightId}"]`);
        if (!card) return;

        card.scrollIntoView({ block: 'nearest', inline: 'center' });
        setTimeout(() => (this.highlightId = null), 2500);
    },

    // Satu tugas berubah (status, PIC, informasi): perbarui Board, Table, dan ringkasan sekaligus.
    syncTask(task) {
        const index = this.tasks.findIndex((item) => item.id === task.id);
        if (index === -1) this.tasks.push(task);
        else this.tasks.splice(index, 1, { ...this.tasks[index], ...task });
    },

    // --- Drag-and-drop Board (TASK-05 D) ------------------------------------------------------

    canDrag(task) {
        return this.movingId === null && availableTransitions(task, this.user).length > 0;
    },

    dragStart(event, task) {
        this.draggingId = task.id;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(task.id));
    },

    dragEnd() {
        this.draggingId = null;
        this.dropTarget = null;
    },

    get draggingTask() {
        return this.tasks.find((task) => task.id === this.draggingId) ?? null;
    },

    dropState(status) {
        if (!this.draggingTask || this.draggingTask.status === status) return null;

        return transitionRule(this.draggingTask, status, this.user).ok ? 'allowed' : 'denied';
    },

    dropReason(status) {
        return this.dropState(status) === 'denied' ? transitionRule(this.draggingTask, status, this.user).reason : '';
    },

    // Hanya kolom dengan transisi valid yang menerima drop; kolom lain menampilkan alasan penolakan.
    dragOver(event, status) {
        this.dropTarget = status;
        if (this.dropState(status) === 'allowed') event.preventDefault();
        else event.dataTransfer.dropEffect = 'none';
    },

    async drop(status) {
        const task = this.draggingTask;
        this.dragEnd();
        if (!task || task.status === status) return;

        const { ok, reason, rule } = transitionRule(task, status, this.user);

        // Perpindahan ditolak: kartu tidak dipindahkan, jadi tetap di kolom sebelumnya.
        if (!ok) {
            showToast({ type: 'info', title: 'Perpindahan ditolak', text: reason, duration: 5000 });
            return;
        }

        // Revisi wajib memiliki catatan alasan: buka form review di panel detail.
        if (rule.review === 'revision') {
            this.openTask(task, { focus: 'revision' });
            return;
        }

        await this.moveTask(task, rule);
    },

    // Keputusan QA (On QA → Done) memakai endpoint review; perpindahan lain memakai endpoint status.
    async moveTask(task, transition) {
        this.movingId = task.id;

        try {
            const { data, message } = transition.review === 'approve'
                ? await reviewTask(task.id, { decision: 'approve', version: task.version })
                : await changeTaskStatus(task.id, { status: transition.to, version: task.version });
            this.syncTask(data);
            showToast({ title: 'Status diperbarui', text: message ?? `${task.title} → ${statusOf(transition.to).label}` });
        } catch (error) {
            if (error?.status === 422) {
                const errors = validationErrors(error);
                notifyFailure({ ...error, data: { ...error.data, message: errors.decision ?? errors.status ?? errors.pic_id } }, 'Status tugas gagal diperbarui.');
            } else {
                notifyFailure(error, 'Status tugas gagal diperbarui.');
            }
            if (error?.status === 409) this.load();
        } finally {
            this.movingId = null;
        }
    },
}));

/**
 * Form Create Task (TASK-02). Dibuka dari induk melalui createOpen.
 */
const emptyTask = () => ({
    title: '',
    description: '',
    category_id: '',
    division_id: '',
    pic_id: '',
    platform_id: '',
    priority: 'medium',
    start_date: '',
    deadline: '',
    status: 'backlog',
});

Alpine.data('taskCreateForm', () => ({
    form: emptyTask(),
    errors: {},
    formError: '',
    formPending: false,
    saving: false,

    divisions: [],
    categories: [],
    platforms: [],
    taskPriorities: [],
    assignees: [],
    optionsState: 'idle',
    optionsError: '',
    optionsRequest: 0,

    init() {
        this.$watch('createOpen', (open) => {
            if (!open) return;
            this.reset();
            this.loadOptions();
        });
        this.$watch('form.division_id', (divisionId) => {
            this.form.pic_id = '';
            this.assignees = this.creatableDivisions.find((division) => String(division.id) === String(divisionId))?.pics ?? [];
        });
    },

    reset() {
        this.form = emptyTask();
        this.errors = {};
        this.formError = '';
        this.formPending = false;
        this.optionsError = '';
        this.$nextTick(() => this.$refs.createTitle?.focus());
    },

    async loadOptions() {
        const request = ++this.optionsRequest;
        this.optionsState = 'loading';
        this.optionsError = '';

        try {
            const { data } = await fetchTaskCreateOptions();
            if (request !== this.optionsRequest || !this.createOpen) return;

            this.divisions = data.divisions;
            this.categories = data.categories;
            this.platforms = data.platforms;
            this.taskPriorities = data.priorities.map(({ value, label }) => ({ key: value, label }));
            this.optionsState = 'ready';

            if (this.user.role === 'manager') {
                this.form.division_id = this.creatableDivisions.find((division) => division.code_division === this.user.division)?.id ?? '';
            }
        } catch (error) {
            if (request !== this.optionsRequest) return;

            this.optionsState = 'error';
            this.optionsError = taskErrorMessage(error, 'Gagal memuat pilihan pembuatan tugas.');
        }
    },

    // Admin: semua divisi. Manajer: hanya divisi yang dikelolanya.
    get creatableDivisions() {
        return this.divisions;
    },

    get assigneeState() {
        if (this.optionsState !== 'ready') return this.optionsState;
        if (!this.form.division_id) return 'idle';

        return this.assignees.length ? 'ready' : 'empty';
    },

    get selectedCategory() {
        return this.categories.find((category) => String(category.id) === String(this.form.category_id)) ?? null;
    },

    // Kewajiban QA ditentukan oleh kategori tugas, bukan dipilih manual.
    get requiresReview() {
        return this.selectedCategory ? Boolean(this.selectedCategory.requires_review) : null;
    },

    validate() {
        const errors = {};
        const { title, division_id, status, pic_id, start_date, deadline } = this.form;

        if (!title.trim()) errors.title = 'Judul tugas wajib diisi.';
        else if (title.trim().length > 150) errors.title = 'Judul tugas maksimal 150 karakter.';
        if (!division_id) errors.division_id = 'Target divisi wajib dipilih.';
        if (status === 'todo' && !pic_id) errors.pic_id = 'Tugas berstatus To Do wajib memiliki PIC.';
        if (start_date && deadline && deadline < start_date) errors.deadline = 'Deadline tidak boleh sebelum tanggal mulai.';

        this.errors = errors;

        return !Object.keys(errors).length;
    },

    async submit() {
        if (this.saving || this.optionsState !== 'ready' || !this.validate()) return;

        this.saving = true;
        this.formError = '';
        this.formPending = false;

        try {
            const { data, message } = await createTask({
                ...this.form,
                title: this.form.title.trim(),
                description: this.form.description.trim(),
                requires_review: this.requiresReview,
            });
            this.syncTask(data);
            this.createOpen = false;
            showToast({ title: 'Tugas Dibuat', text: message ?? `${data.title} ditambahkan ke ${statusOf(data.status).label}.` });
        } catch (error) {
            if (error?.status === 422) {
                this.errors = validationErrors(error);
                this.formError = 'Periksa kembali isian yang ditandai.';
            } else if (error?.status === 409) {
                // Pengiriman ganda: tugas yang sama sudah tersimpan, jadi muat ulang daftar agar terlihat.
                this.formError = errorMessage(error, 'Tugas yang sama baru saja dikirim. Periksa daftar tugas sebelum mengirim ulang.');
                this.load();
            } else if (error?.unavailable) {
                this.formPending = true;
                this.formError = `${BACKEND_PENDING} Isian sudah valid, tetapi penyimpanan tugas belum tersedia.`;
            } else {
                this.formError = taskErrorMessage(error, 'Tugas gagal disimpan.');
            }
        } finally {
            this.saving = false;
        }
    },
}));

/**
 * Panel detail tugas (TASK-03 s.d. TASK-08): informasi, edit, PIC, status, evidence, review, histori.
 */
Alpine.data('taskDetail', () => ({
    task: null,
    state: 'idle',
    message: '',

    evidences: [],
    evidenceState: 'idle',
    activities: [],
    activityState: 'idle',

    editing: false,
    reviewMode: null,
    reviewNote: '',
    reviewError: '',
    editForm: {},
    editErrors: {},
    editCategories: [],
    editPlatforms: [],
    editOptionsState: 'idle',
    editOptionsError: '',
    saving: false,

    assigneeState: 'idle',
    assignmentDivisions: [],
    canChangeAssignmentDivision: false,
    assignmentError: '',
    assignForm: { pic_id: '', division_id: '' },
    assigning: false,

    evidenceForm: { type: 'file', file: null, url: '', note: '' },
    evidenceErrors: {},
    sendingEvidence: false,

    acting: null,

    init() {
        this.$watch('detailId', (id) => (id ? this.open(id) : this.reset()));
    },

    reset() {
        Object.assign(this, { task: null, state: 'idle', editing: false, reviewMode: null, reviewNote: '', reviewError: '', evidences: [], activities: [] });
    },

    async open(id) {
        this.reset();
        this.state = 'loading';

        try {
            const { data } = await fetchTask(id);
            this.task = data;
            this.state = 'ready';
            this.syncTask(data);
            this.syncDetailCollections(data);
            // Tugas yang belum ada di daftar baru masuk Board setelah detailnya dimuat.
            if (this.highlightId === data.id) this.$nextTick(() => this.revealHighlight());
            if (this.detailFocus === 'revision' && this.task.can_review) this.startReview('revision');
            if (this.detailFocus === 'edit' && this.canEditThis) this.startEdit();
        } catch (error) {
            if (this.highlightId === id) this.highlightId = null;
            this.state = error?.unavailable ? 'unavailable' : { 403: 'forbidden', 404: 'not_found' }[error?.status] ?? 'error';
            this.message = taskErrorMessage(error, 'Detail tugas gagal dimuat.');
        } finally {
            this.detailFocus = null;
        }
    },

    async reload() {
        if (this.detailId) await this.open(this.detailId);
    },

    // Respons sukses dari backend membawa data tugas terbaru: tampilkan di detail, Board, dan Table.
    applyTask(data) {
        this.task = { ...this.task, ...data };
        this.syncDetailCollections(data);
        this.syncTask(this.task);
    },

    syncDetailCollections(data) {
        if (Array.isArray(data.evidence)) {
            this.evidences = data.evidence;
            this.evidenceState = 'ready';
        }
        if (Array.isArray(data.history)) {
            this.activities = data.history;
            this.activityState = 'ready';
        }
    },

    async handleFailure(error, fallback) {
        notifyFailure(error, fallback);
        if (error?.status === 409) {
            await this.reload();
            this.load();
        }
    },

    // --- Hak akses pada tugas yang dibuka -----------------------------------------------------

    get isPicOfTask() {
        return this.task?.pic?.id !== undefined && this.task?.pic?.id === this.user.id;
    },

    get canEditThis() {
        return Array.isArray(this.task?.editable_fields)
            && this.task.editable_fields.some((field) => field !== 'status');
    },

    isFieldEditable(field) {
        return this.task?.editable_fields?.includes(field) ?? false;
    },

    // Kalimat histori disusun backend (action_label + description) agar label status, catatan review,
    // dan nama evidence selalu sama dengan data yang tersimpan. Rincian disembunyikan bila hanya mengulang judul.
    activityDetail(activity) {
        const detail = activity.description ?? '';

        return detail === 'Membuat tugas.' || detail === `${activity.action_label}.` ? '' : detail;
    },

    get assignment() {
        return this.task ? assignmentRule(this.task, this.user) : { ok: false, reason: '' };
    },

    get canAssignThis() {
        return this.task?.can_assign === true && this.assignment.ok;
    },

    get eligibleAssignees() {
        const division = this.assignmentDivisions.find((item) => String(item.id) === String(this.assignForm.division_id));

        return (division?.pics ?? []).filter((person) => person.id !== this.task?.pic?.id);
    },

    changeAssignmentDivision() {
        this.assignForm.pic_id = '';
    },

    get canSubmitEvidence() {
        return this.isPicOfTask && ['todo', 'in_progress'].includes(this.task?.status);
    },

    // --- Edit informasi utama (TASK-03 C) -----------------------------------------------------

    async startEdit() {
        const { title, description, category, platform, priority, start_date, deadline } = this.task;
        this.editForm = {
            title,
            description: description ?? '',
            category_id: category?.id ?? '',
            platform_id: platform?.id ?? '',
            priority,
            start_date: start_date ?? '',
            deadline: deadline ?? '',
        };
        this.editErrors = {};
        this.editing = true;
        this.editOptionsState = 'loading';
        this.editOptionsError = '';

        try {
            const { data } = await fetchTaskCreateOptions();
            this.editCategories = data.categories ?? [];
            this.editPlatforms = data.platforms ?? [];
            this.editOptionsState = 'ready';
        } catch (error) {
            this.editOptionsState = 'error';
            this.editOptionsError = taskErrorMessage(error, 'Gagal memuat pilihan edit tugas.');
        }
    },

    async saveEdit() {
        const errors = {};
        if (!this.editForm.title.trim()) errors.title = 'Judul tugas wajib diisi.';
        if (this.editForm.start_date && this.editForm.deadline && this.editForm.deadline < this.editForm.start_date) {
            errors.deadline = 'Deadline tidak boleh sebelum tanggal mulai.';
        }
        this.editErrors = errors;
        if (Object.keys(errors).length || this.saving || this.editOptionsState !== 'ready') return;

        this.saving = true;

        try {
            const { data, message } = await updateTask(this.task.id, {
                ...this.editForm,
                category_id: this.editForm.category_id || null,
                platform_id: this.editForm.platform_id || null,
                start_date: this.editForm.start_date || null,
                deadline: this.editForm.deadline || null,
                version: this.task.version,
            });
            this.applyTask(data);
            this.editing = false;
            showToast({ title: 'Perubahan Tersimpan', text: message ?? 'Informasi tugas diperbarui.' });
        } catch (error) {
            if (error?.status === 422) this.editErrors = validationErrors(error);
            else await this.handleFailure(error, 'Perubahan tugas gagal disimpan.');
        } finally {
            this.saving = false;
        }
    },

    // --- Penugasan PIC (TASK-04) ----------------------------------------------------------------

    async openAssign() {
        this.assignForm = { pic_id: '', division_id: '' };
        this.assignmentError = '';
        this.assigneeState = 'loading';

        try {
            const { data } = await fetchAssignmentOptions(this.task.id);
            this.assignmentDivisions = data.divisions ?? [];
            this.canChangeAssignmentDivision = data.can_change_division === true;
            this.assignForm.division_id = String(data.current_division.id);
            if (data.locked_reason) {
                this.assignmentError = data.locked_reason;
                this.assigneeState = 'error';

                return;
            }
            this.assigneeState = 'ready';
        } catch (error) {
            this.assignmentError = taskErrorMessage(error, 'Daftar PIC gagal dimuat.');
            this.assigneeState = 'error';
        }
    },

    async saveAssign() {
        if (!this.assignForm.pic_id || !this.assignForm.division_id || this.assigning) return;

        this.assigning = true;

        try {
            const { data, message } = await assignTask(this.task.id, {
                pic_id: this.assignForm.pic_id,
                division_id: this.assignForm.division_id,
                version: this.task.version,
            });
            this.applyTask(data);
            this.assigneeState = 'idle';
            showToast({ title: 'PIC diperbarui', text: message ?? `Tugas kini ditangani ${data.pic?.fullname ?? 'PIC baru'}.` });
        } catch (error) {
            if (error?.status === 422) {
                const errors = validationErrors(error);
                this.assignmentError = errors.pic_id ?? errors.division_id ?? taskErrorMessage(error, 'PIC gagal diperbarui.');
                this.assigneeState = 'error';
            } else {
                await this.handleFailure(error, 'PIC gagal diperbarui.');
            }
        } finally {
            this.assigning = false;
        }
    },

    // --- Workflow status (TASK-05) ------------------------------------------------------------

    // Status tujuan mengikuti next_statuses dari backend; keputusan QA mengikuti can_review.
    get transitions() {
        if (!this.task) return [];

        const backendStatuses = new Set((this.task.next_statuses ?? []).map(({ value }) => value));

        return availableTransitions(this.task, this.user)
            .filter((transition) => (transition.review ? this.task.can_review === true : backendStatuses.has(transition.to)));
    },

    async runTransition(transition) {
        if (transition.review) {
            this.startReview(transition.review);
            return;
        }

        this.acting = transition.to;

        try {
            const { data, message } = await changeTaskStatus(this.task.id, { status: transition.to, version: this.task.version });
            this.applyTask(data);
            this.load();
            showToast({ title: 'Status diperbarui', text: message ?? `Status menjadi ${statusOf(transition.to).label}.` });
        } catch (error) {
            if (error?.status === 422) {
                const errors = validationErrors(error);
                notifyFailure({ ...error, data: { ...error.data, message: errors.status ?? errors.pic_id } }, 'Status tugas gagal diperbarui.');
            } else {
                await this.handleFailure(error, 'Status tugas gagal diperbarui.');
            }
        } finally {
            this.acting = null;
        }
    },

    // --- Review QA (TASK-07) -------------------------------------------------------------------

    startReview(mode) {
        this.reviewMode = mode;
        this.reviewNote = '';
        this.reviewError = '';
        this.$nextTick(() => this.$refs.reviewNote?.focus());
    },

    async submitReview() {
        if (this.reviewMode === 'revision' && !this.reviewNote.trim()) {
            this.reviewError = 'Catatan alasan revisi wajib diisi.';
            return;
        }
        if (this.acting) return;

        const decision = this.reviewMode;
        this.acting = decision;

        try {
            const { data, message } = await reviewTask(this.task.id, {
                decision,
                note: this.reviewNote.trim() || null,
                version: this.task.version,
            });
            this.applyTask(data);
            this.reviewMode = null;
            showToast({
                title: decision === 'revision' ? 'Revisi diminta' : 'Tugas disetujui',
                text: message ?? `Status menjadi ${statusOf(data.status).label}.`,
            });
        } catch (error) {
            if (error?.status === 422) {
                const errors = validationErrors(error);
                this.reviewError = errors.note ?? errors.decision ?? taskErrorMessage(error, 'Keputusan review tidak valid.');
            } else {
                await this.handleFailure(error, 'Keputusan review gagal disimpan.');
            }
        } finally {
            this.acting = null;
        }
    },

    // --- Evidence (TASK-06) ---------------------------------------------------------------------

    evidenceAccept: EVIDENCE_EXTENSIONS.map((extension) => `.${extension}`).join(','),
    evidenceHint: `${EVIDENCE_EXTENSIONS.join(', ').toUpperCase()} · maks. ${EVIDENCE_MAX_MB} MB`,

    pickEvidence(event) {
        this.evidenceForm.file = event.target.files[0] ?? null;
        this.evidenceErrors = {};
    },

    validateEvidence() {
        const errors = {};
        const { type, file, url } = this.evidenceForm;

        if (type === 'file') {
            const extension = file?.name.split('.').pop()?.toLowerCase();
            if (!file) errors.file = 'Pilih file evidence.';
            else if (!EVIDENCE_EXTENSIONS.includes(extension)) errors.file = `Format .${extension} tidak diperbolehkan.`;
            else if (file.size > EVIDENCE_MAX_MB * 1024 * 1024) errors.file = `Ukuran file maksimal ${EVIDENCE_MAX_MB} MB.`;
        } else if (!isValidUrl(url.trim())) {
            errors.url = 'Masukkan tautan lengkap yang valid, diawali http:// atau https://.';
        }

        this.evidenceErrors = errors;

        return !Object.keys(errors).length;
    },

    async sendEvidence() {
        if (this.sendingEvidence || !this.validateEvidence()) return;

        this.sendingEvidence = true;
        const { type, file, url, note } = this.evidenceForm;

        try {
            const { data, message } = await submitEvidence(this.task.id, { file: type === 'file' ? file : null, url: type === 'link' ? url.trim() : '', note: note.trim() });
            this.evidences = [data, ...this.evidences];
            this.applyTask({ evidence_count: (this.task.evidence_count ?? 0) + 1 });
            this.evidenceForm = { type, file: null, url: '', note: '' };
            if (this.$refs.evidenceFile) this.$refs.evidenceFile.value = '';
            showToast({ title: 'Evidence terkirim', text: message ?? 'Bukti pekerjaan berhasil disimpan.' });
        } catch (error) {
            if (error?.status === 422) this.evidenceErrors = validationErrors(error);
            else notifyFailure(error, 'Evidence gagal dikirim.');
        } finally {
            this.sendingEvidence = false;
        }
    },
}));

/**
 * Timeline (TASK-10): rentang mulai–deadline per item dalam periode 1 atau 2 minggu.
 */
const mondayOf = (iso) => {
    const date = parseISODate(iso);
    const offset = (date.getDay() + 6) % 7;

    return addDays(iso, -offset);
};

const dayLabel = new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric' });
const monthLabel = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' });

Alpine.data('taskTimeline', () => ({
    mode: 'week',
    start: mondayOf(today()),
    items: [],
    state: 'idle',
    message: '',

    init() {
        this.$watch('view', (view) => view === 'timeline' && this.loadPeriod());
        this.$watch('filters.division', () => this.view === 'timeline' && this.loadPeriod());
        if (this.view === 'timeline') this.loadPeriod();
    },

    get length() {
        return this.mode === 'week' ? 7 : 14;
    },

    get end() {
        return addDays(this.start, this.length - 1);
    },

    get days() {
        return Array.from({ length: this.length }, (_, index) => {
            const iso = addDays(this.start, index);

            return { iso, label: dayLabel.format(parseISODate(iso)), today: iso === today(), weekend: [0, 6].includes(parseISODate(iso).getDay()) };
        });
    },

    get periodLabel() {
        return `${formatDate(this.start)} – ${formatDate(this.end)}`;
    },

    setMode(mode) {
        this.mode = mode;
        this.loadPeriod();
    },

    shift(direction) {
        this.start = addDays(this.start, direction * this.length);
        this.loadPeriod();
    },

    goToday() {
        this.start = mondayOf(today());
        this.loadPeriod();
    },

    async loadPeriod() {
        this.state = 'loading';

        try {
            const { data } = await fetchSchedule({ from: this.start, to: this.end, division: this.filters.division });
            this.items = data;
            this.state = 'ready';
        } catch (error) {
            this.items = [];
            this.state = error?.unavailable ? 'unavailable' : 'error';
            this.message = taskErrorMessage(error, 'Jadwal gagal dimuat.');
        }
    },

    // Item tanpa tanggal mulai digambar satu hari di deadline (dan sebaliknya); tanpa keduanya tidak tampil.
    get rows() {
        const total = this.length;

        return this.items
            // Filter tugas (cari, prioritas, status) ikut berlaku; agenda bersama selalu tampil.
            .filter((item) => item.type === 'event' || this.matchesFilters(item))
            .map((item) => {
                const from = item.start_date ?? item.end_date;
                const to = item.end_date ?? item.start_date;
                if (!from || !to || to < this.start || from > this.end) return null;

                const startIndex = Math.max(0, Math.round((parseISODate(from) - parseISODate(this.start)) / 864e5));
                const endIndex = Math.min(total - 1, Math.round((parseISODate(to) - parseISODate(this.start)) / 864e5));

                return {
                    ...item,
                    partial: !item.start_date || !item.end_date,
                    overdue: item.type === 'task' && Boolean(item.end_date) && item.status !== 'done' && item.end_date.slice(0, 10) < today(),
                    left: `${(startIndex / total) * 100}%`,
                    width: `${((endIndex - startIndex + 1) / total) * 100}%`,
                };
            })
            .filter(Boolean)
            .sort((a, b) => (a.start_date ?? a.end_date).localeCompare(b.start_date ?? b.end_date));
    },

    get undatedCount() {
        return this.items.filter((item) => !item.start_date && !item.end_date).length;
    },

    select(item) {
        if (item.type === 'task' && item.can_view_detail) {
            this.openTask(item);
            return;
        }

        showToast({
            type: 'info',
            title: item.title,
            text: `${item.division?.name ?? 'Lintas divisi'} · ${formatDate(item.start_date ?? item.end_date)}${item.end_date && item.start_date ? ` – ${formatDate(item.end_date)}` : ''}${item.type === 'task' ? ' · Detail hanya untuk pengguna berwenang.' : ''}`,
            duration: 5000,
        });
    },
}));

/**
 * Calendar (TASK-11): agenda bersama lintas divisi + daftar deadline sesuai hak akses.
 */
Alpine.data('taskCalendar', () => ({
    month: today().slice(0, 7),
    selected: today(),
    events: [],
    deadlines: [],
    state: 'idle',
    message: '',
    weekdays: ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'],
    // Ringkasan item yang dipilih: { kind: 'event' | 'task', item }.
    picked: null,

    init() {
        this.$watch('selected', () => (this.picked = null));
        this.$watch('view', (view) => view === 'calendar' && this.loadPeriod());
        this.$watch('filters.division', () => this.view === 'calendar' && this.loadPeriod());
        if (this.view === 'calendar') this.loadPeriod();
    },

    get monthLabel() {
        return monthLabel.format(parseISODate(`${this.month}-01`));
    },

    // 6 minggu (Senin–Minggu) yang memuat bulan aktif.
    get cells() {
        const first = `${this.month}-01`;
        const start = mondayOf(first);

        return Array.from({ length: 42 }, (_, index) => {
            const iso = addDays(start, index);

            return {
                iso,
                day: parseISODate(iso).getDate(),
                inMonth: iso.startsWith(this.month),
                today: iso === today(),
                events: this.eventsOn(iso).length,
                deadlines: this.deadlinesOn(iso).length,
                overdue: this.deadlinesOn(iso).some((task) => isOverdue(task)),
            };
        });
    },

    eventsOn(iso) {
        return this.events.filter((event) => {
            const from = event.start_date ?? event.date;
            const to = event.end_date ?? from;

            return from && from.slice(0, 10) <= iso && to.slice(0, 10) >= iso;
        });
    },

    // Deadline mengikuti filter tugas yang sama dengan Board/Table; agenda bersama tidak disaring.
    deadlinesOn(iso) {
        return this.deadlines.filter((task) => task.deadline?.slice(0, 10) === iso && this.matchesFilters(task));
    },

    pick(kind, item) {
        this.picked = this.picked?.kind === kind && this.picked.item.id === item.id ? null : { kind, item };
    },

    isPicked(kind, item) {
        return this.picked?.kind === kind && this.picked.item.id === item.id;
    },

    get selectedEvents() {
        return this.eventsOn(this.selected);
    },

    get selectedDeadlines() {
        return this.deadlinesOn(this.selected);
    },

    shift(direction) {
        const date = parseISODate(`${this.month}-01`);
        date.setMonth(date.getMonth() + direction);
        this.month = toISODate(date).slice(0, 7);
        this.selected = this.selected.startsWith(this.month) ? this.selected : `${this.month}-01`;
        this.loadPeriod();
    },

    goToday() {
        this.month = today().slice(0, 7);
        this.selected = today();
        this.loadPeriod();
    },

    async loadPeriod() {
        this.state = 'loading';

        try {
            const { data } = await fetchCalendar({ month: this.month, division: this.filters.division });
            this.events = data.events ?? [];
            this.deadlines = data.deadlines ?? [];
            this.state = 'ready';
        } catch (error) {
            this.events = [];
            this.deadlines = [];
            this.state = error?.unavailable ? 'unavailable' : 'error';
            this.message = taskErrorMessage(error, 'Kalender gagal dimuat.');
        }
    },
}));
