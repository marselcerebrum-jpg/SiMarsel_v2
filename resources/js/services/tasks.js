import { http } from '../http';

/**
 * API daftar, meta, detail, pembuatan, pembaruan, penugasan, status, evidence, dan review tugas sudah tersedia dan dipakai langsung.
 * Endpoint Tasklist lain yang belum terintegrasi tetap menunggu VITE_TASK_API_READY=true.
 * Kontrak lengkap ada di docs/api/tasks.md.
 *
 * GET    /api/tasks/meta                       → { data: { role, division, default_division, can_create, divisions, statuses, priorities } }
 * GET    /api/tasks?search&division&priority&status → { data: [Task], meta: { status_counts } }
 * POST   /api/tasks                            → { data: Task, message }
 * GET    /api/tasks/{id}                       → { data: TaskDetail }
 * PATCH  /api/tasks/{id}                       → { data: TaskDetail, message }         (kirim version)
 * PATCH  /api/tasks/{id}/status                → { data: TaskDetail, message }         (kirim status, version)
 * GET    /api/tasks/{id}/assignment-options    → { data: { current_division, can_change_division, locked_reason, divisions } }
 * PATCH  /api/tasks/{id}/assignment            → { data: TaskDetail, message }         (kirim pic_id, version, division_id opsional)
 * GET    /api/tasks/{id}/evidences             → { data: [Evidence] }
 * POST   /api/tasks/{id}/evidences             → { data: Evidence, message }           (multipart: file atau url, note)
 * POST   /api/tasks/{id}/reviews               → { data: TaskDetail, message }         (kirim decision, note, version)
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

export const fetchTaskMeta = () => http('/api/tasks/meta');

export const fetchTasks = (filters) => http(`/api/tasks${query(filters)}`);

export const fetchTaskCreateOptions = () => http('/api/tasks/create-options');

export const createTask = (body) => http('/api/tasks', { method: 'POST', body });

export const fetchTask = (id) => http(`/api/tasks/${id}`);

export const updateTask = (id, body) => http(`/api/tasks/${id}`, { method: 'PATCH', body });

export const changeTaskStatus = (id, body) => http(`/api/tasks/${id}/status`, { method: 'PATCH', body });

export const fetchAssignmentOptions = (id) => http(`/api/tasks/${id}/assignment-options`);

export const assignTask = (id, body) => http(`/api/tasks/${id}/assignment`, { method: 'PATCH', body });

// Evidence tidak bergantung pada VITE_TASK_API_READY: API-nya sudah tersedia.
export const fetchEvidences = (id) => http(`/api/tasks/${id}/evidences`);

// Keputusan QA (approve/revisi) tidak bergantung pada VITE_TASK_API_READY: API-nya sudah tersedia.
export const reviewTask = (id, body) => http(`/api/tasks/${id}/reviews`, { method: 'POST', body });

export const fetchSchedule = (params) => request(`/api/tasks/schedule${query(params)}`);

export const fetchCalendar = (params) => request(`/api/tasks/calendar${query(params)}`);

/**
 * Evidence dikirim sebagai multipart agar bisa membawa file; helper http() hanya untuk JSON.
 */
export async function submitEvidence(id, { file, url, note }) {
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
