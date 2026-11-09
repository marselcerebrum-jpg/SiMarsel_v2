# API Tasklist

Kontrak data untuk halaman **Tasklist** (`/tasks`): Board, Table, ringkasan, Timeline, Calendar, detail tugas, pembuatan tugas, penugasan PIC, workflow status, evidence, review, dan histori (TASK-01 s.d. TASK-11).

**Status: frontend selesai, backend belum dibuat.** Semua panggilan API ada di `resources/js/services/tasks.js`. Selama `VITE_TASK_API_READY` bukan `true`, frontend tidak memanggil server dan menampilkan keterangan "Menunggu Back End". Setelah endpoint di bawah tersedia, isi `VITE_TASK_API_READY=true` di `.env` lalu jalankan `npm run build`.

Wajib login. Header dan kode error umum mengikuti [authentication.md](authentication.md). Tanggal memakai format `YYYY-MM-DD`; waktu memakai ISO 8601.

## Role & cakupan data

Role Tasklist berasal dari `GET /api/tasks/meta`. Sebelum endpoint itu ada, frontend memetakan `MGR` → `manager`, `EMP` → `staff`, dan `ADM` → `admin`. Role Admin belum ada di tabel `roles`.

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

**TaskDetail** = Task ditambah `description`, `category` (`{ id, name }`), `platform` (`{ slug, name }` | null), `creator` (`{ id, fullname }`), `created_at`, `completed_at`, `last_review` (Review terbaru | null), `reviews` (semua Review, terbaru lebih dulu), dan `can_review` (`true` bila user boleh memberi keputusan QA saat ini: tugas `on_qa` dan user Admin/Manajer berwenang).

**Activity** (histori, `GET /api/tasks/{id}/activities`):

```json
{
  "id": 42,
  "action": "reassigned",
  "action_label": "PIC diganti",
  "description": "PIC: Budi → Sari",
  "changes": { "pic": { "old": { "id": 7, "name": "Budi" }, "new": { "id": 9, "name": "Sari" } } },
  "actor": { "id": 3, "fullname": "Manajer Marketing" },
  "created_at": "2026-11-06T02:00:00Z"
}
```

- `action`: `created` | `updated` | `assigned` | `reassigned` | `status_changed` | `evidence_submitted` | `approved` | `revision_requested` | `resubmitted`.
- `changes`: nilai sebelum dan sesudah untuk tiap field yang berubah (`{}` untuk `created`).
- `actor.id` bernilai `null` bila akun pelaku sudah dihapus; `actor.fullname` tetap berisi nama pelaku saat aktivitas terjadi.

## Endpoint

| Method & path | Body / query | Respons |
|---|---|---|
| `GET /api/tasks/meta` | – | `{ data: { role, division, can_create, divisions: [{ code_division, name }], categories: [{ id, name, requires_review }], platforms: [{ slug, name }] } }` |
| `GET /api/tasks` | `division` (opsional) | `{ data: [Task] }`, semua tugas dalam cakupan user. Pencarian, filter prioritas/status, sort, dan pagination dilakukan di frontend. |
| `POST /api/tasks` | `title`*, `description`, `category_id`, `division`*, `pic_id`, `platform`, `priority`*, `start_date`, `deadline`, `status`* (`backlog`\|`todo`), `requires_review`, `evidence_required`, `client_request_id`* | `201 { data: Task, message }` |
| `GET /api/tasks/{id}` | – | `{ data: TaskDetail }` |
| `PATCH /api/tasks/{id}` | `title`, `description`, `category_id`, `platform`, `priority`, `start_date`, `deadline`, `updated_at`* | `{ data: TaskDetail, message }` |
| `PATCH /api/tasks/{id}/status` | `status`*, `version`* | `{ data: TaskDetail, message }`; `422` dengan alasan di `errors.status` bila transisi ditolak; `409 { message, current_version }` bila `version` sudah usang |
| `PATCH /api/tasks/{id}/assignee` | `pic_id`*, `note`, `updated_at`* | `{ data: TaskDetail, message }` |
| `GET /api/tasks/assignees` | `division` | `{ data: [{ id, fullname, division }] }`, hanya user yang memenuhi aturan penugasan |
| `GET /api/tasks/{id}/evidences` | – | `{ data: [Evidence] }` |
| `GET /api/tasks/{id}/evidences/{evidence}/download` | – | File evidence (hanya untuk yang dapat melihat tugas; `404` bila bukan evidence tugas itu atau berupa tautan) |
| `POST /api/tasks/{id}/evidences` | multipart: `file` **atau** `url`, `note` | `201 { data: Evidence, message }`; hanya PIC (`403` untuk yang lain); `422` dengan pesan di `errors.file`/`errors.url`, atau di `errors.evidence` bila status tugas tidak menerima evidence |
| `GET /api/tasks/{id}/reviews` | – | `{ data: [Review] }`, terbaru lebih dulu; dapat dilihat siapa pun yang dapat melihat tugas (termasuk PIC) |
| `POST /api/tasks/{id}/reviews` | `decision`* (`approve`\|`revision`), `note` (wajib untuk `revision`, maks. 2000), `version`* | `{ data: TaskDetail, message }`; `403` bila bukan Admin/Manajer berwenang atau tugas tidak wajib QA; `422` di `errors.note` bila revisi tanpa alasan, di `errors.decision` bila tugas tidak berstatus `on_qa`; `409 { message, current_version }` bila `version` usang |
| `GET /api/tasks/{id}/activities` | – | `{ data: [Activity] }` |
| `GET /api/tasks/schedule` | `from`*, `to`*, `division` | `{ data: [ScheduleItem] }` |
| `GET /api/tasks/calendar` | `month`* (`YYYY-MM`), `division` | `{ data: { events: [Event], deadlines: [Task] } }` |

`*` = wajib.

- **Evidence**: `{ id, task_id, type, file_name, file_url, url, note, submitter: { id, fullname }, created_at }`. `type` = `file` (isi `file_name`/`file_url`) atau `link` (isi `url`). Detail tugas mengirimnya di `evidence`, terbaru lebih dulu; `[]` bila tugas belum punya evidence.
- **Review**: `{ id, task_id, cycle, decision, decision_label, note, reviewer: { id, fullname }, created_at }`. `decision` = `approve` (label Approve) atau `revision` (label Request Revision). `cycle` = urutan pemeriksaan tugas itu (1, 2, ...). Review tidak dapat diubah atau dihapus.
- **Activity**: `{ id, type, label, description, before, after, causer: { id, fullname }, created_at }`. `type` mencakup `created`, `updated`, `assigned`, `status_changed`, `evidence_submitted`, `approved`, `revision_requested`, `resubmitted`. Isi `before`/`after` untuk perubahan penting. Histori hanya ditambahkan oleh sistem, tidak bisa diedit.
- **ScheduleItem**: `{ id, type: "task"|"event", title, division, target_divisions, start_date, end_date, status, priority, task_id, can_view_detail }`. `status` dan `priority` dipakai agar filter Tasklist ikut berlaku di Timeline (agenda bersama tidak disaring), dan bernilai `null` untuk `event`. Agenda bersama (`event`) dan tugas lintas divisi boleh tampil untuk semua role, tetapi `can_view_detail` hanya `true` bila user berhak membuka detailnya. Lihat [Timeline](#timeline-task-10).
- **Event** (Calendar): `{ id, title, division, start_date, end_date, description, task_id, can_view_detail }`. `task_id` diisi bila agenda terkait sebuah tugas (tombol "Lihat Detail Tugas Terkait"); `null` untuk agenda murni yang tidak dibuka di Board. `deadlines` hanya berisi tugas dalam cakupan user dan yang memiliki deadline; sertakan `description` (boleh `null`) agar ringkasan Calendar dapat menampilkan deskripsi singkat.
- **Lihat Detail dari Calendar**: frontend berpindah ke Board dan membuka detail lewat `GET /api/tasks/{id}`. Bila tugas tersembunyi oleh filter, filter cari/prioritas/status dikosongkan; Admin juga dipindah ke Semua Divisi. Akses tetap diputuskan backend (`403`/`404`).
- **Tautan langsung**: `/tasks?task={id}` membuka Board dengan detail tugas tersebut memakai alur yang sama. Parameter `task` diperbarui saat panel detail dibuka dan dihapus saat ditutup.

## Timeline (TASK-10)

**Status: backend tersedia.** `GET /api/tasks/schedule?from=YYYY-MM-DD&to=YYYY-MM-DD&division=KODE` mengembalikan `{ data: [ScheduleItem] }`, hanya-baca (tidak ada endpoint untuk mengubah jadwal).

- **Validasi** (`422`): `from` dan `to` wajib berformat `YYYY-MM-DD`, `to` tidak boleh sebelum `from`, periode maksimal 62 hari, dan `division` harus kode divisi yang ada.
- **Tugas**: tugas semua divisi yang rentang tanggalnya bersinggungan dengan periode, untuk semua role. `start_date` = tanggal mulai, `end_date` = deadline. Tugas yang hanya punya salah satu tanggal dihitung satu hari pada tanggal itu (tanggal lainnya `null`); tugas tanpa tanggal tidak dikirim.
- **Detail internal tidak dikirim**: PIC, deskripsi, evidence, dan histori tidak ada di ScheduleItem. `can_view_detail` mengikuti aturan `GET /api/tasks/{id}` (Admin semua, Manajer divisinya, Staff tugas yang PIC-nya dia), sehingga tugas divisi lain tampil di jadwal tetapi detailnya tetap `403`.
- **Agenda (`event`)** dari tabel `schedule_events`. `division` = divisi pemilik/penyelenggara agenda (`null` bila tanpa pemilik); hanya informasi, tidak menentukan siapa yang melihat. `target_divisions` = divisi tujuan agenda internal (tabel `schedule_event_divisions`, bisa lebih dari satu; `[]` untuk agenda bersama). `is_shared` adalah penentu utama visibilitas: `true` = semua pengguna; `false` dengan divisi tujuan = Admin dan akun divisi tujuan saja; `false` tanpa divisi tujuan = hanya Admin. Divisi pemilik yang bukan divisi tujuan tidak melihat agenda internalnya. `task_id` diisi bila agenda terkait tugas, dan `can_view_detail` agenda mengikuti hak membuka tugas itu (`false` untuk agenda tanpa tugas). Agenda tanpa `end_date` berlangsung satu hari.
- **Filter `division`**: tugas divisi itu, agenda yang dimiliki divisi itu atau menjadikannya divisi tujuan, ditambah agenda bersama tanpa pemilik. Filter tidak pernah memperluas visibilitas agenda. Tanpa `division` = Semua Divisi (default Admin).
- **Urutan**: berdasarkan hari mulai (tugas tanpa tanggal mulai memakai deadline), tugas sebelum agenda pada hari yang sama.
- Agenda belum punya endpoint kelola; data contoh lokal berasal dari `ScheduleEventSeeder`.

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

- Keluar dari `on_qa` hanya lewat `POST /api/tasks/{id}/reviews`. `PATCH /status` dan `PATCH /api/tasks/{id}` ke `done`/`in_progress` dari `on_qa` ditolak `422`, dan `next_statuses` untuk `on_qa` selalu `[]` (pakai `can_review`).

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

**Review & revisi (TASK-07)**
- Hanya tugas `requires_review = true` yang direview. Mulai pemeriksaan = `PATCH /status` dari `ready_for_qa` ke `on_qa` oleh Admin/Manajer berwenang.
- Keputusan hanya untuk tugas `on_qa`. Admin untuk semua divisi, Manajer untuk divisinya; Staff (termasuk PIC) selalu `403`.
- `approve` → `done` dan menyimpan `completed_at`. `revision` → `in_progress`, `note` wajib (spasi saja dianggap kosong).
- Setelah revisi, PIC memperbaiki lalu mengirim ulang ke `ready_for_qa`; histori mencatatnya sebagai `resubmitted`. Siklus dapat berulang sampai disetujui; semua review sebelumnya tetap tersimpan.
- Keputusan dicatat di histori sebagai `approved` atau `revision_requested` dengan `changes.status` dan `changes.review` (`{ id, cycle, note }`).

**Histori aktivitas (TASK-08)**
- Histori hanya dibuat setelah aktivitas berhasil; aksi yang ditolak tidak meninggalkan catatan.
- Urutan: terbaru lebih dulu; aktivitas pada detik yang sama diurutkan berdasarkan `id`. Tanpa aktivitas → `{ data: [] }`.
- Akses mengikuti detail tugas: Admin semua tugas, Manajer tugas divisinya, Staff hanya tugas yang ditugaskan kepadanya (selain itu `403`).
- Histori tidak dapat diedit atau dihapus, dan tetap tersedia setelah status atau PIC berubah.
- Nama pelaku disimpan saat aktivitas dicatat, sehingga tetap tampil walaupun akunnya dihapus.

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
