import Alpine from 'alpinejs';
import { showToast } from '../../components/toast';
import { errorMessage, http, validationErrors } from '../../http';
import {
    assignTask,
    changeTaskStatus,
    createTask,
    deleteEvidence,
    deleteTask,
    postComment,
    fetchAssignmentOptions,
    fetchCalendar,
    fetchDivisions,
    fetchSchedule,
    fetchActivities,
    fetchComments,
    fetchTask,
    fetchTaskMonitoring,
    fetchTaskPreview,
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
    isTaskPic,
    roleAccessOf,
    formatDate,
    formatDateTime,
    isOverdue,
    parseISODate,
    priorityOf,
    statusOf,
    toISODate,
    today,
    transitionRule,
    userContext,
} from './workflow';

const VIEWS = ['board', 'table', 'timeline', 'calendar'];
const TASK_SCOPES = ['all_tasks', 'my_tasks'];
const MAX_PICS = 20;

// Nama metrik monitoring untuk tiap status Tasklist.
const STATUS_METRICS = { backlog: 'backlog', todo: 'todo', in_progress: 'on_process', ready_review: 'ready_review', on_review: 'on_review', done: 'completed' };

// Field yang memengaruhi angka ringkasan atau cakupan/filter tugas: perubahannya memuat ulang ringkasan.
// Field yang tidak dikirim (undefined) diabaikan; PIC atau divisi yang dilepas (null) tetap dihitung sebagai perubahan.
const nested = (value, key) => (value === undefined ? undefined : (value?.[key] ?? null));
const SUMMARY_FIELDS = [
    (task) => task.status,
    (task) => task.start_date,
    (task) => task.deadline,
    (task) => task.priority,
    (task) => task.title,
    (task) => nested(task.division, 'code_division'),
    (task) => task.pics?.map(({ id }) => id).join(','),
];

const affectsSummary = (before, after) => !before || SUMMARY_FIELDS.some((field) => field(after) !== undefined && field(after) !== field(before));
const PAGE_SIZE = 10;
const COMMENT_MAX = 2000;

// Gabungan komentar berdasarkan ID (data backend menang), berurutan waktu seperti GET comments.
const mergeComments = (shown, loaded) => [...new Map([...shown, ...loaded].map((comment) => [comment.id, comment])).values()]
    .sort((a, b) => (a.created_at ?? '').localeCompare(b.created_at ?? '') || a.id - b.id);

// client_request_id untuk satu pengiriman komentar. crypto.randomUUID hanya ada di konteks aman (HTTPS/localhost),
// jadi di HTTP biasa UUID v4 disusun dari getRandomValues.
function newRequestId() {
    const api = globalThis.crypto;
    if (typeof api?.randomUUID === 'function') return api.randomUUID();

    const bytes = typeof api?.getRandomValues === 'function'
        ? api.getRandomValues(new Uint8Array(16))
        : Uint8Array.from({ length: 16 }, () => Math.floor(Math.random() * 256));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

// Backend mengirim kesalahan PIC di pic_ids atau pic_ids.N; keduanya ditampilkan pada satu field.
const picError = (errors) => Object.entries(errors).find(([key]) => key === 'pic_ids' || key.startsWith('pic_ids.'))?.[1];

// Ringkasan Tugas Saya dihitung dari daftar my_tasks, karena monitoring hanya mencakup seluruh tugas.
function myTaskMetrics(tasks, asOf) {
    const active = tasks.filter((task) => task.status !== 'done');
    const count = (status) => tasks.filter((task) => task.status === status).length;
    const completed = count('done');

    return {
        total_tasks: tasks.length,
        backlog: count('backlog'),
        todo: count('todo'),
        on_process: count('in_progress'),
        ready_review: count('ready_review'),
        on_review: count('on_review'),
        completed,
        unfinished: active.length,
        overdue: active.filter((task) => task.deadline && task.deadline.slice(0, 10) < asOf).length,
        due_today: active.filter((task) => task.deadline?.slice(0, 10) === asOf).length,
        urgent: active.filter((task) => task.priority === 'urgent').length,
        completion_rate: tasks.length ? Math.round((completed / tasks.length) * 10000) / 100 : 0,
    };
}
const BACKEND_PENDING = 'Menunggu pengerjaan Back End.';
const PERIOD_DATE_FORMAT = /^\d{4}-\d{2}-\d{2}$/;
const PERIOD_YEAR_FORMAT = /^[1-9]\d{3}$/;
const PERIOD_MONTH_FORMAT = /^[1-9]\d{3}-(0[1-9]|1[0-2])$/;
const PERIOD_FIELDS = ['filter_mode', 'date_field', 'date', 'month', 'year', 'start_date', 'end_date'];
const PERIOD_MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

/**
 * Periode dari form filter dalam bentuk parameter TASK-14 (GET /api/tasks dan monitoring), beserta rentang hari
 * inklusifnya untuk menyaring kartu yang ditambahkan di luar daftar. Hanya parameter milik mode aktif yang dikirim.
 */
function periodOf({ mode, basis, date, month, year, startDate, endDate }) {
    const base = { filter_mode: mode, date_field: basis };

    if (mode === 'date') return { params: { ...base, date }, from: date, to: date };
    if (mode === 'range') return { params: { ...base, start_date: startDate, end_date: endDate }, from: startDate, to: endDate };
    if (mode === 'year') {
        const value = String(year);

        return { params: { ...base, year: value }, from: `${value}-01-01`, to: `${value}-12-31` };
    }

    const [monthYear, monthNumber] = month.split('-');
    const lastDay = new Date(Number(monthYear), Number(monthNumber), 0).getDate();

    return {
        params: { ...base, month: String(Number(monthNumber)), year: monthYear },
        from: `${month}-01`,
        to: `${month}-${String(lastDay).padStart(2, '0')}`,
    };
}

function periodLabelOf({ params }) {
    const basis = params.date_field === 'start_date' ? 'Tanggal Mulai' : 'Deadline';
    const value = {
        date: () => formatDate(params.date),
        month: () => `${PERIOD_MONTHS[Number(params.month) - 1]} ${params.year}`,
        year: () => params.year,
        range: () => `${formatDate(params.start_date)} – ${formatDate(params.end_date)}`,
    }[params.filter_mode]();

    return `${basis}: ${value}`;
}

const emptyPeriodFilter = () => ({
    mode: '',
    basis: 'deadline',
    date: '',
    month: '',
    year: String(new Date().getFullYear()),
    startDate: '',
    endDate: '',
});

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
    pic: (task) => task.pics?.[0]?.fullname?.toLowerCase() ?? '',
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
    scopes: [
        { key: 'all_tasks', label: 'Semua Tugas' },
        { key: 'my_tasks', label: 'Tugas Saya' },
    ],
    taskScope: 'all_tasks',

    user: userContext(Alpine.store('session').user),
    meta: null,
    divisions: [],

    tasks: [],
    status: null,
    loading: true,
    error: '',
    loadSequence: 0,

    monitoring: null,
    summaryState: 'loading',
    summaryError: '',
    summarySequence: 0,
    summaryQueued: false,

    filters: { search: '', division: '', priority: '', status: '', pic: '' },
    periodFilter: emptyPeriodFilter(),
    appliedPeriod: null,
    periodError: '',
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
        const requestedScope = new URLSearchParams(location.search).get('scope');
        if (TASK_SCOPES.includes(requestedScope)) this.taskScope = requestedScope;
        this.$watch('taskScope', (scope) => {
            const url = new URL(location.href);
            url.searchParams.set('scope', scope);
            history.replaceState(null, '', url);
            this.page = 1;
            this.load();
        });
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

    // Awal filter divisi mengikuti backend (Manajer: divisinya); semua role dapat memilih divisi mana pun.
    defaultDivision() {
        if (this.meta) return this.meta.default_division ?? '';

        return this.user.role === 'manager' ? (this.user.division ?? '') : '';
    },

    get divisionOptions() {
        return this.divisions;
    },

    get roleAccess() {
        return roleAccessOf(this.user.roleCode);
    },

    get divisionScopeLabel() {
        const division = this.divisions.find((item) => item.code_division === this.filters.division);
        if (division) return `Divisi ${division.name}`;

        return this.filters.division ? `Divisi ${this.filters.division}` : 'Semua Divisi';
    },

    get scopeLabel() {
        return this.taskScope === 'my_tasks' ? `Tugas Saya · ${this.divisionScopeLabel}` : this.divisionScopeLabel;
    },

    get canCreate() {
        // Diputuskan backend lewat meta; bila meta gagal dimuat, tombol hanya tampil untuk Admin dan Manajer.
        return this.meta?.can_create ?? (this.user.role === 'admin' || this.user.role === 'manager');
    },

    get hasActiveFilter() {
        return ['search', 'priority', 'status', 'pic'].some((key) => this.filters[key] !== '')
            || this.filters.division !== this.defaultDivision()
            || this.appliedPeriod !== null;
    },

    get hasPeriodConfiguration() {
        const { mode, basis, date, month, year, startDate, endDate } = this.periodFilter;

        return mode !== ''
            || basis !== 'deadline'
            || Boolean(date || month || startDate || endDate)
            || String(year) !== String(new Date().getFullYear());
    },

    // Tahun pada mode Tahun dan mode Bulan mengikuti batas backend (1000–9999); input bulan browser menerima tahun 0999.
    get periodYearError() {
        const { mode, year, month } = this.periodFilter;
        const valid = {
            year: () => PERIOD_YEAR_FORMAT.test(String(year)),
            month: () => !month || PERIOD_MONTH_FORMAT.test(month),
        }[mode]?.() ?? true;

        return valid ? '' : 'Masukkan tahun 4 digit, misalnya 2009.';
    },

    // Tanggal dibandingkan sebagai teks, sehingga tahun harus tepat 4 digit agar urutannya benar.
    get periodRangeError() {
        const { mode, startDate, endDate } = this.periodFilter;

        if (mode !== 'range' || !startDate || !endDate) return '';
        if (!PERIOD_DATE_FORMAT.test(startDate) || !PERIOD_DATE_FORMAT.test(endDate)) return 'Format tanggal tidak valid.';

        return endDate < startDate ? 'Tanggal akhir harus sama dengan atau setelah tanggal awal.' : '';
    },

    get canConfirmPeriodFilter() {
        const { mode, date, month, year, startDate, endDate } = this.periodFilter;

        if (mode === 'date') return PERIOD_DATE_FORMAT.test(date);
        if (mode === 'month') return Boolean(month) && !this.periodYearError;
        if (mode === 'year') return !this.periodYearError;
        if (mode === 'range') return Boolean(startDate && endDate) && !this.periodRangeError;

        // "Tanpa filter periode" hanya dapat diterapkan untuk menghapus periode yang sedang aktif.
        return this.appliedPeriod !== null;
    },

    get appliedPeriodLabel() {
        return this.appliedPeriod ? periodLabelOf(this.appliedPeriod) : '';
    },

    // Periode baru berlaku untuk Board, Table, dan KPI sekaligus, pada cakupan Semua Tugas maupun Tugas Saya.
    confirmPeriodFilter() {
        if (!this.canConfirmPeriodFilter) return;

        this.appliedPeriod = this.periodFilter.mode ? periodOf(this.periodFilter) : null;
        this.page = 1;

        return this.load();
    },

    clearPeriod() {
        this.periodFilter = emptyPeriodFilter();
        this.appliedPeriod = null;
        this.periodError = '';
    },

    resetFilters() {
        this.filters = { search: '', division: this.defaultDivision(), priority: '', status: '', pic: '' };
        this.clearPeriod();
        this.sort = { key: 'deadline', dir: 'asc' };
    },

    get requestFilters() {
        return {
            search: this.filters.search.trim(),
            division: this.filters.division,
            priority: this.filters.priority,
            status: this.filters.status,
            pic_id: this.filters.pic,
            ...this.appliedPeriod?.params,
        };
    },

    // PIC aktif dari tugas yang boleh dilihat akun ini (meta); kosong bila meta gagal dimuat.
    get picOptions() {
        return this.meta?.pics ?? [];
    },

    // Sama seperti pic_id di backend: tugas dengan akun itu sebagai salah satu PIC aktif.
    matchesPic(task) {
        return !this.filters.pic || (task.pics ?? []).some(({ id }) => String(id) === this.filters.pic);
    },

    // Tugas tanpa tanggal pada dasar tanggal yang dipilih tidak masuk periode, sama seperti backend.
    matchesPeriod(task) {
        if (!this.appliedPeriod) return true;

        const value = task[this.appliedPeriod.params.date_field]?.slice(0, 10);

        return Boolean(value) && value >= this.appliedPeriod.from && value <= this.appliedPeriod.to;
    },

    // Daftar tugas dan ringkasan KPI dimuat bersamaan dengan filter yang sama.
    async load() {
        const sequence = ++this.loadSequence;
        this.loading = true;
        this.error = '';
        this.periodError = '';
        const summary = this.loadSummary();

        try {
            const { data } = await fetchTasks({ scope: this.taskScope, ...this.requestFilters });
            if (sequence !== this.loadSequence) return;

            this.tasks = data;
            this.status = 'ready';
        } catch (error) {
            if (sequence !== this.loadSequence) return;

            this.tasks = [];
            this.status = error?.unavailable ? 'unavailable' : 'error';
            this.error = taskErrorMessage(error, 'Gagal memuat daftar tugas.');
            // Periode yang ditolak backend (422) dijelaskan di form periode.
            if (error?.status === 422) {
                const errors = validationErrors(error);
                this.periodError = PERIOD_FIELDS.map((field) => errors[field]).find(Boolean) ?? '';
            }
        } finally {
            if (sequence === this.loadSequence) {
                this.loading = false;
                if (this.taskScope === 'my_tasks') this.loadSummary();
                if (this.highlightId) this.$nextTick(() => this.revealHighlight());
            }
        }

        await summary;
    },

    // Ringkasan TASK-09 dari GET /api/tasks/monitoring (scope=tasklist): angkanya dihitung backend dalam cakupan
    // dan filter yang sama dengan Board/Table. Respons lama (filter sudah berubah) diabaikan.
    async loadSummary() {
        const sequence = ++this.summarySequence;
        this.summaryError = '';

        // Tugas Saya: angka mengikuti daftar my_tasks yang sedang dimuat.
        if (this.taskScope === 'my_tasks') {
            this.summaryState = this.loading ? 'loading' : (this.status === 'ready' ? 'ready' : 'error');
            this.summaryError = this.summaryState === 'error' ? this.error : '';
            return;
        }

        this.summaryState = 'loading';

        try {
            const { data } = await fetchTaskMonitoring(this.requestFilters);
            if (sequence !== this.summarySequence) return;
            this.monitoring = data;
            this.summaryState = 'ready';
        } catch (error) {
            if (sequence !== this.summarySequence) return;
            this.summaryState = 'error';
            this.summaryError = taskErrorMessage(error, 'Ringkasan tugas gagal dimuat.');
        }
    },

    // Beberapa perubahan dalam satu aksi digabung menjadi satu permintaan ringkasan.
    queueSummary() {
        if (this.summaryQueued) return;
        this.summaryQueued = true;
        setTimeout(() => {
            this.summaryQueued = false;
            this.loadSummary();
        });
    },

    /**
     * Filter tambahan untuk item Timeline/Calendar yang tidak diambil oleh daftar Task.
     * Timeline memakai filter divisinya sendiri (lintas divisi), sehingga divisi Tasklist dapat dilewati.
     */
    matchesFilters(item, { division = true } = {}) {
        const keyword = this.filters.search.trim().toLowerCase();

        return (!keyword || (item.title ?? '').toLowerCase().includes(keyword))
            && (!division || !this.filters.division || item.division?.code_division === this.filters.division)
            && (!this.filters.priority || !item.priority || item.priority === this.filters.priority)
            && (!this.filters.status || !item.status || item.status === this.filters.status);
    },

    // Tugas yang dibuka dari luar daftar atau PIC yang baru dilepas tidak tampil di Tugas Saya.
    get filteredTasks() {
        return this.tasks.filter((task) => this.matchesFilters(task)
            && this.matchesPic(task)
            && this.matchesPeriod(task)
            && (this.taskScope !== 'my_tasks' || isTaskPic(task, this.user)));
    },

    // Tanpa tugas dalam cakupan, backend mengirim semua angka 0.
    get summary() {
        if (this.taskScope === 'my_tasks') return myTaskMetrics(this.filteredTasks, this.monitoring?.as_of ?? today());

        return this.monitoring?.metrics ?? {};
    },

    // Angka hanya tampil untuk respons filter yang sedang aktif; selama dimuat atau gagal tampil "—",
    // agar angka filter sebelumnya tidak terbaca sebagai hasil filter baru.
    metric(key) {
        return this.summaryState === 'ready' ? (this.summary[key] ?? 0) : '—';
    },

    get completionRateText() {
        return this.summaryState === 'ready' ? `${(this.summary.completion_rate ?? 0).toLocaleString('id-ID')}%` : '—';
    },

    // Jumlah tiap status di ringkasan, terlihat di semua tampilan (kolom Board tersembunyi di Tabel/Linimasa/Kalender).
    get statusSummary() {
        return STATUSES.map((status) => ({ ...status, value: this.metric(STATUS_METRICS[status.key]) }));
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

    // Acuan hari ini sama dengan KPI Terlambat: as_of dari backend (zona waktu aplikasi), tanggal browser bila belum ada.
    isOverdue(task) {
        return isOverdue(task, this.monitoring?.as_of ?? today());
    },

    canOpen(task) {
        return task.can_view_detail !== false;
    },

    canEdit(task) {
        return canEditTask(task, this.user);
    },

    // Tugas terlihat oleh semua akun, tetapi tanpa kewenangan apa pun tampil sebagai hanya-lihat.
    isReadOnly(task) {
        return task.status !== 'done' && task.can_edit === false && task.can_assign === false && task.can_delete === false;
    },

    picNames(task) {
        return (task.pics ?? []).map(({ fullname }) => fullname).join(', ');
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
        if (!loaded || !this.matchesFilters(loaded) || !this.matchesPic(loaded)) {
            this.filters.search = '';
            this.filters.priority = '';
            this.filters.status = '';
            this.filters.pic = '';
        }
        // Item Calendar membawa tanggalnya sendiri; tautan ?task=ID hanya membawa ID, sehingga periode dilepas.
        if (this.appliedPeriod && !this.matchesPeriod(loaded ?? task)) {
            this.clearPeriod();
            this.load();
        }
        if (!loaded) this.filters.division = '';
        // Tugas di luar daftar, atau di Tugas Saya tanpa akun ini sebagai PIC aktif, hanya tampil di Semua Tugas.
        if (!loaded || (this.taskScope === 'my_tasks' && !isTaskPic(loaded, this.user))) this.taskScope = 'all_tasks';

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
        const before = index === -1 ? null : this.tasks[index];
        if (index === -1) this.tasks.push(task);
        else this.tasks.splice(index, 1, { ...this.tasks[index], ...task });
        if (affectsSummary(before, task)) this.queueSummary();
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
                ? await reviewTask(task.id, { decision: 'approve', version: task.version })
                : await changeTaskStatus(task.id, { status: transition.to, version: task.version });
            this.syncTask(data);
            showToast({ title: 'Status diperbarui', text: message ?? `${task.title} → ${statusOf(transition.to).label}` });
        } catch (error) {
            if (error?.status === 422) {
                const errors = validationErrors(error);
                notifyFailure({ ...error, data: { ...error.data, message: errors.status ?? errors.decision ?? picError(errors) } }, 'Status tugas gagal diperbarui.');
            } else {
                notifyFailure(error, 'Status tugas gagal diperbarui.');
            }
            // Data lama, atau tugas sudah diputuskan reviewer lain: muat ulang agar kartu berada di kolom yang benar.
            if (error?.status === 409 || (error?.status === 422 && transition.review)) this.load();
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
    pic_ids: [],
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
            this.assignees = data.pics ?? [];
            this.taskPriorities = data.priorities.map(({ value, label }) => ({ key: value, label }));
            this.optionsState = 'ready';

            // Supervisor hanya memiliki satu divisi tujuan.
            if (data.divisions.length === 1) this.form.division_id = data.divisions[0].id;
        } catch (error) {
            if (request !== this.optionsRequest) return;

            this.optionsState = 'error';
            this.optionsError = taskErrorMessage(error, 'Gagal memuat pilihan pembuatan tugas.');
        }
    },

    // Admin dan Manajer: semua divisi. Supervisor: divisi yang dikelolanya.
    get creatableDivisions() {
        return this.divisions;
    },

    get assigneeState() {
        if (this.optionsState !== 'ready') return this.optionsState;

        return this.assignees.length ? 'ready' : 'empty';
    },

    togglePic(id) {
        const selected = this.form.pic_ids;
        this.form.pic_ids = selected.includes(id) ? selected.filter((item) => item !== id) : [...selected, id];
        this.errors.pic_ids = null;
    },

    picDisabled(id) {
        return this.form.pic_ids.length >= MAX_PICS && !this.form.pic_ids.includes(id);
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
        const { title, division_id, status, pic_ids, start_date, deadline } = this.form;

        if (!title.trim()) errors.title = 'Judul tugas wajib diisi.';
        else if (title.trim().length > 150) errors.title = 'Judul tugas maksimal 150 karakter.';
        if (!division_id) errors.division_id = 'Target divisi wajib dipilih.';
        if (status === 'todo' && !pic_ids.length) errors.pic_ids = 'Tugas berstatus To Do wajib memiliki PIC.';
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
                const errors = validationErrors(error);
                this.errors = { ...errors, pic_ids: picError(errors) };
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
    activityError: '',
    activityRequest: 0,

    editing: false,
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
    assignForm: { pic_ids: [], division_id: '' },
    assignmentPics: [],
    currentAssignment: { pic_ids: [], division_id: '' },
    assigning: false,

    confirmingDelete: false,
    deleting: false,

    evidenceForm: { type: 'file', file: null, url: '', note: '' },
    evidenceErrors: {},
    sendingEvidence: false,
    confirmingEvidenceId: null,
    deletingEvidenceId: null,

    reviewMode: null,
    reviewNote: '',
    reviewError: '',
    acting: null,

    // Diskusi (TASK-16): satu thread per tugas; balasan menunjuk komentar induknya.
    comments: [],
    commentState: 'idle',
    commentError: '',
    commentRequest: 0,
    commentForm: { content: '', parentId: null },
    commentFormError: '',
    // ID pengiriman yang sedang diupayakan: { key, id }. Kirim ulang isi yang sama memakai ID yang sama.
    commentSend: null,
    sendingComment: false,

    init() {
        this.$watch('detailId', (id) => (id ? this.open(id) : this.reset()));
    },

    reset() {
        Object.assign(this, { task: null, state: 'idle', editing: false, confirmingDelete: false, assigneeState: 'idle', reviewMode: null, reviewNote: '', reviewError: '', evidences: [], evidenceErrors: {}, confirmingEvidenceId: null, activities: [], activityState: 'idle', activityError: '', comments: [], commentState: 'idle', commentError: '', commentForm: { content: '', parentId: null }, commentFormError: '', commentSend: null, sendingComment: false });
        this.activityRequest += 1;
        this.commentRequest += 1;
    },

    async open(id) {
        this.reset();
        this.state = 'loading';

        try {
            const { data } = await fetchTask(id);
            this.task = data;
            this.state = 'ready';
            this.syncTask(data);
            this.syncDetailCollections(data, { history: false });
            // Tugas yang belum ada di daftar baru masuk Board setelah detailnya dimuat.
            if (this.highlightId === data.id) this.$nextTick(() => this.revealHighlight());
            // Form revisi dari Board hanya dibuka bila backend mengizinkan user memberi keputusan QA saat ini.
            if (this.detailFocus === 'revision') {
                if (data.can_review === true) this.startReview('revision');
                else showToast({ type: 'info', title: 'Revisi tidak dapat diminta', text: 'Anda tidak dapat memberi keputusan QA untuk tugas ini saat ini.', duration: 5000 });
            }
            if (this.detailFocus === 'edit' && this.canEditThis) this.startEdit();
            await Promise.all([this.loadActivities(), this.loadComments()]);
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

    syncDetailCollections(data, { history = true } = {}) {
        if (Array.isArray(data.evidence)) {
            this.evidences = data.evidence;
            this.evidenceState = 'ready';
        }
        if (history && Array.isArray(data.history)) {
            // Histori dari respons perubahan lebih baru daripada permintaan histori yang mungkin masih berjalan.
            this.activityRequest += 1;
            this.activities = data.history;
            this.activityState = 'ready';
            this.activityError = '';
        }
    },

    // Histori aktivitas (TASK-08) diambil dari API histori agar punya state loading dan error sendiri,
    // tanpa menutup detail bila gagal. Respons lama (tugas lain dibuka, atau histori sudah diperbarui) diabaikan.
    async loadActivities() {
        const id = this.task?.id;
        if (!id) return;

        const request = ++this.activityRequest;
        this.activityState = 'loading';
        this.activityError = '';

        try {
            const { data } = await fetchActivities(id);
            if (request !== this.activityRequest) return;
            this.activities = data;
            this.activityState = 'ready';
        } catch (error) {
            if (request !== this.activityRequest) return;
            this.activityState = 'error';
            this.activityError = taskErrorMessage(error, 'Histori aktivitas gagal dimuat.');
        }
    },

    // --- Diskusi (TASK-16) ----------------------------------------------------------------------

    // Thread dimuat dari API komentar dengan state loading dan error sendiri; respons tugas lain diabaikan.
    // quiet: muat ulang setelah kirim tanpa skeleton; hasilnya digabung dengan yang sudah tampil, dan kegagalannya
    // tidak menimpa thread yang ada.
    async loadComments({ quiet = false } = {}) {
        const id = this.task?.id;
        if (!id) return;

        const request = ++this.commentRequest;
        if (!quiet) {
            this.commentState = 'loading';
            this.commentError = '';
        }

        try {
            const { data } = await fetchComments(id);
            if (request !== this.commentRequest || this.task?.id !== id) return;
            this.comments = quiet ? mergeComments(this.comments, data) : data;
            this.commentState = 'ready';
            this.commentError = '';
        } catch (error) {
            if (request !== this.commentRequest || this.task?.id !== id || quiet) return;
            this.commentState = 'error';
            this.commentError = taskErrorMessage(error, 'Komentar gagal dimuat.');
        }
    },

    /**
     * Komentar utama berurutan waktu, masing-masing dengan seluruh balasannya (termasuk balasan atas balasan)
     * berurutan waktu di bawahnya. Balasan yang induknya tidak ada di daftar tampil sebagai komentar utama.
     */
    get commentThreads() {
        const byId = new Map(this.comments.map((comment) => [comment.id, comment]));
        const rootOf = (comment) => {
            const seen = new Set();
            let current = comment;
            while (current.parent_comment_id && byId.has(current.parent_comment_id) && !seen.has(current.id)) {
                seen.add(current.id);
                current = byId.get(current.parent_comment_id);
            }

            return current;
        };
        const threads = new Map();

        for (const comment of this.comments) {
            const root = rootOf(comment);
            if (!threads.has(root.id)) threads.set(root.id, { root, replies: [] });
            if (root.id !== comment.id) threads.get(root.id).replies.push(comment);
        }

        return [...threads.values()];
    },

    // Mengikuti can_comment dari backend: Done, akses baca saja, atau PIC yang sudah dilepas tidak dapat mengirim.
    get canComment() {
        return this.task?.can_comment === true;
    },

    get commentReadOnlyNote() {
        if (this.canComment || !this.task) return '';
        if (this.task.status === 'done') return 'Tugas sudah selesai. Diskusi tetap dapat dibaca, tetapi komentar baru tidak dapat ditambahkan.';

        return 'Anda dapat membaca diskusi ini. Komentar hanya dapat dikirim oleh Admin, Manajer, Supervisor divisi tugas, dan PIC aktif.';
    },

    get replyTarget() {
        return this.comments.find((comment) => comment.id === this.commentForm.parentId) ?? null;
    },

    startReply(comment) {
        this.commentForm.parentId = comment.id;
        this.commentFormError = '';
        this.$nextTick(() => this.$refs.commentInput?.focus());
    },

    cancelReply() {
        this.commentForm.parentId = null;
        this.commentFormError = '';
    },

    /**
     * Komentar baru tampil hanya setelah backend menyimpannya. Isi dan balasan yang sama dikirim ulang dengan
     * client_request_id yang sama, sehingga kegagalan jaringan tidak menghasilkan komentar ganda; isi atau komentar
     * induk yang diubah adalah pengiriman baru. Komentar tidak mengubah status, review, atau PIC.
     */
    async sendComment() {
        if (this.sendingComment || !this.canComment) return;

        const content = this.commentForm.content.trim();
        const parentId = this.commentForm.parentId;
        if (!content) {
            this.commentFormError = 'Komentar tidak boleh kosong.';
            return;
        }
        if (content.length > COMMENT_MAX) {
            this.commentFormError = `Komentar maksimal ${COMMENT_MAX} karakter.`;
            return;
        }

        const id = this.task.id;
        const key = JSON.stringify([id, parentId, content]);
        if (this.commentSend?.key !== key) this.commentSend = { key, id: newRequestId() };

        this.sendingComment = true;
        this.commentFormError = '';

        try {
            const { data } = await postComment(id, {
                content,
                client_request_id: this.commentSend.id,
                ...(parentId ? { parent_comment_id: parentId } : {}),
            });
            if (this.task?.id !== id) return;

            if (!this.comments.some((comment) => comment.id === data.id)) this.comments = [...this.comments, data];
            this.commentForm = { content: '', parentId: null };
            this.commentSend = null;
            // Thread disamakan dengan backend: komentar yang tersimpan saat respons sebelumnya tidak sampai, atau
            // komentar akun lain, ikut tampil.
            this.loadComments({ quiet: true });
            // Histori mencatat komentar ini; can_comment ikut diperbarui.
            this.refreshDetail('Komentar tersimpan, tetapi histori aktivitas gagal diperbarui.');
        } catch (error) {
            if (this.task?.id !== id) return;

            const errors = error?.status === 422 ? validationErrors(error) : {};
            if (errors.parent_comment_id) {
                this.commentForm.parentId = null;
                this.loadComments();
            }
            // ID yang ditolak tidak dapat dipakai lagi; pengiriman berikutnya memakai ID baru.
            if (errors.client_request_id) this.commentSend = null;
            this.commentFormError = errors.content ?? errors.parent_comment_id ?? errors.client_request_id
                ?? (error?.status === 403
                    ? 'Anda tidak lagi dapat mengirim komentar pada tugas ini.'
                    : taskErrorMessage(error, 'Komentar gagal dikirim. Periksa koneksi Anda, lalu kirim lagi.'));
            // Status atau PIC mungkin sudah berubah sejak detail dibuka: ambil can_comment terbaru.
            if (error?.status === 403 || errors.content) this.refreshDetail('Histori aktivitas gagal diperbarui.');
        } finally {
            this.sendingComment = false;
        }
    },

    // Ambil ulang detail tanpa mengosongkan panel, agar daftar evidence dan histori sama dengan yang tersimpan.
    // Bila gagal, data yang sudah tampil dipertahankan; panel tetap bisa dimuat ulang saat dibuka kembali.
    // Bila gagal, evidence tetap tampil dari respons POST, tetapi histori belum memuat aktivitasnya: bagian histori
    // menampilkan pesan dan tombol Coba lagi (memuat ulang dari API histori).
    async refreshDetail(failure = 'Evidence tersimpan, tetapi histori aktivitas gagal diperbarui.') {
        const id = this.task?.id;
        // Permintaan histori yang masih berjalan dimulai sebelum aktivitas ini tercatat.
        const request = ++this.activityRequest;

        try {
            const { data } = await fetchTask(id);
            if (this.task?.id === id) this.applyTask(data);
        } catch {
            if (this.task?.id !== id || request !== this.activityRequest) return;
            this.activityState = 'error';
            this.activityError = failure;
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
        return this.task ? isTaskPic(this.task, this.user) : false;
    },

    get canEditThis() {
        return Array.isArray(this.task?.editable_fields)
            && this.task.editable_fields.some((field) => field !== 'status');
    },

    // Tanpa satu pun aksi yang tersedia, panel menjelaskan bahwa tugas hanya dapat dilihat.
    get isReadOnlyThis() {
        return Boolean(this.task)
            && this.task.status !== 'done'
            && !(this.task.editable_fields?.length)
            && this.task.can_assign !== true
            && this.task.can_delete !== true
            && this.task.can_review !== true
            && !this.canSubmitEvidence;
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
        return this.task?.can_assign === true;
    },

    toggleAssignPic(id) {
        const selected = this.assignForm.pic_ids;
        this.assignForm.pic_ids = selected.includes(id) ? selected.filter((item) => item !== id) : [...selected, id];
    },

    assignPicDisabled(id) {
        return this.assignForm.pic_ids.length >= MAX_PICS && !this.assignForm.pic_ids.includes(id);
    },

    // Daftar PIC adalah susunan lengkap setelah disimpan; hanya Back Log yang boleh tanpa PIC.
    get assignmentChanged() {
        const sorted = (ids) => [...ids].sort((a, b) => a - b).join(',');

        return sorted(this.assignForm.pic_ids) !== sorted(this.currentAssignment.pic_ids)
            || String(this.assignForm.division_id) !== String(this.currentAssignment.division_id);
    },

    get canSaveAssignment() {
        return this.assignmentChanged
            && Boolean(this.assignForm.division_id)
            && (this.assignForm.pic_ids.length > 0 || this.task?.status === 'backlog')
            && !this.assigning;
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
        this.assignForm = { pic_ids: [], division_id: '' };
        this.assignmentError = '';
        this.assigneeState = 'loading';

        try {
            const { data } = await fetchAssignmentOptions(this.task.id);
            this.assignmentDivisions = data.divisions ?? [];
            this.canChangeAssignmentDivision = data.can_change_division === true;
            this.assignmentPics = data.pics ?? [];
            this.currentAssignment = { pic_ids: (data.current_pics ?? []).map(({ id }) => id), division_id: String(data.current_division.id) };
            this.assignForm = { pic_ids: [...this.currentAssignment.pic_ids], division_id: this.currentAssignment.division_id };
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
        if (!this.canSaveAssignment) return;

        this.assigning = true;

        try {
            const { data, message } = await assignTask(this.task.id, {
                pic_ids: this.assignForm.pic_ids,
                division_id: Number(this.assignForm.division_id),
                version: this.task.version,
            });
            this.applyTask(data);
            this.assigneeState = 'idle';
            showToast({ title: 'PIC diperbarui', text: message ?? 'Penanggung jawab tugas diperbarui.' });
        } catch (error) {
            if (error?.status === 422) {
                const errors = validationErrors(error);
                this.assignmentError = picError(errors) ?? errors.division_id ?? taskErrorMessage(error, 'PIC gagal diperbarui.');
                this.assigneeState = 'error';
            } else {
                await this.handleFailure(error, 'PIC gagal diperbarui.');
            }
        } finally {
            this.assigning = false;
        }
    },

    // --- Hapus evidence sendiri (TASK-13) -----------------------------------------------------

    // Tombol hanya tampil bila backend mengirim can_delete pada evidence itu (PIC aktif pengunggahnya, To Do/On Process).
    // Setelah dihapus, detail diambil ulang agar histori dan flag evidence lain sama dengan yang tersimpan.
    async removeEvidence(evidence) {
        if (this.deletingEvidenceId !== null || evidence?.can_delete !== true) return;

        // Panel bisa ditutup atau berpindah tugas selama request berjalan: hasilnya hanya diterapkan pada tugas ini.
        const taskId = this.task.id;
        this.deletingEvidenceId = evidence.id;
        this.evidenceErrors = {};

        try {
            const { message } = await deleteEvidence(taskId, evidence.id);
            showToast({ title: 'Evidence dihapus', text: message ?? 'Evidence berhasil dihapus.' });

            if (this.task?.id !== taskId) {
                const listed = this.tasks.find(({ id }) => id === taskId);
                if (listed) this.syncTask({ ...listed, evidence_count: Math.max(0, (listed.evidence_count ?? 1) - 1) });

                return;
            }

            this.evidences = this.evidences.filter(({ id }) => id !== evidence.id);
            this.applyTask({ evidence_count: Math.max(0, (this.task.evidence_count ?? 1) - 1) });
            await this.refreshDetail('Evidence dihapus, tetapi histori aktivitas gagal diperbarui.');
        } catch (error) {
            // Penolakan untuk tugas yang sudah tidak ditampilkan tidak boleh muncul di atas tugas lain.
            if (this.task?.id !== taskId) return;

            if (error?.status === 422) {
                this.evidenceErrors = { evidence: validationErrors(error).evidence ?? errorMessage(error, 'Evidence gagal dihapus.') };
            } else if (error?.status === 404) {
                showToast({ type: 'info', title: 'Gagal', text: 'Evidence tidak ditemukan atau sudah dihapus.', duration: 5000 });
            } else {
                notifyFailure(error, 'Evidence gagal dihapus.');
            }
            // Status tugas atau PIC mungkin sudah berubah: muat ulang daftar evidence beserta flag can_delete terbaru.
            await this.refreshDetail('Daftar evidence gagal dimuat ulang. Tutup dan buka kembali detail tugas.');
        } finally {
            this.deletingEvidenceId = null;
            this.confirmingEvidenceId = null;
        }
    },

    // --- Hapus tugas (TASK-12) -----------------------------------------------------------------

    async removeTask() {
        if (this.deleting || this.task?.can_delete !== true) return;

        const { id, title, version } = this.task;
        this.deleting = true;

        try {
            const { message } = await deleteTask(id, { version });
            this.tasks = this.tasks.filter((item) => item.id !== id);
            this.closeTask();
            this.queueSummary();
            showToast({ title: 'Tugas dihapus', text: message ?? `${title} dihapus.` });
        } catch (error) {
            this.confirmingDelete = false;
            if (error?.status === 422) {
                notifyFailure({ ...error, data: { ...error.data, message: validationErrors(error).status } }, 'Tugas gagal dihapus.');
                await this.reload();
                this.load();
            } else {
                await this.handleFailure(error, 'Tugas gagal dihapus.');
            }
        } finally {
            this.deleting = false;
        }
    },

    // --- Workflow status (TASK-05) ------------------------------------------------------------

    // Status tujuan mengikuti next_statuses dari backend. Approve/Revisi tidak pernah lewat endpoint status:
    // tombolnya membuka form review (TASK-07) dan hanya tampil bila backend mengirim can_review.
    get transitions() {
        if (!this.task) return [];

        const backendStatuses = new Set((this.task.next_statuses ?? []).map(({ value }) => value));

        return availableTransitions(this.task, this.user)
            .filter((transition) => (transition.review ? this.task.can_review === true : backendStatuses.has(transition.to)));
    },

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
            const { data, message } = await changeTaskStatus(this.task.id, { status: transition.to, version: this.task.version });
            this.applyTask(data);
            this.load();
            showToast({ title: 'Status diperbarui', text: message ?? `Status menjadi ${statusOf(transition.to).label}.` });
        } catch (error) {
            if (error?.status === 422) {
                const errors = validationErrors(error);
                notifyFailure({ ...error, data: { ...error.data, message: errors.status ?? picError(errors) } }, 'Status tugas gagal diperbarui.');
            } else {
                await this.handleFailure(error, 'Status tugas gagal diperbarui.');
            }
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
                note: this.reviewNote.trim() || null,
                version: this.task.version,
            });
            this.applyTask(data);
            this.load();
            this.reviewMode = null;
            showToast({
                title: decision === 'revision' ? 'Revisi diminta' : 'Tugas disetujui',
                text: message ?? `Status menjadi ${statusOf(data.status).label}.`,
            });
        } catch (error) {
            if (error?.status === 422) {
                const errors = validationErrors(error);
                if (errors.note) {
                    this.reviewError = errors.note;
                } else {
                    // Tugas sudah tidak di On QA (misalnya diputuskan reviewer lain): tutup form dan muat data terbaru.
                    notifyFailure({ ...error, data: { ...error.data, message: errors.decision ?? errors.version } }, 'Keputusan review gagal disimpan.');
                    this.reviewMode = null;
                    await this.reload();
                    this.load();
                }
            } else {
                if (error?.status === 409) this.reviewMode = null;
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
            this.evidences = [data, ...this.evidences.filter(({ id }) => id !== data.id)];
            this.applyTask({ evidence_count: (this.task.evidence_count ?? 0) + 1 });
            this.evidenceForm = { type, file: null, url: '', note: '' };
            if (this.$refs.evidenceFile) this.$refs.evidenceFile.value = '';
            showToast({ title: 'Evidence terkirim', text: message ?? 'Bukti pekerjaan berhasil disimpan.' });
            await this.refreshDetail();
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
    periodSequence: 0,

    // Filter divisi Timeline terpisah dari cakupan Tasklist: semua role dapat melihat jadwal lintas divisi.
    // Default Admin dan Staff: Semua Divisi; Manajer: divisinya, dan dapat memilih Semua Divisi atau divisi lain.
    division: null,
    timelineDivisions: [],

    init() {
        this.$watch('view', (view) => view === 'timeline' && this.loadPeriod());
        this.$watch('division', () => this.view === 'timeline' && this.loadPeriod());
        if (this.view === 'timeline') this.loadPeriod();
    },

    get timelineDivision() {
        return this.division ?? (this.user.role === 'manager' ? (this.user.division ?? '') : '');
    },

    // Label mengikuti divisi yang benar-benar dikirim ke backend; bila namanya belum dimuat, tampilkan kodenya.
    get timelineScopeLabel() {
        if (!this.timelineDivision) return 'Semua Divisi';

        const division = this.timelineDivisionOptions.find((item) => item.code_division === this.timelineDivision);

        return `Divisi ${division?.name ?? this.timelineDivision}`;
    },

    // Master divisi; bila gagal dimuat, pakai divisi dari meta Tasklist agar filter tetap tersedia.
    get timelineDivisionOptions() {
        return this.timelineDivisions.length ? this.timelineDivisions : this.divisions;
    },

    async loadTimelineDivisions() {
        if (this.timelineDivisions.length) return;

        try {
            const { data } = await fetchDivisions();
            this.timelineDivisions = data.map(({ code_division, name }) => ({ code_division, name }));
        } catch {
            this.timelineDivisions = [];
        }
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

    // Respons periode atau divisi sebelumnya yang datang terlambat diabaikan.
    async loadPeriod() {
        const sequence = ++this.periodSequence;
        this.state = 'loading';
        this.loadTimelineDivisions();

        try {
            const { data } = await fetchSchedule({ from: this.start, to: this.end, division: this.timelineDivision });
            if (sequence !== this.periodSequence) return;
            this.items = data;
            this.state = 'ready';
        } catch (error) {
            if (sequence !== this.periodSequence) return;
            this.items = [];
            this.state = error?.unavailable ? 'unavailable' : 'error';
            this.message = taskErrorMessage(error, 'Jadwal gagal dimuat.');
        }
    },

    // Item tanpa tanggal mulai digambar satu hari di deadline (dan sebaliknya); tanpa keduanya tidak tampil.
    get rows() {
        const total = this.length;

        return this.items
            // Filter tugas (cari, prioritas, status) ikut berlaku; divisi sudah disaring backend; agenda selalu tampil.
            .filter((item) => item.type === 'event' || this.matchesFilters(item, { division: false }))
            .map((item) => {
                const from = item.start_date ?? item.end_date;
                const to = item.end_date ?? item.start_date;
                if (!from || !to || to < this.start || from > this.end) return null;

                const startIndex = Math.max(0, Math.round((parseISODate(from) - parseISODate(this.start)) / 864e5));
                const endIndex = Math.min(total - 1, Math.round((parseISODate(to) - parseISODate(this.start)) / 864e5));

                return {
                    ...item,
                    partial: !item.start_date || !item.end_date,
                    overdue: item.type === 'task' && Boolean(item.end_date) && item.status !== 'done' && item.end_date.slice(0, 10) < (this.monitoring?.as_of ?? today()),
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

    // Tugas, atau agenda yang terkait tugas, membuka detail tugas bila backend mengizinkan (can_view_detail);
    // selain itu hanya informasi jadwal yang ditampilkan.
    select(item) {
        if (item.can_view_detail === true && item.task_id) {
            this.openTask({ id: item.task_id, can_view_detail: true });
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
    // Ringkasan agenda yang dipilih: { kind: 'event', item }. Tugas memakai pop-up ringkasan di bawah.
    picked: null,
    periodSequence: 0,
    // Pop-up ringkasan tugas (TASK-15): { id, state: 'loading' | 'ready' | 'error' | 'missing', task, message }.
    taskPreview: null,
    previewSequence: 0,
    previewTrigger: null,

    init() {
        this.$watch('selected', () => (this.picked = null));
        this.$watch('view', (view) => view === 'calendar' && this.loadPeriod());
        this.$watch('filters.division', () => this.view === 'calendar' && this.loadPeriod());
        if (this.view === 'calendar') this.loadPeriod();
    },

    // Divisi awal akun tidak dikirim agar agenda bersama lintas divisi tetap tampil; deadline tetap disaring
    // filter divisi di halaman. Divisi yang dipilih sendiri memfokuskan agenda dan deadline.
    get calendarDivision() {
        return this.filters.division === this.defaultDivision() ? '' : this.filters.division;
    },

    get calendarScopeLabel() {
        return this.filters.division && !this.calendarDivision
            ? `Agenda lintas divisi · Deadline ${this.divisionScopeLabel}`
            : this.divisionScopeLabel;
    },

    get monthLabel() {
        return monthLabel.format(parseISODate(`${this.month}-01`));
    },

    // 6 minggu (Senin–Minggu) yang memuat bulan aktif. Backend hanya mengirim data bulan aktif, jadi tanggal
    // bulan lain tidak diberi penanda (datanya tidak lengkap) dan memilihnya membuka bulan tersebut.
    get cells() {
        const first = `${this.month}-01`;
        const start = mondayOf(first);

        return Array.from({ length: 42 }, (_, index) => {
            const iso = addDays(start, index);
            const inMonth = iso.startsWith(this.month);

            return {
                iso,
                day: parseISODate(iso).getDate(),
                inMonth,
                today: iso === today(),
                events: inMonth ? this.eventsOn(iso).length : 0,
                // Satu tugas tetap satu item kalender walaupun memiliki beberapa PIC.
                tasks: inMonth ? this.deadlinesOn(iso) : [],
                deadlines: inMonth ? this.deadlinesOn(iso).length : 0,
                overdue: inMonth && this.deadlinesOn(iso).some((task) => this.isOverdue(task)),
            };
        });
    },

    choose(iso) {
        if (iso.startsWith(this.month)) {
            this.selected = iso;
            return;
        }

        this.month = iso.slice(0, 7);
        this.selected = iso;
        this.loadPeriod();
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

    // Agenda terkait tugas hanya membuka detail bila backend mengizinkan; filter tidak memperluas akses.
    canOpenEventTask(event) {
        return Boolean(event.task_id) && event.can_view_detail === true;
    },

    pick(kind, item) {
        this.picked = this.picked?.kind === kind && this.picked.item.id === item.id ? null : { kind, item };
    },

    isPicked(kind, item) {
        return this.picked?.kind === kind && this.picked.item.id === item.id;
    },

    // Ringkasan agenda hanya tampil selama agendanya masih ada di tanggal terpilih (setelah muat ulang).
    get pickedVisible() {
        return this.picked?.kind === 'event' && this.selectedEvents.some((item) => item.id === this.picked.item.id);
    },

    /**
     * Pop-up ringkasan tugas dari item di sel kalender atau daftar deadline tanggal terpilih. Isinya selalu dibaca
     * ulang dari GET /api/tasks/{id}/preview; data Calendar hanya dipakai untuk judul selama dimuat. Bulan dan
     * tanggal terpilih tidak berubah, dan pop-up tidak mengubah tugas.
     */
    async openPreview(task) {
        const sequence = ++this.previewSequence;
        // Fokus kembali ke item yang membuka pop-up, bukan ke tombol Coba Lagi di dalamnya.
        if (!this.taskPreview) this.previewTrigger = document.activeElement ?? null;
        this.taskPreview = { id: task.id, state: 'loading', task, message: '' };

        try {
            const { data } = await fetchTaskPreview(task.id);
            if (sequence !== this.previewSequence) return;

            this.taskPreview = { id: data.id, state: 'ready', task: data, message: '' };
            // Item kalender dan kartu yang sudah dimuat ikut memakai data terbaru.
            this.deadlines = this.deadlines.map((item) => (item.id === data.id ? { ...item, ...data } : item));
            if (this.tasks.some((item) => item.id === data.id)) this.syncTask(data);
        } catch (error) {
            if (sequence !== this.previewSequence) return;

            const missing = error?.status === 404;
            this.taskPreview = {
                id: task.id,
                state: missing ? 'missing' : 'error',
                task,
                message: missing ? 'Tugas ini sudah dihapus atau tidak ditemukan.' : taskErrorMessage(error, 'Ringkasan tugas gagal dimuat.'),
            };
            // Tugas yang sudah dihapus juga dilepas dari Board, dan KPI dimuat ulang, seperti setelah Hapus Tugas.
            if (missing) {
                this.deadlines = this.deadlines.filter((item) => item.id !== task.id);
                if (this.tasks.some((item) => item.id === task.id)) {
                    this.tasks = this.tasks.filter((item) => item.id !== task.id);
                    this.queueSummary();
                }
            }
        }
    },

    retryPreview() {
        return this.taskPreview ? this.openPreview(this.taskPreview.task) : undefined;
    },

    closePreview() {
        ++this.previewSequence;
        this.taskPreview = null;
        this.previewTrigger?.focus?.();
        this.previewTrigger = null;
    },

    // Lihat Detail membuka Board berdasarkan ID tugas dari ringkasan terbaru; hak ubah tetap dari backend.
    openPreviewDetail() {
        const task = this.taskPreview?.task;
        if (this.taskPreview?.state !== 'ready') return;

        this.closePreview();
        this.focusTask(task);
    },

    previewDate(value) {
        return value ? formatDate(value) : 'Belum diatur';
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

    // Respons bulan atau divisi sebelumnya yang datang terlambat diabaikan.
    async loadPeriod() {
        const sequence = ++this.periodSequence;
        this.state = 'loading';

        try {
            const { data } = await fetchCalendar({ month: this.month, division: this.calendarDivision });
            if (sequence !== this.periodSequence) return;
            this.events = data.events ?? [];
            this.deadlines = data.deadlines ?? [];
            this.state = 'ready';
            // Ringkasan yang terbuka memakai data terbaru dari respons ini.
            if (this.picked) {
                const list = this.picked.kind === 'task' ? this.deadlines : this.events;
                const item = list.find(({ id }) => id === this.picked.item.id);
                this.picked = item ? { kind: this.picked.kind, item } : null;
            }
        } catch (error) {
            if (sequence !== this.periodSequence) return;
            this.events = [];
            this.deadlines = [];
            this.state = error?.unavailable ? 'unavailable' : 'error';
            this.message = taskErrorMessage(error, 'Kalender gagal dimuat.');
        }
    },
}));
