# API Tasklist

Kontrak data untuk halaman **Tasklist** (`/tasks`): Board, Table, ringkasan, Timeline, Calendar, detail tugas, pembuatan tugas, penugasan PIC, workflow status, evidence, review, dan histori (TASK-01 s.d. TASK-11).

**Status: Board/Table, ringkasan, Timeline, Calendar, pembuatan, detail, edit, penugasan, workflow status, evidence, review, dan histori tugas terintegrasi dengan backend.** `GET /api/tasks/meta`, `GET /api/tasks`, `GET /api/tasks/monitoring`, `GET /api/tasks/schedule`, `GET /api/tasks/calendar`, `GET /api/tasks/create-options`, `POST /api/tasks`, `GET /api/tasks/{id}`, `GET /api/tasks/{id}/preview`, `PATCH /api/tasks/{id}`, `GET /api/tasks/{id}/assignment-options`, `PATCH /api/tasks/{id}/assignment`, `PATCH /api/tasks/{id}/status`, `GET /api/tasks/{id}/evidences`, `POST /api/tasks/{id}/evidences`, `POST /api/tasks/{id}/reviews`, dan `GET /api/tasks/{id}/activities` dipanggil langsung, tanpa bergantung pada `VITE_TASK_API_READY`. Daftar evidence di detail diambil dari `evidence` pada `GET /api/tasks/{id}`. Keputusan review dari Board (drag On Review → Done untuk approve, On Review → Back Log membuka form revisi) dan dari detail selalu memakai `POST /api/tasks/{id}/reviews` dengan `version`, tidak pernah `PATCH /status`; drag keputusan review di Board serta tombol dan form keputusan di detail mengikuti `can_review`. Filter daftar tugas dikirim ke API; endpoint Tasklist tambahan tetap dinonaktifkan sampai tersedia.

Wajib login. Header dan kode error umum mengikuti [authentication.md](authentication.md). Tanggal memakai format `YYYY-MM-DD`; waktu memakai ISO 8601.

## Role & cakupan data

Role dan kewenangan membuat tugas berasal dari `GET /api/tasks/meta`. Jika pemuatan meta gagal, frontend memakai pemetaan role akun `ADM` → `admin`, `MGR` → `manager`, serta `SPV`, `EMP`, dan `FRL` → `staff`.

Semua akun **aktif** melihat daftar dan detail tugas **seluruh divisi** (Board, Table, Calendar, Timeline, histori, evidence, review, monitoring). Melihat tidak memberi hak mengubah; hak mengubah mengikuti permission role (`meta.permissions`), cakupan divisi, dan assignment PIC aktif.

| Role | Melihat | Membuat | Edit field | Atur PIC | Pindah divisi | Status | Review (termasuk self-approval) | Hapus evidence sendiri |
|---|---|---|---|---|---|---|---|---|
| `admin` (`ADM`) | Semua tugas | Semua divisi | Semua divisi | Semua divisi, PIC dari semua divisi | Ya | Semua divisi | Semua divisi | Bila dia PIC aktif |
| `manager` (`MGR`) | Semua tugas | Semua divisi | Semua divisi | Semua divisi, PIC dari semua divisi | Ya | Semua divisi | Semua divisi | Bila dia PIC aktif |
| Supervisor (`SPV`, `meta.role` = `staff`) | Semua tugas | Divisinya | Divisinya (kecuali divisi) | Tugas divisinya, PIC baru hanya dari divisinya | Tidak | Divisinya; Back Log sampai Ready Review juga bila dia PIC aktif tugas divisi lain | Divisinya | Bila dia PIC aktif |
| Staff (`EMP`) dan Freelance (`FRL`), `meta.role` = `staff` | Semua tugas | Tidak | Tidak | Tidak | Tidak | Hanya sebagai PIC aktif: Back Log → To Do → On Process → Ready Review. Tidak pernah ke On Review atau Done | Tidak | Bila dia PIC aktif |

- Tugas di luar kewenangan tampil **read-only**: `can_edit`, `can_assign`, `can_delete`, `can_review`, `can_comment` `false`, `editable_fields` dan `next_statuses` kosong. FE cukup mengikuti flag ini.
- Perubahan tanpa hak lewat API ditolak `403`. Perubahan yang diizinkan tetapi tidak sah untuk kondisi tugas (mis. transisi status yang salah) ditolak `422` dengan alasan.
- PIC yang dilepas tetap melihat tugas lewat `all_tasks`, tetapi hilang dari `my_tasks` dan kehilangan hak sebagai PIC.
- Akun nonaktif tidak bisa login dan mendapat `403` di semua endpoint Tasklist.
- `meta.role` tetap tiga nilai (`admin`, `manager`, `staff`) agar kode lama tidak rusak; pakai `meta.role_code` untuk membedakan Supervisor dan Freelance, dan `meta.can_create` untuk tombol Buat Tugas.

Backend **wajib** menerapkan aturan ini di setiap endpoint. Aturan di frontend hanya untuk tampilan.

**Frontend (TASK-12)**:

- Nama role (Admin, Manajer, Supervisor, Staff, Freelance) berasal dari `meta.role_code`. Filter divisi tersedia untuk semua role; nilai awalnya mengikuti `meta.default_division`.
- Papan dan Tabel memiliki cakupan **Semua Tugas** (`scope=all_tasks`) dan **Tugas Saya** (`scope=my_tasks`). Cakupan tersimpan di URL (`?scope=`) dan filter aktif dipertahankan saat berpindah cakupan.
- `GET /api/tasks/monitoring` belum memiliki cakupan `my_tasks`, sehingga ringkasan pada Tugas Saya dihitung frontend dari daftar `my_tasks` dengan rumus yang sama.
- Tugas tanpa `can_edit`, `can_assign`, dan `can_delete` diberi tanda **Hanya lihat**. Tombol Ubah, Atur PIC, Hapus, dan keputusan review mengikuti flag backend per tugas.
- Pemilihan PIC mengirim daftar lengkap `pic_ids`. Tombol Hapus Tugas memakai `DELETE /api/tasks/{id}` dengan `version`.

**Frontend (TASK-14)**: form Filter Periode di Papan dan Tabel baru berlaku setelah **Terapkan Periode**. Periode yang diterapkan dikirim sebagai parameter [Filter periode](#filter-periode-task-14) ke `GET /api/tasks` dan `GET /api/tasks/monitoring?scope=tasklist`, dan tetap berlaku saat berpindah Papan/Tabel atau Semua Tugas/Tugas Saya. **Atur Ulang Filter** menghapusnya. Pesan `422` untuk parameter periode ditampilkan di form periode.

**Filter PIC (TASK-14)**: pilihan PIC di Papan dan Tabel diambil dari `pics` pada `GET /api/tasks/meta` dan dikirim sebagai `pic_id` ke `GET /api/tasks` dan `GET /api/tasks/monitoring?scope=tasklist`, bersama filter lain dan periode. Pilihan tetap berlaku saat berpindah Papan/Tabel atau Semua Tugas/Tugas Saya; di Tugas Saya hasilnya tetap hanya tugas dengan akun login sebagai PIC aktif. **Atur Ulang Filter** mengembalikannya ke Semua PIC.

## Objek

**Task** (daftar, Board, Table, Calendar):

```json
{
  "id": 105,
  "title": "Update landing page JadiASN",
  "division": { "code_division": "MKT", "name": "Marketing" },
  "pics": [{ "id": 7, "fullname": "Budi" }, { "id": 9, "fullname": "Sari" }],
  "priority": "high",
  "status": "in_progress",
  "start_date": "2026-10-16",
  "deadline": "2026-10-20",
  "requires_review": true,
  "evidence_required": true,
  "evidence_count": 0,
  "can_view_detail": true,
  "can_change_status": true,
  "can_review": false,
  "version": 1,
  "updated_at": "2026-10-21T03:00:00Z"
}
```

- `priority`: `low` | `medium` | `high` | `urgent`.
- `status`: `backlog` | `todo` | `in_progress` | `ready_review` | `on_review` | `done` (label: Back Log, To Do, On Process, Ready Review, On Review, Done).
- `pics`: semua PIC aktif, urut nama; `[]` bila belum ada PIC. Tidak ada Lead PIC. Field `pic` (satu PIC) sudah dihapus.
- `can_change_status`: `true` bila user boleh memindahkan status tugas ini sekarang lewat endpoint status (permission `task.change_status` serta PIC aktif atau pengelola sampai Ready Review; hanya reviewer ke On Review dan Done). Board hanya mengizinkan drag bila `true`; keputusan review dari `on_review` mengikuti `can_review`.
- `can_review`: `true` bila user boleh memberi keputusan review (approve/revisi) sekarang: tugas `on_review` yang wajib review, akun aktif dengan permission `task.review`, dan Admin/Manajer untuk semua divisi atau Supervisor untuk divisinya. Board hanya mengizinkan drag On Review → Done/Back Log bila `true`.
- `can_assign`: `true` bila user boleh mengatur PIC tugas ini sekarang.
- `can_delete`: `true` bila user boleh menghapus tugas ini sekarang (Admin dan Manajer semua divisi, Supervisor divisinya; bukan untuk tugas Done).
- `start_date` dan `deadline` boleh `null`.
- `version` dipakai untuk mencegah perubahan berbasis data lama (lihat 409).

**TaskDetail** = Task ditambah `description`, `category` (`{ id, name, requires_review, evidence_required }` | null), `platform` (`{ id, name }` | null), `creator` (`{ id, fullname }` | null), `assignments` (array Assignment: riwayat PIC aktif dan yang sudah dilepas, terlama lebih dulu), `evidence` (array Evidence), `history` (array Activity), `reviews` (semua Review, terbaru lebih dulu), `last_review` (Review terbaru | null), `can_comment` (`true` bila user boleh mengirim komentar saat ini; lihat [Komentar](#komentar--balasan-task-16)), `editable_fields`, `next_statuses`, `created_at`, dan `completed_at`. `next_statuses` tidak memuat status tujuan dari `on_review`, karena keputusan review memakai endpoint review.

**Activity** (histori, `GET /api/tasks/{id}/activities`):

```json
{
  "id": 42,
  "action": "reassigned",
  "action_label": "PIC diganti",
  "description": "PIC: Budi → Budi, Sari",
  "changes": { "pics": { "old": [{ "id": 7, "name": "Budi" }], "new": [{ "id": 7, "name": "Budi" }, { "id": 9, "name": "Sari" }] } },
  "actor": { "id": 3, "fullname": "Manajer Marketing" },
  "created_at": "2026-11-06T02:00:00Z"
}
```

- `action`: `created` | `updated` | `assigned` | `reassigned` | `unassigned` | `status_changed` | `evidence_submitted` | `approved` | `revision_requested` | `resubmitted`. Perubahan PIC: `assigned` = hanya menambah PIC, `unassigned` = hanya melepas, `reassigned` = menambah dan melepas sekaligus. Penerusan divisi tanpa perubahan PIC dicatat `updated`. `comment_added` (Komentar ditambahkan) dicatat satu kali untuk setiap komentar atau balasan baru, dengan `changes.comment.new = { id, parent_comment_id }` tanpa isi komentar; `description` balasan berisi "Membalas komentar". Tipe `deleted` (Tugas dihapus) dan `evidence_deleted` (Evidence dihapus) dipakai fitur hapus tugas dan hapus evidence.
- `changes`: nilai sebelum dan sesudah untuk tiap field yang berubah (`{}` untuk `created`). Perubahan PIC memakai `changes.pics` berisi daftar PIC aktif sebelum dan sesudah.
- `actor.id` bernilai `null` bila akun pelaku sudah dihapus; `actor.fullname` tetap berisi nama pelaku saat aktivitas terjadi.

## Endpoint

| Method & path | Body / query | Respons |
|---|---|---|
| `GET /api/tasks/meta` | – | `{ data: { role, role_code, permissions, division, default_division, can_create, divisions, pics, statuses, priorities } }`. `pics` = PIC aktif dari tugas yang boleh dilihat akun itu (`{ id, fullname }`, urut nama, masing-masing sekali), untuk filter PIC Tasklist. `role` tetap `admin` \| `manager` \| `staff`; `role_code` = kode role akun (`ADM`, `MGR`, `SPV`, `EMP`, `FRL`); `permissions` = kode permission role itu (misalnya `task.view_all`, `task.assign`), urut abjad. Permission hanya menyatakan kewenangan umum; akses per tugas tetap diputuskan backend. |
| `GET /api/tasks` | `scope` (`all_tasks` default \| `my_tasks`), `search`, `division`, `pic_id`, `priority`, `status`, `filter_mode`, `date_field`, `date`, `month`, `year`, `start_date`, `end_date`, `sort`, `direction`, `per_page`, `page` (semua opsional; lihat [Filter periode](#filter-periode-task-14)) | `{ data: [Task], meta: { status_counts } }`, tugas dalam cakupan user. `my_tasks` = hanya tugas yang user menjadi PIC aktifnya. Tugas dengan banyak PIC tetap muncul sekali di daftar, halaman, dan `status_counts`. `sort=pic` memakai nama PIC aktif pertama (abjad). Tanpa `per_page` semua tugas dikembalikan sekaligus; Board/Table mengirim filter ke server dan mengurutkan serta mempaginasi di frontend. |
| `GET /api/tasks/create-options` | – | `divisions` (divisi yang boleh dibuatkan tugas), `pics` (semua akun yang dapat menjadi PIC dari semua divisi: `{ id, fullname, role, division: { id, code_division, name } \| null }`), kategori/aturan review, platform, prioritas, status awal, dan default form. Admin/Manajer saja. |
| `POST /api/tasks` | `title`*, `description`, `category_id`, `division_id`*, `pic_ids` (array ID akun, maks. 20), `platform_id`, `priority`, `start_date`, `deadline`, `status`, `requires_review`, `evidence_required` | `201 { data: TaskDetail, message }`; Admin/Manajer saja. Field pembuat ditentukan dari akun yang login. Setiap PIC mendapat assignment aktif dengan `assigned_by` = pembuat. |
| `GET /api/tasks/{id}` | – | `{ data: TaskDetail }` |
| `GET /api/tasks/{id}/preview` | – | `{ data: TaskPreview }` untuk pop-up Calendar: field Task (termasuk `can_review`) ditambah `description` dan `is_overdue`. Dibaca ulang dari database setiap kali dipanggil dan tidak mengubah data. `pics` berisi seluruh PIC aktif tanpa duplikasi (`[]` bila belum ada); `start_date` dan `deadline` `null` bila kosong. Semua akun aktif; `404` bila tugas tidak ada atau sudah dihapus |
| `DELETE /api/tasks/{id}` | `version`* | `{ message: "Tugas berhasil dihapus." }`. Soft delete; hanya Admin/Manajer (semua divisi) dan Supervisor (divisinya), selain itu `403`. `422` di `errors.status` bila tugas sudah Done; `409` bila `version` usang |
| `PATCH /api/tasks/{id}` | `title`, `description`, `category_id`, `platform_id`, `priority`, `start_date`, `deadline`, `version`* | `{ data: TaskDetail, message }` |
| `PATCH /api/tasks/{id}/status` | `status`*, `version`* | `{ data: TaskDetail, message }`; semua transisi kecuali keluar dari `on_review` (approve/revisi memakai `POST /api/tasks/{id}/reviews`). `422` dengan alasan di `errors.status` bila transisi ditolak; `409 { message, current_version }` bila `version` sudah usang |
| `GET /api/tasks/{id}/assignment-options` | – | `{ data: { current_pics, current_division, can_change_division, locked_reason, divisions, pics } }`. `divisions` = tujuan penerusan (semua divisi bila `can_change_division`, yaitu Admin/Manajer; selain itu divisi tugas saat ini). `pics` = akun yang boleh ditambahkan user sebagai PIC: semua divisi untuk Admin/Manajer, divisinya sendiri untuk Supervisor (bentuk sama dengan `create-options`). |
| `PATCH /api/tasks/{id}/assignment` | `pic_ids`* (array ID akun, boleh `[]`, maks. 20), `version`*, `note` (opsional, maks. 1000), `division_id` (opsional; untuk meneruskan, hanya Admin/Manajer; Supervisor `422` di `errors.division_id`) | `{ data: TaskDetail, message }`. `pic_ids` adalah daftar PIC lengkap setelah perubahan: PIC yang tidak ada di daftar dilepas, PIC baru ditambahkan, PIC yang tetap tidak disentuh. `422` di `errors.pic_ids` bila tidak ada perubahan, PIC terkunci, atau daftar kosong untuk tugas yang bukan `backlog`; `422` di `errors.pic_ids.N` bila akun tidak memenuhi syarat; `409` bila `version` usang. |
| `GET /api/tasks/{id}/evidences` | – | `{ data: [Evidence] }` |
| `DELETE /api/tasks/{id}/evidences/{evidence}` | – | `{ message: "Evidence berhasil dihapus." }` (TASK-13). Hanya PIC aktif yang mengunggahnya; PIC lain, PIC yang sudah dilepas, Admin/Manajer/Supervisor bukan pengunggah, dan evidence lama tanpa pengunggah `403`. `422` di `errors.evidence` bila tugas tidak berstatus `backlog`/`todo`/`in_progress`. `404` bila bukan evidence tugas itu atau sudah dihapus |
| `GET /api/tasks/{id}/evidences/{evidence}/download` | – | File evidence sebagai unduhan (hanya untuk yang dapat melihat tugas; `404` bila bukan evidence tugas itu, berupa tautan, atau sudah dihapus) |
| `GET /api/tasks/{id}/evidences/{evidence}/preview` | – | Gambar JPG/PNG (dan WEBP lama) ditampilkan inline untuk thumbnail atau preview (TASK-18). Akses sama dengan download; `404` untuk dokumen, tautan, atau evidence yang sudah dihapus. Pakai `preview_url` dari Evidence |
| `POST /api/tasks/{id}/evidences` | multipart: `file` **atau** `url`, `note` | `201 { data: Evidence, message }`; hanya PIC (`403` untuk yang lain); `422` dengan pesan di `errors.file`/`errors.url`, atau di `errors.evidence` bila status tugas tidak menerima evidence |
| `GET /api/tasks/{id}/reviews` | – | `{ data: [Review] }`, terbaru lebih dulu; dapat dilihat siapa pun yang dapat melihat tugas (termasuk PIC) |
| `POST /api/tasks/{id}/reviews` | `decision`* (`approve`\|`revision`), `note` (wajib untuk `revision`, maks. 2000), `version`* | `{ data: TaskDetail, message }`; `403` bila bukan Admin/Manajer atau Supervisor divisi tugas atau tugas tidak wajib review; `422` di `errors.note` bila revisi tanpa alasan, di `errors.decision` bila tugas tidak berstatus `on_review`; `409 { message, current_version }` bila `version` usang |
| `GET /api/tasks/{id}/comments` | – | `{ data: [Comment] }`, terlama lebih dulu (TASK-16). Semua akun aktif yang dapat melihat tugas, termasuk PIC yang sudah dilepas |
| `POST /api/tasks/{id}/comments` | `content`* (maks. 2000), `client_request_id`* (UUID per pengiriman), `parent_comment_id` (untuk balasan) | `201 { data: Comment, message }` (TASK-16). `200` dengan komentar yang sudah tersimpan bila `client_request_id` yang sama dikirim ulang (lihat [Komentar](#komentar--balasan-task-16)). `403` bila user tidak boleh berkomentar; `422` di `errors.content` bila kosong, terlalu panjang, atau tugas sudah Done; `422` di `errors.parent_comment_id` bila komentar yang dibalas bukan milik tugas itu; `422` di `errors.client_request_id` bila tidak dikirim, bukan UUID, atau sudah dipakai untuk komentar di tugas lain |
| `GET /api/tasks/{id}/activities` | – | `{ data: [Activity] }` |
| `GET /api/tasks/schedule` | `from`*, `to`*, `division` | `{ data: [ScheduleItem] }` |
| `GET /api/tasks/calendar` | `month`* (`YYYY-MM`), `division` | `{ data: { events: [Event], deadlines: [Task] } }` |

`*` = wajib.

- **Evidence**: `{ id, task_id, type, kind, file_name, mime_type, file_size, file_url, preview_url, url, note, submitter: { id, fullname }, can_delete, created_at }`. `can_delete` = `true` bila user boleh menghapus evidence ini sekarang. `type` = `file` (isi `file_name`/`file_url`) atau `link` (isi `url`). `kind` (TASK-18) = `document`, `image`, atau `link` untuk tampilan. `mime_type` (hasil deteksi isi file di server) dan `file_size` (byte) hanya untuk file; evidence lama tanpa metadata membaca `mime_type` dari ekstensi nama file dan `file_size`-nya `null`. `preview_url` hanya terisi untuk gambar. `submitter` = pengunggah, `created_at` = waktu unggah. Detail tugas mengirimnya di `evidence`, terbaru lebih dulu; `[]` bila tugas belum punya evidence.
- **Review**: `{ id, task_id, cycle, decision, decision_label, note, reviewer: { id, fullname }, created_at }`. `decision` = `approve` (label Approve) atau `revision` (label Request Revision). `cycle` = urutan pemeriksaan tugas itu (1, 2, ...), dihitung dari urutan review (tidak disimpan sebagai kolom sejak ERD TASK-19). Review tidak dapat diubah atau dihapus.
- **Comment**: `{ id, task_id, parent_comment_id, content, author: { id, fullname, role: { code_role, name } }, reply_to: { id, author: { id, fullname } } | null, created_at }`. `parent_comment_id` dan `reply_to` bernilai `null` untuk komentar utama. `role` = role penulis saat ini.
- **Activity**: lihat bentuk JSON di atas (`action`, `action_label`, `description`, `changes`, `actor`). Histori hanya ditambahkan oleh sistem, tidak bisa diedit.
- **Assignment** (di `TaskDetail.assignments`): `{ id, account: { id, fullname }, assigned_by: { id, fullname } | null, assigned_at, unassigned_by: { id, fullname } | null, unassigned_at, is_active, note }`. Assignment aktif = `unassigned_at` `null`. Melepas PIC tidak menghapus barisnya; menugaskan ulang PIC yang sudah dilepas membuat assignment baru.
- **Mengatur PIC**: lewat `PATCH /api/tasks/{id}/assignment` dengan `version` dan daftar lengkap `pic_ids`. `PATCH /api/tasks/{id}` tidak mengubah PIC. Sertakan `division_id` bila Admin atau Manajer meneruskan tugas ke divisi lain. Backend tidak mengubah status ketika PIC/divisi diperbarui dan mencatat daftar PIC sebelum/sesudah di `history`. Penugasan terkunci saat tugas berada di tahap review (`ready_review`, `on_review`) atau sudah selesai.
- **ScheduleItem**: `{ id, type: "task"|"event", title, division, is_shared, target_divisions, start_date, end_date, status, priority, task_id, can_view_detail }`. `status` dan `priority` dipakai agar filter Tasklist ikut berlaku di Timeline (agenda bersama tidak disaring), dan bernilai `null` untuk `event`; sebaliknya `is_shared` hanya untuk `event` (`null` untuk tugas). Untuk `event`, `task_id` selalu `null` dan `can_view_detail` selalu `false` (agenda tidak terhubung ke tugas di ERD). Untuk tugas, `can_view_detail` hanya `true` bila user berhak membuka detailnya. Lihat [Timeline](#timeline-task-10).
- **Event** (Calendar): `{ id, title, division, is_shared, target_divisions, start_date, end_date, description, task_id, can_view_detail }`. `task_id` selalu `null` dan `can_view_detail` selalu `false`: agenda tidak terhubung ke tugas, sehingga tombol "Lihat Detail Tugas Terkait" tidak muncul. `deadlines` hanya berisi tugas dalam cakupan user dan yang memiliki deadline, berupa objek Task ditambah `task_id` (sama dengan `id`), `description` (boleh `null`), dan `is_overdue`. Lihat [Calendar](#calendar-task-11).
- **Lihat Detail dari Calendar (TASK-15)**: frontend membawa `task_id` ke Board dan membuka detail lewat `GET /api/tasks/{id}`. Bila tugas tersembunyi oleh filter atau pagination, frontend menyesuaikan filter yang menghalangi (cari, prioritas, status, PIC, periode, divisi, dan cakupan `my_tasks`) dan tetap menampilkan Task Card dari respons detail. Navigasi tidak mengubah data dan tidak menambah hak edit: akses tetap diputuskan backend (`403`/`404`), dan tombol mengikuti flag `can_*`.
- **Tautan langsung**: `/tasks?task={id}` membuka Board dengan detail tugas tersebut memakai alur yang sama. Parameter `task` diperbarui saat panel detail dibuka dan dihapus saat ditutup.

## Filter periode (TASK-14)

`GET /api/tasks` dan `GET /api/tasks/monitoring?scope=tasklist` menerima parameter periode yang sama. Tanpa `filter_mode` = tanpa filter periode.

| `filter_mode` | Parameter wajib | Periode |
|---|---|---|
| `date` | `date` (`YYYY-MM-DD`) | Satu hari |
| `month` | `month` (1 sampai 12), `year` (4 digit) | Seluruh bulan pada tahun itu |
| `year` | `year` (4 digit, 1000 sampai 9999) | 1 Januari sampai 31 Desember |
| `range` | `start_date`, `end_date` (`YYYY-MM-DD`) | Termasuk kedua tanggal; `end_date` tidak boleh sebelum `start_date` |

- `date_field` (`start_date` atau `deadline`) wajib dikirim bersama `filter_mode`.
- Kirim hanya parameter milik mode yang aktif. Parameter mode lain, atau parameter periode tanpa `filter_mode`, ditolak `422`.
- Tugas yang tanggalnya kosong pada `date_field` tidak masuk hasil.
- `pic_id` (ID akun) menyaring tugas dengan akun itu sebagai PIC aktif. PIC yang sudah dilepas tidak cocok.
- Periode dapat digabung dengan `scope`, `search`, `division`, `pic_id`, `priority`, `status`, `sort`, `direction`, `per_page`, dan `page`. Filter tidak mengubah hak akses.
- Tugas dengan banyak PIC tetap satu baris dan dihitung satu kali di daftar, `status_counts`, dan KPI.
- Agar KPI sama dengan Board dan Table, kirim parameter yang sama ke monitoring dengan `scope=tasklist`, dan kirim cakupan daftar sebagai `task_scope` (`all_tasks` atau `my_tasks`).

Contoh: `GET /api/tasks?scope=my_tasks&filter_mode=month&date_field=deadline&month=11&year=2026&status=todo`

## Timeline (TASK-10)

**Status: backend tersedia.** `GET /api/tasks/schedule?from=YYYY-MM-DD&to=YYYY-MM-DD&division=KODE` mengembalikan `{ data: [ScheduleItem] }`, hanya-baca (tidak ada endpoint untuk mengubah jadwal).

- **Validasi** (`422`): `from` dan `to` wajib berformat `YYYY-MM-DD`, `to` tidak boleh sebelum `from`, periode maksimal 62 hari, dan `division` harus kode divisi yang ada.
- **Tugas**: tugas semua divisi yang rentang tanggalnya bersinggungan dengan periode, untuk semua role. `start_date` = tanggal mulai, `end_date` = deadline. Tugas yang hanya punya salah satu tanggal dihitung satu hari pada tanggal itu (tanggal lainnya `null`); tugas tanpa tanggal tidak dikirim.
- **Detail internal tidak dikirim**: PIC, deskripsi, evidence, dan histori tidak ada di ScheduleItem. `can_view_detail` mengikuti aturan `GET /api/tasks/{id}`, yang kini `true` untuk semua akun aktif.
- **Agenda (`event`)** dari tabel `calendar_events` (sumber tunggal agenda). Aturan aksesnya sama dengan aplikasi sebelumnya: agenda bersama (`is_shared` `true`) terlihat oleh semua akun aktif; agenda internal (`is_shared` `false`) hanya oleh Admin dan akun dari divisi tujuannya (`target_divisions`), termasuk Manajer: Manajer Finance tidak melihat agenda internal Sales. Agenda internal tanpa divisi tujuan hanya terlihat oleh Admin; akun tanpa divisi hanya melihat agenda bersama. `division` = divisi penyelenggara (boleh `null`) dan tidak memberi akses. `target_divisions` = semua divisi tujuan agenda internal (`[]` untuk agenda bersama). `task_id` `null` dan `can_view_detail` `false`. Agenda tanpa `end_date` berlangsung satu hari.
- **Filter `division`**: tugas divisi itu, agenda yang diselenggarakan divisi itu atau menjadikannya divisi tujuan, ditambah agenda bersama tanpa penyelenggara. Filter tidak pernah memperluas visibilitas agenda. Tanpa `division` = Semua Divisi (default Admin).
- **Urutan**: berdasarkan hari mulai (tugas tanpa tanggal mulai memakai deadline), tugas sebelum agenda pada hari yang sama.
- Agenda belum punya endpoint kelola; data contoh lokal berasal dari `CalendarEventSeeder`.
- **Frontend**: Timeline memanggil endpoint ini langsung (tanpa `VITE_TASK_API_READY`) untuk periode 1 atau 2 minggu yang tampil. Filter divisi Timeline terpisah dari filter divisi Tasklist dan berisi semua divisi (`GET /api/divisions`), sehingga semua role dapat melihat jadwal lintas divisi: default Admin dan Staff Semua Divisi, Manajer divisinya. Pencarian, prioritas, dan status Tasklist ikut menyaring tugas; agenda selalu tampil. Memilih item membuka detail tugas (`task_id`) hanya bila `can_view_detail`; selain itu hanya informasi jadwal. Respons periode/divisi sebelumnya diabaikan.

## Calendar (TASK-11)

**Status: backend tersedia.** `GET /api/tasks/calendar?month=YYYY-MM&division=KODE` mengembalikan `{ data: { events: [Event], deadlines: [Task] } }`, hanya-baca. Kedua daftar dipisah: agenda bersama di `events`, deadline tugas di `deadlines`.

- **Validasi** (`422`): `month` wajib berformat `YYYY-MM`; `division` harus kode divisi yang ada.
- **`events`**: agenda (`calendar_events`) yang bersinggungan dengan bulan itu, dengan aturan visibilitas yang sama seperti [Timeline](#timeline-task-10): agenda bersama (`is_shared`) untuk semua akun aktif, agenda internal hanya untuk Admin dan akun divisi tujuannya. Hanya informasi umum (nama, divisi, tanggal, keterangan) yang dikirim.
- **`deadlines`**: tugas dengan deadline di bulan itu, dalam cakupan yang sama dengan `GET /api/tasks` (semua divisi untuk akun aktif). Tugas tanpa deadline tidak ikut. Urut berdasarkan deadline. Setiap tugas tampil satu kali walaupun memiliki beberapa PIC; seluruh PIC aktifnya ada di `pics`. Pakai `task_id` untuk membuka ringkasan lewat `GET /api/tasks/{id}/preview`.
- **`is_overdue`**: `true` bila deadline sudah lewat (sebelum hari ini) dan status bukan `done`.
- **Filter `division`**: deadline divisi itu (tetap di dalam cakupan user, jadi filter tidak pernah memperluas akses) dan agenda yang diselenggarakan divisi itu atau menjadikannya divisi tujuan, ditambah agenda bersama tanpa penyelenggara. Tanpa `division` = Semua Divisi (default Admin).
- **Frontend**: Calendar memanggil endpoint ini langsung (tanpa `VITE_TASK_API_READY`) untuk bulan yang tampil. Hanya Admin yang mengirim `division` (filter divisi Tasklist, default Semua Divisi). Manajer dan Staff tidak mengirim `division`, sehingga agenda bersama lintas divisi tetap tampil, sementara deadline mereka tetap dibatasi backend. Pencarian, prioritas, dan status Tasklist ikut menyaring deadline; agenda selalu tampil. "Lihat Detail Tugas Terkait" pada agenda hanya tampil bila `can_view_detail`. Respons bulan sebelumnya diabaikan.
- **Pop-up ringkasan tugas (TASK-15)**: klik tanggal menampilkan agenda dan deadline tanggal itu; klik item tugas (judul di sel kalender pada desktop, atau di daftar deadline tanggal terpilih) membuka pop-up yang selalu memanggil `GET /api/tasks/{id}/preview`, sehingga status, tanggal, dan seluruh PIC aktif mengikuti data terbaru. Pop-up hanya-baca, punya state loading, error dengan Coba Lagi, dan `404` ("Tugas ini sudah dihapus atau tidak ditemukan.", item dihapus dari Calendar). Tanggal atau PIC kosong ditampilkan sebagai "Belum diatur" / "Belum ada PIC". Menutup pop-up tidak mengubah bulan atau tanggal terpilih. Klik agenda tetap menampilkan ringkasan agenda. **Lihat Detail** mengikuti aturan di [Lihat Detail dari Calendar](#endpoint).

## Aturan bisnis yang harus divalidasi backend

**Pembuatan tugas (TASK-02)**
- `title` dan `division` wajib.
- Admin dan Manajer boleh membuat tugas untuk semua divisi, Supervisor hanya untuk divisinya (`422` pada `division_id` bila lain). Staff dan Freelance `403`.
- Status awal hanya `backlog` atau `todo`. Status `todo` wajib memiliki minimal satu PIC di `pic_ids`.
- `requires_review` ditentukan dari kategori dan disimpan bersama tugas.
- Pembuat tugas diambil dari akun yang login.
- `client_request_id` sama berarti permintaan yang sama: jangan buat tugas ganda, kembalikan tugas yang sudah dibuat.
- Field Poin dari sistem lama tidak ada.

**Transition matrix (TASK-05, diperbarui TASK-17)**. Perpindahan lain ditolak dengan `422`.

| Dari | Ke | Oleh |
|---|---|---|
| `backlog` | `todo` | PIC aktif mana pun, Admin/Manajer, atau Supervisor divisinya (tugas harus punya minimal satu PIC aktif) |
| `todo` | `in_progress` | PIC aktif mana pun, Admin/Manajer, atau Supervisor divisinya |
| `in_progress` | `ready_review` | PIC aktif mana pun, Admin/Manajer, atau Supervisor divisinya, jika `requires_review = true` |
| `in_progress` | `done` | Admin/Manajer, atau Supervisor divisinya, jika `requires_review = false`. PIC Staff/Freelance tidak pernah ke `done` |
| `ready_review` | `on_review` | Admin/Manajer, atau Supervisor divisinya |
| `on_review` | `done` | Admin/Manajer, atau Supervisor divisinya (via `reviews`, `decision=approve`) |
| `on_review` | `backlog` | Admin/Manajer, atau Supervisor divisinya (via `reviews`, `decision=revision`, `note` wajib) |

- Semua PIC aktif menjalankan workflow bersama; satu PIC dapat memindahkan status tanpa persetujuan PIC lain.
- **Frontend (TASK-17)**: Board dan tombol status di detail memakai matrix yang sama. Kolom yang tidak boleh dituju menolak drop dan menampilkan alasannya; kartu tetap di kolom asal, juga bila backend menolak (`403`/`409`/`422`). Staff/Freelance hanya menggeser kartu dengan dirinya sebagai PIC aktif sampai Ready Review. Langkah ke On Review dan Done hanya untuk Admin, Manajer, atau Supervisor divisinya yang memiliki `task.review` (dari `meta.permissions`) dan mengikuti `can_change_status`; drag On Review → Done menjalankan Approve dan On Review → Back Log membuka form Request Revision dengan alasan wajib, keduanya mengikuti `can_review`. Detail menampilkan Riwayat Review dari `reviews` (siklus, keputusan, reviewer, waktu, alasan).
- Pengelola (Admin, Manajer, Supervisor divisinya) memindahkan status sampai `ready_review` dan memulai review. Hanya pengelola dengan permission `task.review` yang dapat memindahkan ke `on_review` atau `done`, termasuk bila dia juga PIC aktif (self-approval). `next_statuses` hanya memuat langkah yang boleh dilakukan user tersebut.
- Keluar dari `on_review` hanya lewat `POST /api/tasks/{id}/reviews`. `PATCH /status` dan `PATCH /api/tasks/{id}` ke `done`/`backlog` dari `on_review` ditolak `422`, dan `next_statuses` untuk `on_review` selalu `[]` (pakai `can_review`).

- Jika `evidence_required = true`, PIC tidak boleh ke `ready_review` atau `done` sebelum ada evidence.
- `done` menyimpan `completed_at`.

**PIC (TASK-04, multi-PIC TASK-19)**
- Satu tugas dapat memiliki banyak PIC aktif, dan satu akun dapat menjadi PIC di banyak tugas. Seluruh penugasan aktif dan riwayatnya disimpan di `task_assignments`.
- PIC harus akun aktif dengan role Staff (`EMP`), Freelance (`FRL`), atau Supervisor (`SPV`). PIC lintas divisi tidak memerlukan divisi kolaborator.
- Yang boleh mengatur PIC: Admin dan Manajer untuk semua divisi dengan PIC dari divisi mana pun; Supervisor untuk tugas divisinya dengan PIC **baru** hanya dari divisinya (TASK-13). Supervisor tetap boleh mempertahankan atau melepas PIC divisi lain yang sudah ada; menambah PIC divisi lain ditolak `422` di `errors.pic_ids.N` ("Supervisor hanya dapat menugaskan PIC dari divisinya sendiri."). Aturan yang sama berlaku di `POST /api/tasks`.
- Memindahkan tugas ke divisi lain (lewat `division_id` di assignment maupun form edit) hanya oleh Admin dan Manajer (TASK-13). Supervisor ditolak `422` di `errors.division_id`. Setelah dipindah, tugas keluar dari cakupan Supervisor divisi asal.
- Menambah atau melepas satu PIC tidak mengubah assignment PIC lain dan tidak mengubah status tugas.
- PIC tidak boleh diubah saat status `ready_review`, `on_review`, atau `done`. Tugas selain `backlog` harus selalu punya minimal satu PIC.
- Daftar PIC sebelum dan sesudah dicatat di histori; riwayat assignment tidak dihapus.
- Divisi utama tugas (`division_id`) tidak pernah disimpan sebagai divisi kolaborator (`task_divisions`); saat tugas dipindah ke divisi yang sebelumnya kolaborator, baris kolaborator itu dihapus.

**Evidence (TASK-06)**
- Hanya PIC aktif (siapa pun di antara PIC tugas itu) yang boleh mengirim evidence, saat status `backlog`, `todo`, atau `in_progress`, tanpa harus mengubah status tugas. Semua akun aktif dapat melihat, mengunduh, dan membuka preview-nya.
- Satu evidence berisi `file` **atau** `url`, tidak keduanya. `note` opsional, maks. 2000 karakter.
- File yang diterima (TASK-18): PDF, DOC, DOCX, JPG, JPEG, PNG. Jenis file dideteksi dari isinya, bukan dari ekstensi atau tipe yang dikirim browser, dan harus sama dengan ekstensi nama file. File PDF yang diganti namanya menjadi `.png`, atau file skrip, ditolak `422` di `errors.file`. Evidence lama berformat lain (WEBP, XLS/XLSX, PPT/PPTX, ZIP) tetap dapat dilihat dan diunduh.
- Batas ukuran mengikuti konfigurasi `tasklist.evidence.max_kilobytes` (env `TASK_EVIDENCE_MAX_KB`, default 10240 = 10 MB); pesan error menyebut batas yang berlaku. Batas ini harus tidak lebih besar dari `upload_max_filesize` dan `post_max_size` PHP.
- File disimpan privat dengan nama acak dari server dan hanya dapat diakses lewat endpoint download/preview, dengan header `X-Content-Type-Options: nosniff`. Pemindaian antivirus belum tersedia di infrastruktur.
- Tautan wajib URL valid dengan `http://` atau `https://`, maks. 2048 karakter.
- Evidence terikat pada tugas di URL; `task_id` di body diabaikan.
- PIC diperiksa ulang saat evidence disimpan (row tugas dikunci). Bila PIC diganti ketika evidence sedang dikirim, evidence dari PIC lama ditolak `403` dan file yang sudah terunggah dihapus.
- `evidence_required` mengikuti kategori: kategori dengan `evidence_required = true` selalu mewajibkan evidence. Untuk kategori lain, pembuat tugas boleh mengirim `evidence_required` di `POST /api/tasks`. Memindahkan tugas ke kategori yang mewajibkan evidence membuat evidence wajib.
- Submission tetap lewat `PATCH /api/tasks/{id}/status` (`ready_review` bila wajib review; tanpa review, `done` diberikan Admin/Manajer atau Supervisor divisinya); ditolak `422` bila evidence wajib tapi belum ada.
- Setelah revisi (`on_review` → `backlog`), PIC dapat langsung mengirim evidence baru, lalu menjalankan lagi workflow sampai submit ulang. Evidence lama tetap tersimpan dan tampil di detail (terbaru lebih dulu).
- Setiap evidence yang dikirim dicatat di histori dengan action `evidence_submitted`.
- **Hapus evidence (TASK-13)**: hanya PIC aktif yang mengunggahnya, dengan permission `task.update`, saat status `backlog`, `todo`, atau `in_progress` (evidence yang sedang/sudah direview tetap). Soft delete: `deleted_at`, `deleted_by`; file tetap disimpan untuk penelusuran, tetapi evidence hilang dari daftar dan tidak dapat diunduh (`404`). Tugas, statusnya, `version`, dan evidence lain tidak berubah. Dicatat di histori sebagai `evidence_deleted` dengan `changes.evidence.old` = `{ id, type, name }`. Bila evidence wajib dan evidence terakhir dihapus, PIC harus mengunggah lagi sebelum Ready Review. Mengubah evidence tidak tersedia: hapus lalu unggah ulang.

**Review & revisi (TASK-07)**
- Hanya tugas `requires_review = true` yang direview. Mulai pemeriksaan = `PATCH /status` dari `ready_review` ke `on_review` oleh Admin/Manajer atau Supervisor divisinya.
- Keputusan hanya untuk tugas `on_review`. Admin dan Manajer untuk semua divisi, Supervisor untuk divisinya; Manajer/Supervisor yang juga PIC aktif boleh menyetujui pekerjaannya sendiri (self-approval). Staff dan Freelance (termasuk PIC) selalu `403`.
- `approve` → `done` dan menyimpan `completed_at`. `revision` → `backlog`, `note` wajib (spasi saja dianggap kosong).
- Setelah revisi, PIC menjalankan lagi Back Log → To Do → On Process lalu mengirim ulang ke `ready_review`; histori mencatatnya sebagai `resubmitted`. Siklus dapat berulang sampai disetujui; semua review sebelumnya tetap tersimpan.
- Keputusan dicatat di histori sebagai `approved` atau `revision_requested` dengan `changes.status` dan `changes.review` (`{ id, cycle, note }`).

**Hapus Task Card (TASK-12)**
- Hanya Admin dan Manajer (semua divisi) serta Supervisor (divisinya). Staff dan Freelance selalu `403`, termasuk bila mereka PIC.
- Semua status boleh dihapus kecuali `done` (`422`).
- Soft delete: tugas hilang dari daftar, Board, Table, Calendar, Timeline, ringkasan, dan monitoring; detail, preview, dan endpoint lain mengembalikan `404`. Histori, evidence, review, dan assignment tetap tersimpan; `deleted_by` dan aktivitas `deleted` mencatat siapa yang menghapus. Belum ada fitur memulihkan tugas.
- Agenda tidak terhubung ke tugas (ERD), jadi menghapus tugas tidak mengubah agenda.
- Konfirmasi sebelum menghapus ditampilkan FE; backend mewajibkan `version` sehingga tugas yang baru diubah orang lain tidak terhapus tanpa dilihat (`409`).

**Histori aktivitas (TASK-08)**
- Histori hanya dibuat setelah aktivitas berhasil; aksi yang ditolak tidak meninggalkan catatan.
- Urutan: terbaru lebih dulu; aktivitas pada detik yang sama diurutkan berdasarkan `id`. Tanpa aktivitas → `{ data: [] }`.
- Akses mengikuti detail tugas: semua akun aktif.
- Histori tidak dapat diedit atau dihapus, dan tetap tersedia setelah status atau PIC berubah.
- Nama pelaku disimpan saat aktivitas dicatat, sehingga tetap tampil walaupun akunnya dihapus.
- Frontend: bagian Histori Aktivitas di detail mengambil `GET /api/tasks/{id}/activities` setelah detail tampil, dengan state loading, kosong, dan error (tombol "Coba lagi") sendiri, sehingga kegagalan histori tidak menutup detail. Setelah perubahan berhasil, histori diperbarui dari `history` pada respons TaskDetail; respons histori yang lebih lama diabaikan.

**Data lama (409)**: setiap perubahan membawa `version` terakhir yang dilihat user. Jika berbeda dengan data di database, tolak dengan `409 Conflict`. Frontend lalu memuat ulang data dan meminta user memeriksa kembali.

## Komentar & Balasan (TASK-16)

- **Siapa yang boleh mengirim**: Admin dan Manajer di semua divisi, Supervisor di divisinya, dan setiap PIC aktif tugas itu, termasuk semua PIC pada tugas Multi-PIC. Selain itu hanya bisa membaca (`403`), termasuk PIC yang sudah dilepas. FE cukup mengikuti `can_comment` di TaskDetail.
- **Status**: komentar diterima pada `backlog`, `todo`, `in_progress`, `ready_review`, dan `on_review`. Pada `done` komentar tetap bisa dibaca, tetapi komentar baru ditolak `422`: "Tugas yang sudah selesai tidak dapat dikomentari."
- **Balasan**: kirim `parent_comment_id` berisi ID komentar pada tugas yang sama, baik komentar utama maupun balasan lain. Semua komentar tugas berada dalam satu thread; FE menyusun balasan memakai `parent_comment_id` atau `reply_to`.
- **Isi**: `content` dipangkas spasinya; isi kosong atau hanya spasi ditolak. Penulis selalu akun yang login (`author_id` dari body diabaikan). Isi dikembalikan apa adanya, jadi **FE wajib menampilkannya sebagai teks** (`x-text`), bukan HTML.
- **Pengiriman ganda**: FE membuat `client_request_id` baru (UUID, mis. `crypto.randomUUID()`) setiap kali user menekan Kirim, dan memakai ID yang sama bila request itu diulang (retry atau koneksi putus). Request dengan ID yang sudah tersimpan mengembalikan komentar tersebut (`200`) tanpa membuat komentar atau histori baru, kapan pun diulang. Pengiriman baru dengan ID baru selalu membuat komentar baru, meskipun teksnya sama. ID berlaku per akun; ID yang sama dari akun lain adalah pengiriman terpisah. Tampilkan komentar hanya setelah respons sukses.
- **Bukan keputusan review**: komentar tidak mengubah status, review, PIC, atau `version` tugas. Approve dan Request Revision tetap lewat `POST /api/tasks/{id}/reviews`.
- **Tidak hilang**: komentar tetap tersimpan setelah status, PIC, atau siklus revisi berubah.

## Kode respons

| Kode | Arti di frontend |
|---|---|
| `403` | "Anda tidak memiliki akses untuk tindakan ini." / detail: Akses ditolak |
| `404` | "Tugas tidak ditemukan atau sudah dihapus." / detail: Tugas tidak ditemukan |
| `409` | Data sudah diubah pengguna lain, data dimuat ulang |
| `422` | Pesan validasi tampil di bawah field. Pesan aturan bisnis diambil dari `message`, mis. alasan transisi ditolak |

## Ringkasan (TASK-09)

Ringkasan diambil dari `GET /api/tasks/monitoring?scope=tasklist` dengan filter yang sama seperti `GET /api/tasks` (search, division, priority, status), sehingga angkanya dihitung backend dalam cakupan Board/Table. Ringkasan dimuat bersama daftar tugas, punya state loading dan error (dengan "Coba lagi") sendiri, dan dimuat ulang setelah perubahan status, deadline, prioritas, judul, divisi, atau PIC tugas. Halaman menonjolkan empat metrik utama (Total Tasks, In Progress = status On Process, Overdue, Due Today); Completed, Unfinished, Urgent, dan Completion Rate tampil sebagai informasi tambahan, dan jumlah per status tampil di kolom Board:

- **Total Tasks**: semua tugas dalam cakupan dan filter.
- **Back Log, To Do, On Process, Ready Review, On Review**: jumlah per status.
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
| `scope` | `report` (default) \| `tasklist` | `report` = seluruh divisi, boleh dibaca semua role. `tasklist` = cakupan Tasklist user (semua divisi untuk akun aktif), angkanya sama dengan Board/Table untuk filter yang sama. |
| `division` | `code_division` | Pada `scope=tasklist` filter ini tidak dapat memperluas cakupan. |
| `status` | nilai status | |
| `priority` | `low` \| `medium` \| `high` \| `urgent` | |
| `search` | teks, maks. 150 | Judul tugas, sama seperti Tasklist. |
| `date_basis` | `deadline` (default) \| `created_at` | Tanggal yang dipakai filter periode. Lihat *Dasar tanggal periode* di bawah. |
| `date_from`, `date_to` | `YYYY-MM-DD` | Batas periode (inklusif) pada tanggal sesuai `date_basis`. Boleh diisi salah satu. `date_to` ≥ `date_from`. |
| `task_scope` | `all_tasks` (default) \| `my_tasks` | Hanya untuk `scope=tasklist`. `my_tasks` = tugas dengan user sebagai PIC aktif. |
| `pic_id` | ID akun | Tugas dengan akun itu sebagai PIC aktif. |
| `filter_mode`, `date_field`, `date`, `month`, `year`, `start_date`, `end_date` | lihat [Filter periode](#filter-periode-task-14) | Periode yang sama dengan `GET /api/tasks`. Tidak boleh digabung dengan `date_basis`, `date_from`, atau `date_to` (`422`). |

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
      "total_tasks": 8, "backlog": 1, "todo": 2, "on_process": 1, "ready_review": 1, "on_review": 1,
      "completed": 2, "unfinished": 6, "overdue": 3, "due_today": 1, "urgent": 2, "completion_rate": 25
    },
    "by_priority": { "low": { "total": 2, "unfinished": 1 }, "medium": { "total": 2, "unfinished": 2 }, "high": { "total": 1, "unfinished": 1 }, "urgent": { "total": 3, "unfinished": 2 } },
    "by_division": [
      { "code_division": "FIN", "name": "Finance", "total": 2, "completed": 1, "unfinished": 1, "overdue": 1, "completion_rate": 50 }
    ]
  }
}
```

- `scope.label` wajib ditampilkan di samping KPI. Kedua cakupan kini berlabel "Seluruh divisi".
- `period` = dasar tanggal yang dipakai (`basis`, `label`: "Tanggal dibuat" atau "Deadline"), batas periode, dan `active` (`true` bila `date_from`/`date_to` diisi).
- `as_of` = tanggal hari ini menurut zona waktu aplikasi (`APP_TIMEZONE`), acuan Overdue dan Due Today.
- Rumus metrik sama dengan bagian Ringkasan di atas. `completion_rate` dibulatkan 2 desimal dan bernilai `0` bila `total_tasks` = 0. Tugas aktif = status selain `done`.
- Tidak ada tugas → semua angka `0`, `by_division` = `[]`, dan `by_priority` tetap berisi keempat prioritas.
- `422` bila parameter tidak valid (pesan di `errors.<param>`), `401` bila belum login.
