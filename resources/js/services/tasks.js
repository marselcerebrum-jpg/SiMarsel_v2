import { http } from '../http';

/**
 * Semua panggilan API Tasklist. Backend belum tersedia: selama VITE_TASK_API_READY bukan "true",
 * setiap pemanggilan langsung ditolak dengan { unavailable: true } tanpa memanggil server, dan
 * halaman menampilkan keterangan "Menunggu Back End". Kontrak lengkap ada di docs/api/tasks.md.
 *
 * GET    /api/tasks/meta                       → { data: { role, scope, can_create, divisions, categories, platforms } }
 * GET    /api/tasks?division&priority&status   → { data: [Task] }
 * POST   /api/tasks                            → { data: Task, message }
 * GET    /api/tasks/{id}                       → { data: TaskDetail }
 * PATCH  /api/tasks/{id}                       → { data: TaskDetail, message }         (kirim updated_at)
 * PATCH  /api/tasks/{id}/status                → { data: TaskDetail, message }         (kirim status, updated_at)
 * PATCH  /api/tasks/{id}/assignee              → { data: TaskDetail, message }         (kirim pic_id, note, updated_at)
 * GET    /api/tasks/assignees?division         → { data: [{ id, fullname, division }] }
 * GET    /api/tasks/{id}/evidences             → { data: [Evidence] }
 * POST   /api/tasks/{id}/evidences             → { data: Evidence, message }           (multipart: file atau url, note)
 * POST   /api/tasks/{id}/reviews               → { data: TaskDetail, message }         (kirim decision, note, updated_at)
 * GET    /api/tasks/{id}/activities            → { data: [Activity] }
 * GET    /api/tasks/schedule?from&to&division  → { data: [ScheduleItem] }
 * GET    /api/tasks/calendar?month&division    → { data: { events: [Event], deadlines: [Task] } }
 */
export const TASK_API_READY = import.meta.env.VITE_TASK_API_READY === 'true';

const unavailable = () => Promise.reject({ unavailable: true });

function query(params = {}) {
    const entries = Object.entries(params).filter(([, value]) => value !== '' && value !== null && value !== undefined);

    return entries.length ? `?${new URLSearchParams(entries)}` : '';
}

const request = (url, options) => (TASK_API_READY ? http(url, options) : unavailable());

export const fetchTaskMeta = () => request('/api/tasks/meta');

export const fetchTasks = (filters) => request(`/api/tasks${query(filters)}`);

export const createTask = (body) => request('/api/tasks', { method: 'POST', body });

export const fetchTask = (id) => request(`/api/tasks/${id}`);

export const updateTask = (id, body) => request(`/api/tasks/${id}`, { method: 'PATCH', body });

export const changeTaskStatus = (id, body) => request(`/api/tasks/${id}/status`, { method: 'PATCH', body });

export const assignTask = (id, body) => request(`/api/tasks/${id}/assignee`, { method: 'PATCH', body });

export const fetchAssignees = (division) => request(`/api/tasks/assignees${query({ division })}`);

export const fetchEvidences = (id) => request(`/api/tasks/${id}/evidences`);

export const reviewTask = (id, body) => request(`/api/tasks/${id}/reviews`, { method: 'POST', body });

export const fetchActivities = (id) => request(`/api/tasks/${id}/activities`);

export const fetchSchedule = (params) => request(`/api/tasks/schedule${query(params)}`);

export const fetchCalendar = (params) => request(`/api/tasks/calendar${query(params)}`);

/**
 * Evidence dikirim sebagai multipart agar bisa membawa file; helper http() hanya untuk JSON.
 */
export async function submitEvidence(id, { file, url, note }) {
    if (!TASK_API_READY) return unavailable();

    const form = new FormData();
    if (file) form.append('file', file);
    if (url) form.append('url', url);
    if (note) form.append('note', note);

    const response = await fetch(`/api/tasks/${id}/evidences`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
        },
        body: form,
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw { status: response.status, data };

    return data;
}
