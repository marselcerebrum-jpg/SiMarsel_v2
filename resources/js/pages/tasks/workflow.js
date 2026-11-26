/**
 * Aturan Tasklist yang dipakai bersama oleh semua tampilan: status, prioritas, hak akses per role,
 * transition matrix (TASK-05), dan rumus ringkasan (TASK-09). Backend tetap memvalidasi ulang;
 * aturan di sini dipakai untuk menampilkan tombol yang relevan dan menolak drag-and-drop lebih awal.
 */

export const STATUSES = [
    { key: 'backlog', label: 'Back Log', dot: 'bg-slate-400', badge: 'bg-slate-100 text-slate-700' },
    { key: 'todo', label: 'To Do', dot: 'bg-sky-500', badge: 'bg-sky-50 text-sky-700' },
    { key: 'in_progress', label: 'On Process', dot: 'bg-amber-500', badge: 'bg-amber-50 text-amber-800' },
    { key: 'ready_review', label: 'Ready Review', dot: 'bg-violet-500', badge: 'bg-violet-50 text-violet-700' },
    { key: 'on_review', label: 'On Review', dot: 'bg-fuchsia-500', badge: 'bg-fuchsia-50 text-fuchsia-700' },
    { key: 'done', label: 'Done', dot: 'bg-emerald-500', badge: 'bg-emerald-50 text-emerald-700' },
];

export const PRIORITIES = [
    { key: 'low', label: 'Low', rank: 1, badge: 'bg-slate-100 text-slate-700' },
    { key: 'medium', label: 'Medium', rank: 2, badge: 'bg-sky-50 text-sky-700' },
    { key: 'high', label: 'High', rank: 3, badge: 'bg-orange-50 text-orange-700' },
    { key: 'urgent', label: 'Urgent', rank: 4, badge: 'bg-red-50 text-red-700' },
];

// Nama status QA lama sebelum migrasi TASK-17 tetap dibaca dengan label Review terbaru.
const LEGACY_STATUSES = { ready_for_qa: 'ready_review', on_qa: 'on_review' };

export const statusOf = (key) => STATUSES.find((status) => status.key === (LEGACY_STATUSES[key] ?? key)) ?? { key, label: key ?? '-', dot: 'bg-slate-300', badge: 'bg-slate-100 text-slate-700' };

export const priorityOf = (key) => PRIORITIES.find((priority) => priority.key === key) ?? { key, label: key ?? '-', rank: 0, badge: 'bg-slate-100 text-slate-700' };

/**
 * Role dari meta API bila tersedia; sebelum itu diturunkan dari role akun yang login.
 * meta.role tetap tiga nilai; roleCode membedakan Supervisor dan Freelance dari Staff.
 */
const ROLE_BY_CODE = { ADM: 'admin', MGR: 'manager', SPV: 'staff', EMP: 'staff', FRL: 'staff' };

// Ringkasan kewenangan untuk ditampilkan; keputusan per tugas tetap dari flag backend.
const ROLE_ACCESS = {
    ADM: { label: 'Admin', text: 'Melihat dan mengelola tugas seluruh divisi.' },
    MGR: { label: 'Manajer', text: 'Melihat dan mengelola tugas seluruh divisi, termasuk penugasan PIC dan review.' },
    SPV: { label: 'Supervisor', text: 'Melihat tugas seluruh divisi; mengelola dan me-review tugas divisi yang dikelola.' },
    EMP: { label: 'Staff', text: 'Melihat tugas seluruh divisi; mengerjakan tugas dengan Anda sebagai PIC aktif.' },
    FRL: { label: 'Freelance', text: 'Melihat tugas seluruh divisi; mengerjakan tugas dengan Anda sebagai PIC aktif.' },
};

export const roleAccessOf = (roleCode) => ROLE_ACCESS[roleCode] ?? { label: 'Pengguna', text: 'Melihat tugas seluruh divisi.' };

export function userContext(sessionUser, meta = null) {
    return {
        id: sessionUser?.id ?? null,
        role: meta?.role ?? ROLE_BY_CODE[sessionUser?.role?.code_role] ?? 'staff',
        roleCode: meta?.role_code ?? sessionUser?.role?.code_role ?? null,
        // Divisi yang dikelola: dari meta backend bila ada, selain itu divisi akun.
        division: meta?.division ?? sessionUser?.division?.code_division ?? null,
    };
}

// PIC aktif sebuah tugas: satu tugas dapat memiliki beberapa PIC.
export const isTaskPic = (task, user) => (task.pics ?? []).some(({ id }) => id === user.id);

// Admin dan Manajer berwenang atas semua divisi; Supervisor atas divisi yang dikelolanya.
export const managesTask = (task, user) =>
    user.role === 'admin'
    || user.role === 'manager'
    || (user.roleCode === 'SPV' && Boolean(user.division) && task.division?.code_division === user.division);

export const canEditTask = (task, user) => task.can_edit !== false && managesTask(task, user) && task.status !== 'done';

const LOCKED_ASSIGNMENT = ['ready_review', 'on_review', 'done'];

export function assignmentRule(task, user) {
    if (!managesTask(task, user)) return { ok: false, reason: 'Hanya Admin, Manajer, atau Supervisor divisi ini yang dapat mengatur PIC.' };
    if (LOCKED_ASSIGNMENT.includes(task.status)) return { ok: false, reason: `PIC tidak dapat diubah saat status ${statusOf(task.status).label}.` };

    return { ok: true, reason: '' };
}

/**
 * Transition matrix TASK-05. `actor`: siapa yang boleh memindahkan; `when`: syarat tambahan.
 */
export const TRANSITIONS = [
    { from: 'backlog', to: 'todo', actor: 'manager', label: 'Siap Dikerjakan' },
    { from: 'todo', to: 'in_progress', actor: 'pic', label: 'Mulai Kerjakan' },
    { from: 'in_progress', to: 'ready_review', actor: 'pic', when: 'requires_review', label: 'Kirim ke QA' },
    { from: 'in_progress', to: 'done', actor: 'pic', when: 'no_review', label: 'Selesaikan Tugas' },
    { from: 'ready_review', to: 'on_review', actor: 'manager', label: 'Mulai Review' },
    { from: 'on_review', to: 'done', actor: 'manager', label: 'Setujui', review: 'approve' },
    { from: 'on_review', to: 'in_progress', actor: 'manager', label: 'Minta Revisi', review: 'revision' },
];

/**
 * Apakah user boleh memindahkan tugas ke status `to`. Mengembalikan alasan penolakan
 * yang bisa langsung ditampilkan kepada user.
 */
export function transitionRule(task, to, user) {
    if (task.status === to) return { ok: false, reason: '' };

    const rule = TRANSITIONS.find((item) => item.from === task.status && item.to === to);
    if (!rule) return { ok: false, reason: `Perpindahan dari ${statusOf(task.status).label} ke ${statusOf(to).label} tidak diizinkan.` };

    if (rule.actor === 'pic' && !isTaskPic(task, user)) return { ok: false, reason: 'Hanya PIC tugas ini yang dapat mengubah status tersebut.' };
    if (rule.actor === 'manager' && !managesTask(task, user)) return { ok: false, reason: 'Hanya Admin, Manajer, atau Supervisor divisi ini yang dapat mengubah status tersebut.' };
    // Permission ditentukan backend: ubah status lewat can_change_status, keputusan QA (Approve/Revisi) lewat can_review.
    if (!rule.review && task.can_change_status === false) return { ok: false, reason: 'Anda tidak memiliki izin untuk mengubah status tugas ini.' };
    if (rule.review && task.can_review !== true) return { ok: false, reason: 'Anda tidak memiliki izin untuk memberi keputusan QA pada tugas ini.' };

    if (rule.when === 'requires_review' && !task.requires_review) return { ok: false, reason: 'Tugas ini tidak wajib QA. Gunakan status Done.' };
    if (rule.when === 'no_review' && task.requires_review) return { ok: false, reason: 'Tugas ini wajib QA. Kirim ke Ready Review terlebih dahulu.' };

    if (rule.from === 'backlog' && !task.pics?.length) return { ok: false, reason: 'Tetapkan PIC terlebih dahulu sebelum tugas masuk To Do.' };
    if (rule.actor === 'pic' && rule.to !== 'in_progress' && task.evidence_required && !task.evidence_count) {
        return { ok: false, reason: 'Tambahkan evidence terlebih dahulu sebelum mengirim hasil pekerjaan.' };
    }

    return { ok: true, reason: '', rule };
}

export const availableTransitions = (task, user) =>
    TRANSITIONS.filter((rule) => rule.from === task.status && transitionRule(task, rule.to, user).ok);

// --- Tanggal (YYYY-MM-DD, zona waktu lokal) -------------------------------------------------

const pad = (value) => String(value).padStart(2, '0');

export const toISODate = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

export const parseISODate = (value) => {
    if (!value) return null;
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return new Date(year, month - 1, day);
};

export const today = () => toISODate(new Date());

export const addDays = (iso, days) => {
    const date = parseISODate(iso);
    date.setDate(date.getDate() + days);

    return toISODate(date);
};

const dateFormat = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
const dateTimeFormat = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

export const formatDate = (iso) => (iso ? dateFormat.format(parseISODate(iso)) : '-');

export const formatDateTime = (value) => (value ? dateTimeFormat.format(new Date(value)) : '-');

export const isActive = (task) => task.status !== 'done';

export const isOverdue = (task, on = today()) => isActive(task) && Boolean(task.deadline) && task.deadline.slice(0, 10) < on;

