import Alpine from 'alpinejs';
import { showToast } from '../../components/toast';
import { errorMessage, http, validationErrors } from '../../http';
import {
    assignTask,
    changeTaskStatus,
    createTask,
    fetchAssignees,
    fetchCalendar,
    fetchEditOptions,
    fetchSchedule,
    fetchTask,
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
    managesTask,
    parseISODate,
    priorityOf,
    statusOf,
    summarize,
    toISODate,
    today,
    transitionRule,
    userContext,
} from './workflow';
import { historyEntries } from './history';

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
        { key: 'board', label: 'Board' },
        { key: 'table', label: 'Table' },
        { key: 'timeline', label: 'Timeline' },
        { key: 'calendar', label: 'Calendar' },
    ],
    view: 'board',

    user: userContext(Alpine.store('session').user),
    meta: null,
    divisions: [],
    platforms: [],
    categories: [],

    tasks: [],
    status: null,
    loading: true,
    error: '',

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
        this.$watch('filters.division', () => this.load());
        this.$watch('filters', () => (this.page = 1));

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
            this.categories = data.categories ?? [];
            this.platforms = data.platforms ?? [];
            this.filters.division = this.defaultDivision();
        } catch {
            // Meta tugas menunggu backend: pakai master divisi & platform yang sudah tersedia.
            await Promise.all([this.loadDivisions(), this.loadPlatforms()]);
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

    async loadPlatforms() {
        try {
            const { data } = await http('/api/products/filters');
            this.platforms = data.platforms ?? [];
        } catch {
            this.platforms = [];
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
        return this.meta ? Boolean(this.meta.can_create) : this.user.role !== 'staff';
    },

    get hasActiveFilter() {
        return ['search', 'priority', 'status'].some((key) => this.filters[key] !== '') || this.filters.division !== this.defaultDivision();
    },

    resetFilters() {
        this.filters = { search: '', division: this.defaultDivision(), priority: '', status: '' };
        this.sort = { key: 'deadline', dir: 'asc' };
    },

    async load() {
        this.loading = true;
        this.error = '';

        try {
            const { data } = await fetchTasks({ division: this.filters.division });
            this.tasks = data;
            this.status = 'ready';
        } catch (error) {
            this.tasks = [];
            this.status = error?.unavailable ? 'unavailable' : 'error';
            this.error = taskErrorMessage(error, 'Gagal memuat daftar tugas.');
        } finally {
            this.loading = false;
            if (this.highlightId) this.$nextTick(() => this.revealHighlight());
        }
    },

    /**
     * Pencarian, prioritas, dan status difilter di browser. Fungsi yang sama dipakai Board, Table,
     * ringkasan, Timeline, dan daftar deadline Calendar agar data selalu konsisten. Atribut yang tidak
     * dimiliki item (mis. agenda tanpa prioritas) tidak menyaring item tersebut.
     */
    matchesFilters(item) {
        const keyword = this.filters.search.trim().toLowerCase();

        return (!keyword || (item.title ?? '').toLowerCase().includes(keyword))
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
        if (!this.tasks.length) return 'none';

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

    // Approve lewat drag-and-drop (On QA → Done) memakai endpoint review; transisi lain memakai endpoint status.
    async moveTask(task, transition) {
        this.movingId = task.id;

        try {
            const { data, message } = transition.review === 'approve'
                ? await reviewTask(task.id, { decision: 'approve', updated_at: task.updated_at })
                : await changeTaskStatus(task.id, { status: transition.to, updated_at: task.updated_at });
            this.syncTask(data);
            showToast({ title: 'Status diperbarui', text: message ?? `${task.title} → ${statusOf(transition.to).label}` });
        } catch (error) {
            notifyFailure(error, 'Status tugas gagal diperbarui.');
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
    division: '',
    pic_id: '',
    platform: '',
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
    requestId: null,

    assignees: [],
    assigneeState: 'idle',

    init() {
        this.$watch('createOpen', (open) => open && this.reset());
        this.$watch('form.division', (division) => {
            this.form.pic_id = '';
            this.loadAssignees(division);
        });
    },

    reset() {
        this.form = { ...emptyTask(), division: this.user.role === 'manager' ? (this.user.division ?? '') : '' };
        this.errors = {};
        this.formError = '';
        // Satu ID per pengisian form: pengiriman berulang dikenali backend sebagai permintaan yang sama.
        this.requestId = crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;
        this.$nextTick(() => this.$refs.createTitle?.focus());
    },

    // Admin: semua divisi. Manajer: hanya divisi yang dikelolanya.
    get creatableDivisions() {
        return this.divisionOptions;
    },

    get selectedCategory() {
        return this.categories.find((category) => String(category.id) === String(this.form.category_id)) ?? null;
    },

    // Kewajiban QA ditentukan oleh kategori tugas, bukan dipilih manual.
    get requiresReview() {
        return this.selectedCategory ? Boolean(this.selectedCategory.requires_review) : null;
    },

    async loadAssignees(division) {
        this.assignees = [];
        if (!division) {
            this.assigneeState = 'idle';
            return;
        }

        this.assigneeState = 'loading';

        try {
            const { data } = await fetchAssignees(division);
            this.assignees = data;
            this.assigneeState = 'ready';
        } catch (error) {
            this.assigneeState = error?.unavailable ? 'unavailable' : 'error';
        }
    },

    validate() {
        const errors = {};
        const { title, division, status, pic_id, start_date, deadline } = this.form;

        if (!title.trim()) errors.title = 'Judul tugas wajib diisi.';
        else if (title.trim().length > 150) errors.title = 'Judul tugas maksimal 150 karakter.';
        if (!division) errors.division = 'Target divisi wajib dipilih.';
        if (status === 'todo' && !pic_id) errors.pic_id = 'Tugas berstatus To Do wajib memiliki PIC.';
        if (start_date && deadline && deadline < start_date) errors.deadline = 'Deadline tidak boleh sebelum tanggal mulai.';

        this.errors = errors;

        return !Object.keys(errors).length;
    },

    async submit() {
        if (this.saving || !this.validate()) return;

        this.saving = true;
        this.formError = '';
        this.formPending = false;

        try {
            const { data, message } = await createTask({
                ...this.form,
                title: this.form.title.trim(),
                description: this.form.description.trim(),
                requires_review: this.requiresReview,
                client_request_id: this.requestId,
            });
            this.syncTask(data);
            this.createOpen = false;
            showToast({ title: 'Task Created', text: message ?? `${data.title} ditambahkan ke ${statusOf(data.status).label}.` });
        } catch (error) {
            if (error?.status === 422) {
                this.errors = validationErrors(error);
                this.formError = 'Periksa kembali isian yang ditandai.';
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

// Field form edit; nama sama dengan field API dan editable_fields dari backend.
const EDIT_FIELDS = ['title', 'description', 'category_id', 'platform_id', 'priority', 'start_date', 'deadline'];

// Nilai form → nilai yang dikirim ke API: teks dirapikan, pilihan kosong menjadi null, ID menjadi angka.
const editPayload = (form) => ({
    title: form.title.trim(),
    description: form.description.trim() || null,
    category_id: form.category_id ? Number(form.category_id) : null,
    platform_id: form.platform_id ? Number(form.platform_id) : null,
    priority: form.priority,
    start_date: form.start_date || null,
    deadline: form.deadline || null,
});

// Board dan Table hanya butuh data ringkas; histori, evidence, dan aturan edit tetap di panel detail.
const listFields = ({ history, evidence, editable_fields, next_statuses, ...task }) => task;

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
    editForm: {},
    editBase: {},
    editErrors: {},
    editFormError: '',
    saving: false,
    editOptions: { categories: [], platforms: [] },
    editOptionsState: 'idle',

    assignees: [],
    assigneeState: 'idle',
    assignForm: { pic_id: '', note: '' },
    assigning: false,

    evidenceForm: { type: 'file', file: null, url: '', note: '' },
    evidenceErrors: {},
    sendingEvidence: false,

    reviewMode: null,
    reviewNote: '',
    reviewError: '',
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
            this.applyRelated(data);
            this.syncTask(listFields(data));
            // Tugas yang belum ada di daftar baru masuk Board setelah detailnya dimuat.
            if (this.highlightId === data.id) this.$nextTick(() => this.revealHighlight());
            if (this.detailFocus === 'revision') this.startReview('revision');
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
        this.applyRelated(data);
        this.syncTask(listFields(this.task));
    },

    // Evidence dan histori ikut dalam respons detail/update, jadi tidak perlu permintaan terpisah.
    applyRelated(data) {
        if (Array.isArray(data.evidence)) {
            this.evidences = data.evidence;
            this.evidenceState = 'ready';
        }
        if (Array.isArray(data.history)) {
            this.activities = historyEntries(data.history);
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

    // "Coba lagi" pada histori: ambil ulang detail tugas yang memuat histori terbaru.
    async loadActivities() {
        this.activityState = 'loading';

        try {
            const { data } = await fetchTask(this.task.id);
            this.applyTask(data);
        } catch {
            this.activityState = 'error';
        }
    },

    // --- Hak akses pada tugas yang dibuka -----------------------------------------------------

    get transitions() {
        return this.task ? availableTransitions(this.task, this.user) : [];
    },

    get isPicOfTask() {
        return this.task?.pic?.id !== undefined && this.task?.pic?.id === this.user.id;
    },

    // Backend menentukan field yang boleh diubah sesuai role dan kondisi tugas (mis. Done hanya status).
    get canEditThis() {
        if (!this.task) return false;
        if (Array.isArray(this.task.editable_fields)) return EDIT_FIELDS.some((field) => this.task.editable_fields.includes(field));

        return canEditTask(this.task, this.user);
    },

    isEditable(field) {
        return Array.isArray(this.task?.editable_fields) ? this.task.editable_fields.includes(field) : this.canEditThis;
    },

    get assignment() {
        return this.task ? assignmentRule(this.task, this.user) : { ok: false, reason: '' };
    },

    get canSubmitEvidence() {
        return this.isPicOfTask && ['todo', 'in_progress'].includes(this.task?.status);
    },

    get canReview() {
        return this.task ? managesTask(this.task, this.user) : false;
    },

    // --- Edit informasi utama (TASK-03 C) -----------------------------------------------------

    startEdit() {
        const { title, description, category, platform, priority, start_date, deadline } = this.task;
        this.editForm = {
            title,
            description: description ?? '',
            category_id: category?.id ? String(category.id) : '',
            platform_id: platform?.id ? String(platform.id) : '',
            priority,
            start_date: start_date ?? '',
            deadline: deadline ?? '',
        };
        this.editBase = editPayload(this.editForm);
        this.editErrors = {};
        this.editFormError = '';
        this.editing = true;
        this.loadEditOptions();
    },

    async loadEditOptions() {
        if (this.editOptionsState === 'ready' || this.editOptionsState === 'loading') return;

        this.editOptionsState = 'loading';

        try {
            const { data } = await fetchEditOptions();
            this.editOptions = { categories: data.categories ?? [], platforms: data.platforms ?? [] };
            this.editOptionsState = 'ready';
        } catch {
            this.editOptionsState = 'error';
        }
    },

    // Pilihan kategori/platform; nilai tugas saat ini tetap ada meski opsi gagal dimuat.
    editChoices(kind) {
        const current = this.task?.[kind === 'categories' ? 'category' : 'platform'];
        const options = this.editOptions[kind];

        return current && !options.some((option) => option.id === current.id) ? [current, ...options] : options;
    },

    get editCategoryRequiresReview() {
        const id = Number(this.editForm.category_id);

        return Boolean(id && this.editChoices('categories').find((category) => category.id === id)?.requires_review);
    },

    async saveEdit() {
        const errors = {};
        if (!this.editForm.title.trim()) errors.title = 'Judul tugas wajib diisi.';
        if (this.editForm.start_date && this.editForm.deadline && this.editForm.deadline < this.editForm.start_date) {
            errors.deadline = 'Deadline tidak boleh sebelum tanggal mulai.';
        }
        this.editErrors = errors;
        this.editFormError = '';
        if (Object.keys(errors).length || this.saving) return;

        // Hanya field yang berubah dikirim, sehingga data lain tidak ikut tertimpa.
        const changes = Object.fromEntries(
            Object.entries(editPayload(this.editForm)).filter(([field, value]) => value !== this.editBase[field] && this.isEditable(field)),
        );

        if (!Object.keys(changes).length) {
            this.editing = false;
            showToast({ type: 'info', title: 'Tidak ada perubahan', text: 'Informasi tugas tidak berubah.' });
            return;
        }

        this.saving = true;

        try {
            // version dikirim agar backend menolak perubahan yang berbasis data lama (409).
            const { data, message } = await updateTask(this.task.id, { ...changes, version: this.task.version });
            this.applyTask(data);
            this.editing = false;
            showToast({ title: 'Changes Saved', text: message ?? 'Informasi tugas diperbarui.' });
        } catch (error) {
            if (error?.status === 422) this.showEditErrors(validationErrors(error));
            else await this.handleFailure(error, 'Perubahan tugas gagal disimpan.');
        } finally {
            this.saving = false;
        }
    },

    // Error untuk field di form tampil di bawah field-nya; error lain (mis. aturan QA) tampil di atas tombol simpan.
    async showEditErrors(errors) {
        if (errors.version) {
            await this.handleFailure({ status: 409 });
            return;
        }

        this.editErrors = Object.fromEntries(Object.entries(errors).filter(([field]) => EDIT_FIELDS.includes(field)));
        const others = Object.entries(errors).filter(([field]) => !EDIT_FIELDS.includes(field)).map(([, message]) => message);
        this.editFormError = others.length ? others.join(' ') : 'Periksa kembali isian yang ditandai.';
    },

    // --- Penugasan PIC (TASK-04) ----------------------------------------------------------------

    async openAssign() {
        this.assignForm = { pic_id: '', note: '' };
        this.assigneeState = 'loading';

        try {
            const { data } = await fetchAssignees(this.task.division?.code_division);
            this.assignees = data.filter((user) => user.id !== this.task.pic?.id);
            this.assigneeState = 'ready';
        } catch (error) {
            this.assigneeState = error?.unavailable ? 'unavailable' : 'error';
        }
    },

    async saveAssign() {
        if (!this.assignForm.pic_id || this.assigning) return;

        this.assigning = true;

        try {
            const { data, message } = await assignTask(this.task.id, { ...this.assignForm, updated_at: this.task.updated_at });
            this.applyTask(data);
            this.assigneeState = 'idle';
            showToast({ title: 'PIC diperbarui', text: message ?? `Tugas kini ditangani ${data.pic?.fullname ?? 'PIC baru'}.` });
        } catch (error) {
            // Penugasan yang ditolak tidak mengubah PIC yang tampil: data tugas tidak disentuh.
            await this.handleFailure(error, 'PIC gagal diperbarui.');
        } finally {
            this.assigning = false;
        }
    },

    // --- Workflow status (TASK-05) & review (TASK-07) -----------------------------------------

    async runTransition(transition) {
        if (transition.review === 'revision') {
            this.startReview('revision');
            return;
        }
        if (transition.review === 'approve') {
            this.startReview('approve');
            return;
        }

        this.acting = transition.to;

        try {
            const { data, message } = await changeTaskStatus(this.task.id, { status: transition.to, updated_at: this.task.updated_at });
            this.applyTask(data);
            showToast({ title: 'Status diperbarui', text: message ?? `Status menjadi ${statusOf(transition.to).label}.` });
        } catch (error) {
            await this.handleFailure(error, 'Status tugas gagal diperbarui.');
        } finally {
            this.acting = null;
        }
    },

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
                note: this.reviewNote.trim(),
                updated_at: this.task.updated_at,
            });
            this.applyTask(data);
            this.reviewMode = null;
            showToast({
                title: decision === 'revision' ? 'Revisi diminta' : 'Tugas disetujui',
                text: message ?? `Status menjadi ${statusOf(data.status).label}.`,
            });
        } catch (error) {
            if (error?.status === 422) this.reviewError = validationErrors(error).note ?? taskErrorMessage(error, 'Keputusan review tidak valid.');
            else await this.handleFailure(error, 'Keputusan review gagal disimpan.');
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
