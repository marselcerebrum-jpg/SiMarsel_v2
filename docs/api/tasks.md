# API Tasklist

Kontrak data untuk halaman **Tasklist** (`/tasks`): Board, Table, ringkasan, Timeline, Calendar, detail tugas, pembuatan tugas, penugasan PIC, workflow status, evidence, review, dan histori (TASK-01 s.d. TASK-11).

**Status: Board/Table dan pembuatan tugas terintegrasi dengan backend.** `GET /api/tasks/meta`, `GET /api/tasks`, `GET /api/tasks/create-options`, `POST /api/tasks`, `GET /api/tasks/{id}`, dan `PATCH /api/tasks/{id}/status` dipanggil langsung. Filter daftar tugas dikirim ke API; endpoint Tasklist tambahan tetap dinonaktifkan sampai tersedia.

Wajib login. Header dan kode error umum mengikuti [authentication.md](authentication.md). Tanggal memakai format `YYYY-MM-DD`; waktu memakai ISO 8601.

## Role & cakupan data

Role dan kewenangan membuat tugas berasal dari `GET /api/tasks/meta`. Jika pemuatan meta gagal, frontend memakai pemetaan role akun `MGR` → `manager`, `EMP` → `staff`, dan `ADM` → `admin`.

| Role | Melihat | Membuat | Edit & PIC | Review |
|---|---|---|---|---|
| `admin` | Semua tugas, default Semua Divisi | Semua divisi | Semua tugas | Semua divisi |
| `manager` | Tugas divisi yang dikelolanya | Divisinya | Tugas divisinya | Divisinya |
| `staff` | Hanya tugas yang PIC-nya dia, termasuk yang sudah Done | Tidak | Hanya status tugasnya: To Do → On Process → Ready for QA (atau → Done bila tanpa QA). Back Log, Ready for QA, On QA, dan Done hanya-baca (`can_edit: false`) | Tidak |

Backend **wajib** menerapkan cakupan ini di setiap endpoint. Staff yang mengubah parameter atau ID tugas harus mendapat `403` atau `404`. Aturan di frontend hanya untuk tampilan.

## Objek

**Task** (daftar, Board, Table, Calendar):

```json
{
  "id": 105,
  "title": "Update landing page JadiASN",
  "division": { "code_division": "MKT", "name": "Marketing" },
  "pic": { "id": 1, "fullname": "Default Manager" },
  "priority": "high",
  "status": "in_progress",
  "start_date": "2026-10-16",
  "deadline": "2026-10-20",
  "requires_review": true,
  "evidence_required": true,
  "evidence_count": 0,
  "can_view_detail": true,
  "updated_at": "2026-10-21T03:00:00Z"
}
```

- `priority`: `low` | `medium` | `high` | `urgent`.
- `status`: `backlog` | `todo` | `in_progress` | `ready_for_qa` | `on_qa` | `done` (label: Back Log, To Do, On Process, Ready for QA, On QA, Done).
- `pic`, `start_date`, dan `deadline` boleh `null`.
- `updated_at` dipakai untuk mencegah perubahan berbasis data lama (lihat 409).

**TaskDetail** = Task ditambah `description`, `category` (`{ id, name }`), `platform` (`{ slug, name }` | null), `creator` (`{ id, fullname }`), `created_at`, `completed_at`, dan `last_review` (`{ decision: "approve"|"revision", note, reviewer: { id, fullname }, created_at }` | null).

## Endpoint

| Method & path | Body / query | Respons |
|---|---|---|
| `GET /api/tasks/meta` | – | `{ data: { role, division, default_division, can_create, divisions, statuses, priorities } }` |
| `GET /api/tasks` | `search`, `division`, `priority`, `status`, `sort`, `direction`, `per_page`, `page` (semua opsional) | `{ data: [Task], meta: { status_counts } }`, tugas dalam cakupan user. Tanpa `per_page` semua tugas dikembalikan sekaligus; Board/Table mengirim filter ke server dan mengurutkan serta mempaginasi di frontend. |
| `GET /api/tasks/create-options` | – | Pilihan divisi sesuai kewenangan, PIC per divisi, kategori/aturan QA, platform, prioritas, status awal, dan default form. Admin/Manajer saja. |
| `POST /api/tasks` | `title`*, `description`, `category_id`, `division_id`*, `pic_id`, `platform_id`, `priority`, `start_date`, `deadline`, `status`, `requires_review`, `evidence_required` | `201 { data: TaskDetail, message }`; Admin/Manajer saja. Field pembuat ditentukan dari akun yang login. |
| `GET /api/tasks/{id}` | – | `{ data: TaskDetail }` |
| `PATCH /api/tasks/{id}` | `title`, `description`, `category_id`, `platform_id`, `priority`, `start_date`, `deadline`, `version`* | `{ data: TaskDetail, message }` |
| `PATCH /api/tasks/{id}/status` | `status`*, `version`* | `{ data: TaskDetail, message }`; `422` dengan alasan di `errors.status` bila transisi ditolak; `409 { message, current_version }` bila `version` sudah usang |
| `PATCH /api/tasks/{id}/assignee` | `pic_id`*, `note`, `updated_at`* | `{ data: TaskDetail, message }` |
| `GET /api/tasks/assignees` | `division` | `{ data: [{ id, fullname, division }] }`, hanya user yang memenuhi aturan penugasan |
| `GET /api/tasks/{id}/evidences` | – | `{ data: [Evidence] }` |
| `GET /api/tasks/{id}/evidences/{evidence}/download` | – | File evidence (hanya untuk yang dapat melihat tugas; `404` bila bukan evidence tugas itu atau berupa tautan) |
| `POST /api/tasks/{id}/evidences` | multipart: `file` **atau** `url`, `note` | `201 { data: Evidence, message }`; hanya PIC (`403` untuk yang lain); `422` dengan pesan di `errors.file`/`errors.url`, atau di `errors.evidence` bila status tugas tidak menerima evidence |
| `POST /api/tasks/{id}/reviews` | `decision`* (`approve`\|`revision`), `note` (wajib untuk `revision`), `updated_at`* | `{ data: TaskDetail, message }` |
| `GET /api/tasks/{id}/activities` | – | `{ data: [Activity] }` |
| `GET /api/tasks/schedule` | `from`*, `to`*, `division` | `{ data: [ScheduleItem] }` |
| `GET /api/tasks/calendar` | `month`* (`YYYY-MM`), `division` | `{ data: { events: [Event], deadlines: [Task] } }` |

`*` = wajib.

- **Evidence**: `{ id, task_id, type, file_name, file_url, url, note, submitter: { id, fullname }, created_at }`. `type` = `file` (isi `file_name`/`file_url`) atau `link` (isi `url`). Detail tugas mengirimnya di `evidence`, terbaru lebih dulu; `[]` bila tugas belum punya evidence.
- **Activity**: `{ id, type, label, description, before, after, causer: { id, fullname }, created_at }`. `type` mencakup `created`, `updated`, `assigned`, `status_changed`, `evidence_submitted`, `approved`, `revision_requested`, `resubmitted`. Isi `before`/`after` untuk perubahan penting. Histori hanya ditambahkan oleh sistem, tidak bisa diedit.
- **ScheduleItem**: `{ id, type: "task"|"event", title, division, start_date, end_date, status, priority, can_view_detail }`. `status` dan `priority` dipakai agar filter Tasklist ikut berlaku di Timeline (agenda bersama tidak disaring). Agenda bersama (`event`) dan tugas lintas divisi boleh tampil untuk semua role, tetapi `can_view_detail` hanya `true` bila user berhak membuka detailnya.
- **Event** (Calendar): `{ id, title, division, start_date, end_date, description, task_id, can_view_detail }`. `task_id` diisi bila agenda terkait sebuah tugas (tombol "Lihat Detail Tugas Terkait"); `null` untuk agenda murni yang tidak dibuka di Board. `deadlines` hanya berisi tugas dalam cakupan user dan yang memiliki deadline; sertakan `description` (boleh `null`) agar ringkasan Calendar dapat menampilkan deskripsi singkat.
- **Lihat Detail dari Calendar**: frontend berpindah ke Board dan membuka detail lewat `GET /api/tasks/{id}`. Bila tugas tersembunyi oleh filter, filter cari/prioritas/status dikosongkan; Admin juga dipindah ke Semua Divisi. Akses tetap diputuskan backend (`403`/`404`).
- **Tautan langsung**: `/tasks?task={id}` membuka Board dengan detail tugas tersebut memakai alur yang sama. Parameter `task` diperbarui saat panel detail dibuka dan dihapus saat ditutup.

## Aturan bisnis yang harus divalidasi backend

**Pembuatan tugas (TASK-02)**
- `title` dan `division` wajib.
- Manajer hanya boleh membuat tugas untuk divisinya.
- Status awal hanya `backlog` atau `todo`. Status `todo` wajib memiliki `pic_id` yang valid.
- `requires_review` ditentukan dari kategori dan disimpan bersama tugas.
- Pembuat tugas diambil dari akun yang login.
- `client_request_id` sama berarti permintaan yang sama: jangan buat tugas ganda, kembalikan tugas yang sudah dibuat.
- Field Poin dari sistem lama tidak ada.

**Transition matrix (TASK-05)**. Perpindahan lain ditolak dengan `422`.

| Dari | Ke | Oleh |
|---|---|---|
| `backlog` | `todo` | Admin/Manajer berwenang (tugas harus punya PIC) |
| `todo` | `in_progress` | PIC |
| `in_progress` | `ready_for_qa` | PIC, jika `requires_review = true` |
| `in_progress` | `done` | PIC, jika `requires_review = false` |
| `ready_for_qa` | `on_qa` | Admin/Manajer berwenang |
| `on_qa` | `done` | Admin/Manajer berwenang (via `reviews`, `decision=approve`) |
| `on_qa` | `in_progress` | Admin/Manajer berwenang (via `reviews`, `decision=revision`, `note` wajib) |

- Jika `evidence_required = true`, PIC tidak boleh ke `ready_for_qa` atau `done` sebelum ada evidence.
- `done` menyimpan `completed_at`.

**PIC (TASK-04)**
- Hanya Admin, dan Manajer untuk divisinya, yang boleh mengganti PIC.
- Penggantian PIC tidak mengubah status tugas.
- PIC tidak boleh diganti saat status `ready_for_qa`, `on_qa`, atau `done`.
- PIC lama dan baru dicatat di histori.

**Evidence (TASK-06)**
- Hanya PIC yang boleh mengirim evidence, saat status `todo` atau `in_progress`. Admin dan Manajer divisi dapat melihat dan mengunduhnya.
- Satu evidence berisi `file` **atau** `url`, tidak keduanya. `note` opsional, maks. 2000 karakter.
- File yang diterima: PDF, JPG, JPEG, PNG, WEBP, DOC, DOCX, XLS, XLSX, PPT, PPTX, ZIP, maks. 10 MB. Backend memvalidasi ulang jenis dan ukuran file. File disimpan privat dan hanya dapat diunduh lewat endpoint download.
- Tautan wajib URL valid dengan `http://` atau `https://`, maks. 2048 karakter.
- Evidence terikat pada tugas di URL; `task_id` di body diabaikan.
- PIC diperiksa ulang saat evidence disimpan (row tugas dikunci). Bila PIC diganti ketika evidence sedang dikirim, evidence dari PIC lama ditolak `403` dan file yang sudah terunggah dihapus.
- `evidence_required` mengikuti kategori: kategori dengan `evidence_required = true` selalu mewajibkan evidence. Untuk kategori lain, pembuat tugas boleh mengirim `evidence_required` di `POST /api/tasks`. Memindahkan tugas ke kategori yang mewajibkan evidence membuat evidence wajib.
- Submission tetap lewat `PATCH /api/tasks/{id}/status` (`ready_for_qa` bila wajib QA, `done` bila tidak); ditolak `422` bila evidence wajib tapi belum ada.
- Setelah revisi (`on_qa` → `in_progress`), PIC dapat mengirim evidence baru dan submit ulang. Evidence lama tetap tersimpan dan tampil di detail (terbaru lebih dulu).
- Setiap evidence yang dikirim dicatat di histori dengan action `evidence_submitted`.

**Data lama (409)**: setiap perubahan membawa `updated_at` terakhir yang dilihat user. Jika berbeda dengan data di database, tolak dengan `409 Conflict`. Frontend lalu memuat ulang data dan meminta user memeriksa kembali.

## Kode respons

| Kode | Arti di frontend |
|---|---|
| `403` | "Anda tidak memiliki akses untuk tindakan ini." / detail: Akses ditolak |
| `404` | "Tugas tidak ditemukan atau sudah dihapus." / detail: Tugas tidak ditemukan |
| `409` | Data sudah diubah pengguna lain, data dimuat ulang |
| `422` | Pesan validasi tampil di bawah field. Pesan aturan bisnis diambil dari `message`, mis. alasan transisi ditolak |

## Ringkasan (TASK-09)

Ringkasan dihitung di frontend dari respons `GET /api/tasks` yang sama dengan Board/Table, sehingga angkanya selalu konsisten. Halaman menonjolkan empat metrik utama (Total Tasks, In Progress = status On Process, Overdue, Due Today); Completed, Unfinished, Urgent, dan Completion Rate tampil sebagai informasi tambahan, dan jumlah per status tampil di kolom Board:

- **Total Tasks**: semua tugas dalam cakupan dan filter.
- **Back Log, To Do, On Process, Ready for QA, On QA**: jumlah per status.
- **Completed**: status `done`. **Unfinished**: status selain `done`.
- **Overdue**: tugas aktif (bukan `done`) dengan deadline sebelum hari ini.
- **Due Today**: tugas aktif dengan deadline hari ini.
- **Urgent**: tugas aktif berprioritas `urgent`.
- **Completion Rate** = Completed / Total × 100%, dan 0% bila Total = 0.

Tugas tanpa deadline tidak dihitung pada Overdue maupun Due Today.
