import { formatDate, priorityOf, statusOf } from './workflow';

/**
 * Mengubah histori dari detail tugas ({ action, changes: { field: { old, new } }, actor, created_at })
 * menjadi baris yang siap ditampilkan di panel detail.
 */
const ACTION_LABELS = {
    created: 'Tugas dibuat',
    updated: 'Informasi tugas diperbarui',
    assigned: 'PIC ditetapkan',
    reassigned: 'PIC diganti',
};

const FIELD_LABELS = {
    title: 'Judul',
    description: 'Deskripsi',
    category: 'Kategori',
    division: 'Divisi',
    platform: 'Platform',
    pic: 'PIC',
    priority: 'Prioritas',
    status: 'Status',
    start_date: 'Tanggal mulai',
    deadline: 'Deadline',
    requires_review: 'Kewajiban QA',
};

// Deskripsi bisa panjang: cukup dicatat bahwa isinya berubah.
const VALUE_HIDDEN = ['description'];

function display(field, value) {
    if (value === null || value === undefined || value === '') return '-';
    if (typeof value === 'object') return value.name ?? '-';
    if (field === 'status') return statusOf(value).label;
    if (field === 'priority') return priorityOf(value).label;
    if (field === 'start_date' || field === 'deadline') return formatDate(value);
    if (field === 'requires_review') return value ? 'Wajib' : 'Tidak wajib';

    return String(value);
}

export function historyEntries(history = []) {
    return history.map((entry) => {
        const changes = Object.entries(entry.changes ?? {}).map(([field, change]) => ({
            field,
            label: FIELD_LABELS[field] ?? field,
            hidden: VALUE_HIDDEN.includes(field),
            before: display(field, change?.old),
            after: display(field, change?.new),
        }));
        const onlyStatus = entry.action === 'updated' && changes.length === 1 && changes[0].field === 'status';

        return {
            id: entry.id,
            label: onlyStatus ? 'Status diubah' : (ACTION_LABELS[entry.action] ?? entry.action),
            changes,
            causer: entry.actor,
            created_at: entry.created_at,
        };
    });
}
