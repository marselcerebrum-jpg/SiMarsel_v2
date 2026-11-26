import { http } from '../http';

/**
 * API daftar, meta, monitoring, detail, pembuatan, pembaruan, penugasan, status, evidence, review, histori, jadwal (Timeline), dan kalender (Calendar) tugas sudah tersedia dan dipakai langsung.
 * Endpoint Tasklist lain yang belum terintegrasi tetap menunggu VITE_TASK_API_READY=true.
 * Kontrak lengkap ada di docs/api/tasks.md.
 *
 * GET    /api/tasks/meta                       → { data: { role, role_code, permissions, division, default_division, can_create, divisions, pics, statuses, priorities } }
 * GET    /api/tasks?scope&search&division&priority&status&pic_id&<periode> → { data: [Task], meta: { status_counts } }   (scope: all_tasks | my_tasks)
 * GET    /api/tasks/monitoring?scope&search&division&priority&status&pic_id&<periode> → { data: { scope, as_of, metrics, by_priority, by_division } }
 * POST   /api/tasks                            → { data: Task, message }
 * GET    /api/tasks/{id}                       → { data: TaskDetail }
 * GET    /api/tasks/{id}/preview               → { data: TaskPreview }                 (pop-up Calendar, selalu data terbaru)
 * PATCH  /api/tasks/{id}                       → { data: TaskDetail, message }         (kirim version)
 * DELETE /api/tasks/{id}                       → { message }                           (kirim version)
 * PATCH  /api/tasks/{id}/status                → { data: TaskDetail, message }         (kirim status, version)
 * GET    /api/tasks/{id}/assignment-options    → { data: { current_pics, current_division, can_change_division, locked_reason, divisions, pics } }
 * PATCH  /api/tasks/{id}/assignment            → { data: TaskDetail, message }         (kirim pic_ids, version, division_id opsional)
 * GET    /api/tasks/{id}/evidences             → { data: [Evidence] }
 * POST   /api/tasks/{id}/evidences             → { data: Evidence, message }           (multipart: file atau url, note)
 * DELETE /api/tasks/{id}/evidences/{evidence}  → { message }                           (hanya evidence dengan can_delete)
 * POST   /api/tasks/{id}/reviews               → { data: TaskDetail, message }         (kirim decision, note, version)
 * GET    /api/tasks/{id}/activities            → { data: [Activity] }                  (terbaru lebih dulu)
 * GET    /api/tasks/{id}/comments              → { data: [Comment] }                   (terlama lebih dulu)
 * POST   /api/tasks/{id}/comments              → { data: Comment, message }            (kirim content, client_request_id, parent_comment_id opsional)
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

// Ringkasan KPI Tasklist dihitung backend dalam cakupan Tasklist user, dengan filter yang sama seperti daftar tugas.
export const fetchTaskMonitoring = (filters) => http(`/api/tasks/monitoring${query({ scope: 'tasklist', ...filters })}`);

export const fetchTaskCreateOptions = () => http('/api/tasks/create-options');

export const createTask = (body) => http('/api/tasks', { method: 'POST', body });

export const fetchTask = (id) => http(`/api/tasks/${id}`);

// Ringkasan pop-up Calendar (TASK-15): dibaca ulang dari backend setiap kali dibuka.
export const fetchTaskPreview = (id) => http(`/api/tasks/${id}/preview`);

export const updateTask = (id, body) => http(`/api/tasks/${id}`, { method: 'PATCH', body });

export const deleteTask = (id, body) => http(`/api/tasks/${id}`, { method: 'DELETE', body });

export const changeTaskStatus = (id, body) => http(`/api/tasks/${id}/status`, { method: 'PATCH', body });

export const fetchAssignmentOptions = (id) => http(`/api/tasks/${id}/assignment-options`);

export const assignTask = (id, body) => http(`/api/tasks/${id}/assignment`, { method: 'PATCH', body });

// Evidence tidak bergantung pada VITE_TASK_API_READY: API-nya sudah tersedia.
export const fetchEvidences = (id) => http(`/api/tasks/${id}/evidences`);

// Hanya PIC aktif yang mengunggahnya yang boleh menghapus (flag can_delete per evidence); soft delete di backend.
export const deleteEvidence = (taskId, evidenceId) => http(`/api/tasks/${taskId}/evidences/${evidenceId}`, { method: 'DELETE' });

// Keputusan QA (approve/revisi) tidak bergantung pada VITE_TASK_API_READY: API-nya sudah tersedia.
export const reviewTask = (id, body) => http(`/api/tasks/${id}/reviews`, { method: 'POST', body });

// Histori aktivitas tidak bergantung pada VITE_TASK_API_READY: API-nya sudah tersedia.
export const fetchActivities = (id) => http(`/api/tasks/${id}/activities`);

// Komentar dan balasan (TASK-16) tidak bergantung pada VITE_TASK_API_READY: API-nya sudah tersedia.
export const fetchComments = (id) => http(`/api/tasks/${id}/comments`);

export const postComment = (id, body) => http(`/api/tasks/${id}/comments`, { method: 'POST', body });

// Jadwal Timeline tidak bergantung pada VITE_TASK_API_READY: API-nya sudah tersedia.
export const fetchSchedule = (params) => http(`/api/tasks/schedule${query(params)}`);

// Master divisi untuk filter Timeline: jadwal lintas divisi dapat difokuskan ke divisi mana pun oleh semua role.
export const fetchDivisions = () => http('/api/divisions');

// Kalender tidak bergantung pada VITE_TASK_API_READY: API-nya sudah tersedia.
export const fetchCalendar = (params) => http(`/api/tasks/calendar${query(params)}`);

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
