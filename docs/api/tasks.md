# API Tasklist

Kontrak data untuk halaman **Tasklist** (`/tasks`): Board, Table, ringkasan, Timeline, Calendar, detail tugas, pembuatan tugas, penugasan PIC, workflow status, evidence, review, dan histori (TASK-01 s.d. TASK-11).

**Status: Board/Table, pembuatan, detail, edit, penugasan, workflow status, evidence, review, dan histori tugas terintegrasi dengan backend.** `GET /api/tasks/meta`, `GET /api/tasks`, `GET /api/tasks/create-options`, `POST /api/tasks`, `GET /api/tasks/{id}`, `PATCH /api/tasks/{id}`, `GET /api/tasks/{id}/assignment-options`, `PATCH /api/tasks/{id}/assignment`, `PATCH /api/tasks/{id}/status`, `GET /api/tasks/{id}/evidences`, `POST /api/tasks/{id}/evidences`, `POST /api/tasks/{id}/reviews`, dan `GET /api/tasks/{id}/activities` dipanggil langsung, tanpa bergantung pada `VITE_TASK_API_READY`. Daftar evidence di detail diambil dari `evidence` pada `GET /api/tasks/{id}`. Keputusan QA dari Board (drag On QA → Done untuk approve, On QA → On Process membuka form revisi) dan dari detail selalu memakai `POST /api/tasks/{id}/reviews` dengan `version`, tidak pernah `PATCH /status`; tombol dan form keputusan di detail mengikuti `can_review`. Filter daftar tugas dikirim ke API; endpoint Tasklist tambahan tetap dinonaktifkan sampai tersedia.

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
  "version": 1,
  "updated_at": "2026-10-21T03:00:00Z"
}
```

- `priority`: `low` | `medium` | `high` | `urgent`.
- `status`: `backlog` | `todo` | `in_progress` | `ready_for_qa` | `on_qa` | `done` (label: Back Log, To Do, On Process, Ready for QA, On QA, Done).
- `pic`, `start_date`, dan `deadline` boleh `null`.
- `version` dipakai untuk mencegah perubahan berbasis data lama (lihat 409).

**TaskDetail** = Task ditambah `description`, `category` (`{ id, name, requires_review, evidence_required }` | null), `platform` (`{ id, name }` | null), `creator` (`{ id, fullname }` | null), `evidence` (array Evidence), `history` (array Activity), `reviews` (semua Review, terbaru lebih dulu), `last_review` (Review terbaru | null), `can_review` (`true` bila user boleh memberi keputusan QA saat ini: tugas `on_qa` dan user Admin/Manajer berwenang), `editable_fields`, `next_statuses`, `created_at`, dan `completed_at`. `next_statuses` tidak memuat status tujuan dari `on_qa`, karena keputusan QA memakai endpoint review.

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
| `GET /api/tasks/meta` | – | `{ data: { role, division, default_division, can_create, divisions, statuses, priorities } }` |
| `GET /api/tasks` | `search`, `division`, `priority`, `status`, `sort`, `direction`, `per_page`, `page` (semua opsional) | `{ data: [Task], meta: { status_counts } }`, tugas dalam cakupan user. Tanpa `per_page` semua tugas dikembalikan sekaligus; Board/Table mengirim filter ke server dan mengurutkan serta mempaginasi di frontend. |
| `GET /api/tasks/create-options` | – | Pilihan divisi sesuai kewenangan, PIC per divisi, kategori/aturan QA, platform, prioritas, status awal, dan default form. Admin/Manajer saja. |
| `POST /api/tasks` | `title`*, `description`, `category_id`, `division_id`*, `pic_id`, `platform_id`, `priority`, `start_date`, `deadline`, `status`, `requires_review`, `evidence_required` | `201 { data: TaskDetail, message }`; Admin/Manajer saja. Field pembuat ditentukan dari akun yang login. |
| `GET /api/tasks/{id}` | – | `{ data: TaskDetail }` |
| `PATCH /api/tasks/{id}` | `title`, `description`, `category_id`, `platform_id`, `priority`, `start_date`, `deadline`, `version`* | `{ data: TaskDetail, message }` |
| `PATCH /api/tasks/{id}/status` | `status`*, `version`* | `{ data: TaskDetail, message }`; semua transisi kecuali keluar dari `on_qa` (approve/revisi memakai `POST /api/tasks/{id}/reviews`). `422` dengan alasan di `errors.status` bila transisi ditolak; `409 { message, current_version }` bila `version` sudah usang |
| `GET /api/tasks/{id}/assignment-options` | – | Pilihan divisi sesuai kewenangan, Staff yang dapat ditugaskan per divisi, PIC/divisi saat ini, serta alasan jika penugasan terkunci. Admin dapat memilih lintas divisi; Manajer hanya mendapat divisinya. |
| `PATCH /api/tasks/{id}/assignment` | `pic_id`*, `version`*, `division_id` (opsional; Admin saja) | `{ data: TaskDetail, message }`; menetapkan atau mengganti PIC dan, untuk Admin, dapat meneruskan tugas lintas divisi. Perubahan versi lama ditolak dengan `409`; PIC/divisi yang tidak valid ditolak dengan `422`. |
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
- **Activity**: lihat bentuk JSON di atas (`action`, `action_label`, `description`, `changes`, `actor`). Histori hanya ditambahkan oleh sistem, tidak bisa diedit.
- **Assignment**: perubahan PIC memakai `PATCH /api/tasks/{id}/assignment`, membawa `version` dan ID PIC yang dipilih. Sertakan `division_id` bila Admin meneruskan tugas ke divisi lain. Backend tidak mengubah status ketika PIC/divisi diperbarui dan menyimpan PIC lama/baru dalam `history`; Staff tidak dapat menetapkan PIC. Penugasan terkunci saat tugas berada di tahap QA atau sudah selesai.
- **ScheduleItem**: `{ id, type: "task"|"event", title, division, target_divisions, start_date, end_date, status, priority, task_id, can_view_detail }`. `status` dan `priority` dipakai agar filter Tasklist ikut berlaku di Timeline (agenda bersama tidak disaring), dan bernilai `null` untuk `event`. Agenda bersama (`event`) dan tugas lintas divisi boleh tampil untuk semua role, tetapi `can_view_detail` hanya `true` bila user berhak membuka detailnya. Lihat [Timeline](#timeline-task-10).
- **Event** (Calendar): `{ id, title, division, target_divisions, start_date, end_date, description, task_id, can_view_detail }`. `task_id` diisi bila agenda terkait sebuah tugas (tombol "Lihat Detail Tugas Terkait"); `null` untuk agenda murni yang tidak dibuka di Board. `deadlines` hanya berisi tugas dalam cakupan user dan yang memiliki deadline, berupa objek Task ditambah `description` (boleh `null`) dan `is_overdue`. Lihat [Calendar](#calendar-task-11).
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

## Calendar (TASK-11)

**Status: backend tersedia.** `GET /api/tasks/calendar?month=YYYY-MM&division=KODE` mengembalikan `{ data: { events: [Event], deadlines: [Task] } }`, hanya-baca. Kedua daftar dipisah: agenda bersama di `events`, deadline tugas di `deadlines`.

- **Validasi** (`422`): `month` wajib berformat `YYYY-MM`; `division` harus kode divisi yang ada.
- **`events`**: agenda (`schedule_events`) yang bersinggungan dengan bulan itu, dengan aturan visibilitas yang sama seperti [Timeline](#timeline-task-10): `is_shared = true` untuk semua role, agenda internal hanya untuk Admin dan akun divisi tujuannya (`target_divisions`), agenda internal tanpa divisi tujuan hanya untuk Admin. Hanya informasi umum (nama, divisi pemilik, divisi tujuan, tanggal, keterangan) yang dikirim. `can_view_detail` mengikuti hak membuka tugas terkait.
- **`deadlines`**: tugas dengan deadline di bulan itu, dalam cakupan user yang sama dengan `GET /api/tasks`: Admin semua divisi, Manajer divisinya, Staff tugas yang PIC-nya dia. Tugas tanpa deadline tidak ikut. Urut berdasarkan deadline.
- **`is_overdue`**: `true` bila deadline sudah lewat (sebelum hari ini) dan status bukan `done`.
- **Filter `division`**: deadline divisi itu (tetap di dalam cakupan user, jadi filter tidak pernah memperluas akses) dan agenda yang dimiliki divisi itu atau menjadikannya divisi tujuan, ditambah agenda bersama tanpa pemilik. Tanpa `division` = Semua Divisi (default Admin).

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
- Frontend: bagian Histori Aktivitas di detail mengambil `GET /api/tasks/{id}/activities` setelah detail tampil, dengan state loading, kosong, dan error (tombol "Coba lagi") sendiri, sehingga kegagalan histori tidak menutup detail. Setelah perubahan berhasil, histori diperbarui dari `history` pada respons TaskDetail; respons histori yang lebih lama diabaikan.

**Data lama (409)**: setiap perubahan membawa `version` terakhir yang dilihat user. Jika berbeda dengan data di database, tolak dengan `409 Conflict`. Frontend lalu memuat ulang data dan meminta user memeriksa kembali.

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

## Monitoring Tugas (agregasi backend)

`GET /api/tasks/monitoring` menghitung KPI ringkasan langsung di database, sehingga angka tidak bergantung pada data yang dimuat frontend. Hanya-baca: melihat tugas di laporan tidak memberi hak edit, dan akses detail/aksi tetap mengikuti cakupan Tasklist.

**Query** (semua opsional):

| Param | Nilai | Keterangan |
|---|---|---|
| `scope` | `report` (default) \| `tasklist` | `report` = seluruh divisi, boleh dibaca semua role. `tasklist` = cakupan Tasklist user (Admin semua, Manajer divisinya, Staff tugas PIC-nya), angkanya sama dengan Board/Table untuk filter yang sama. |
| `division` | `code_division` | Pada `scope=tasklist` filter ini tidak dapat memperluas cakupan. |
| `status` | nilai status | |
| `priority` | `low` \| `medium` \| `high` \| `urgent` | |
| `search` | teks, maks. 150 | Judul tugas, sama seperti Tasklist. |
| `date_basis` | `deadline` (default) \| `created_at` | Tanggal yang dipakai filter periode. Lihat *Dasar tanggal periode* di bawah. |
| `date_from`, `date_to` | `YYYY-MM-DD` | Batas periode (inklusif) pada tanggal sesuai `date_basis`. Boleh diisi salah satu. `date_to` ≥ `date_from`. |

Tanpa parameter = konfigurasi default (Reset Filter): cakupan `report`, semua divisi, `date_basis=deadline`, tanpa periode.

**Dasar tanggal periode (`date_basis`):**

| Mode | Periode menyaring | Tugas tanpa deadline |
|---|---|---|
| `created_at` (Tanggal dibuat) | tanggal pembuatan tugas | tetap dihitung bila dibuat dalam periode |
| `deadline` (Deadline) | deadline tugas | tidak dihitung saat periode aktif |

- Mode yang dipilih berlaku untuk **seluruh** angka di respons: semua KPI di `metrics`, `by_priority`, dan `by_division`, bukan hanya Total Tasks.
- Periode hanya menentukan tugas mana yang dihitung. **Overdue** dan **Due Today** selalu dinilai dari deadline terhadap hari ini (`as_of`), apa pun modenya. Contoh: tugas yang dibuat November dengan deadline 1 Oktober dan belum Done tetap terhitung Overdue pada mode `created_at` periode November.
- Mode yang dipakai dikirim di `period` agar tampilan dapat memberi label, mis. "Periode (Deadline): 1–30 Nov 2026".

Contoh: hari ini 6 Nov 2026, filter periode 1–30 November 2026.

| Tugas | Dibuat | Deadline | `created_at` | `deadline` |
|---|---|---|---|---|
| A | 3 Nov | 10 Des | ✅ dihitung | ❌ tidak |
| B | 15 Okt | 20 Nov | ❌ tidak | ✅ dihitung |
| C (On Process) | 6 Nov | – | ✅ dihitung, tidak Overdue/Due Today | ❌ tidak |
| D (To Do) | 6 Nov | 1 Okt | ✅ dihitung sebagai Overdue | ❌ tidak |

**Respons `200`:**

```json
{
  "data": {
    "scope": { "key": "report", "label": "Seluruh divisi" },
    "as_of": "2026-11-06",
    "filters": { "search": null, "division": null, "priority": null, "status": null, "date_basis": "deadline", "date_from": null, "date_to": null },
    "period": { "basis": "deadline", "label": "Deadline", "from": null, "to": null, "active": false },
    "metrics": {
      "total_tasks": 8, "backlog": 1, "todo": 2, "on_process": 1, "ready_for_qa": 1, "on_qa": 1,
      "completed": 2, "unfinished": 6, "overdue": 3, "due_today": 1, "urgent": 2, "completion_rate": 25
    },
    "by_priority": { "low": { "total": 2, "unfinished": 1 }, "medium": { "total": 2, "unfinished": 2 }, "high": { "total": 1, "unfinished": 1 }, "urgent": { "total": 3, "unfinished": 2 } },
    "by_division": [
      { "code_division": "FIN", "name": "Finance", "total": 2, "completed": 1, "unfinished": 1, "overdue": 1, "completion_rate": 50 }
    ]
  }
}
```

- `scope.label` wajib ditampilkan di samping KPI. Untuk `tasklist` labelnya "Tugas divisi Anda" (Manajer) atau "Tugas yang ditugaskan kepada Anda" (Staff).
- `period` = dasar tanggal yang dipakai (`basis`, `label`: "Tanggal dibuat" atau "Deadline"), batas periode, dan `active` (`true` bila `date_from`/`date_to` diisi).
- `as_of` = tanggal hari ini menurut zona waktu aplikasi (`APP_TIMEZONE`), acuan Overdue dan Due Today.
- Rumus metrik sama dengan bagian Ringkasan di atas. `completion_rate` dibulatkan 2 desimal dan bernilai `0` bila `total_tasks` = 0. Tugas aktif = status selain `done`.
- Tidak ada tugas → semua angka `0`, `by_division` = `[]`, dan `by_priority` tetap berisi keempat prioritas.
- `422` bila parameter tidak valid (pesan di `errors.<param>`), `401` bila belum login.
