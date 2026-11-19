# Integrasi FE Tasklist setelah TASK-19

Panduan untuk tim FE: data yang dikirim backend setelah TASK-19 (STEAM-268), selisih dengan kode FE saat ini, dan fitur
mana yang sudah siap diintegrasikan. Kontrak lengkap tetap di [tasks.md](tasks.md); dokumen ini merangkum yang berubah.

Diverifikasi pada 16 Nov 2026 terhadap branch `feat/DB-tasklist-database-foundation` yang sudah disinkronkan dengan `dev`
(termasuk PR #111 ringkasan monitoring dan PR #116 Timeline) dan kode FE di `dev`
(`resources/js/pages/tasks/*`, `resources/js/services/tasks.js`, `resources/views/pages/tasks/components/*`).

## 0. Dependensi dan urutan penerapan

Branch backend ini **mengubah kontrak API** (status QA, `pic_id` → `pic_ids`, `pic` → `pics`, opsi PIC). Kode FE di `dev`
masih memakai kontrak lama, sehingga **backend ini tidak boleh dipakai pengguna sebelum FE disesuaikan**. Tanpa
penyesuaian FE: kolom Ready Review/On Review di Board kosong, ringkasan status salah, nama PIC tidak tampil, dan form Buat
Tugas/Atur PIC ditolak `422`.

| Langkah | Siapa | Catatan |
|---|---|---|
| 1. Review dan merge PR backend ke `dev` | BE + reviewer | Belum di-deploy ke pengguna |
| 2. Ticket FE: sesuaikan kontrak (bagian 2) | FE | Bisa dikerjakan paralel memakai branch backend ini |
| 3. Merge FE ke `dev`, uji end-to-end di `dev` | FE + BE | Board, Table, Calendar, Timeline, detail, buat tugas, atur PIC, status, review |
| 4. Deploy backend + FE **bersamaan** ke production | BE + FE | Ikuti prosedur deploy di [tasklist-schema-alignment.md](../database/tasklist-schema-alignment.md#8-prosedur-deploy-validasi-dan-rollback) |

Ticket FE yang dibutuhkan (nama sesuai pembagian tim): penyesuaian status Review, Multi-PIC (`pic_ids`/`pics`), opsi PIC
lintas divisi, dan tampilan read-only untuk tugas di luar kewenangan. Calendar dan Timeline tidak perlu diubah.

## 1. Data yang dibutuhkan FE

| Kebutuhan | Sumber | Bentuk |
|---|---|---|
| Daftar PIC aktif | `Task.pics` (daftar, Board, Table, Calendar `deadlines`, detail) | `[{ id, fullname }]`, urut nama; `[]` bila belum ada PIC |
| Riwayat PIC | `TaskDetail.assignments` | `[{ id, account, assigned_by, assigned_at, unassigned_by, unassigned_at, is_active, note }]`, terlama dulu |
| Pilihan PIC (buat & atur) | `create-options.pics`, `assignment-options.pics` | `[{ id, fullname, role, division: { id, code_division, name } \| null }]` dari semua divisi |
| Status | `Task.status`, `meta.statuses` | `backlog`, `todo`, `in_progress`, `ready_review`, `on_review`, `done` |
| Hak akses umum | `meta.role`, `meta.role_code`, `meta.permissions`, `meta.can_create` | `role` tetap `admin`/`manager`/`staff`; `role_code` = `ADM`/`MGR`/`SPV`/`EMP`/`FRL`; `permissions` = kode `task.*` |
| Hak akses per tugas | `can_view_detail`, `can_edit`, `can_assign`, `can_delete`, `can_review`, `editable_fields`, `next_statuses`; `can_delete` per Evidence | Dihitung backend; FE tidak perlu menghitung ulang |
| Versi (optimistic locking) | `Task.version` | Kirim di setiap `PATCH`/`POST` perubahan; `409 { current_version }` bila usang |
| Cakupan daftar | `GET /api/tasks?scope=` | `all_tasks` (default, semua divisi) atau `my_tasks` (PIC aktif) |
| Filter periode dan PIC | `GET /api/tasks`, `GET /api/tasks/monitoring?scope=tasklist` | `filter_mode`, `date_field`, `date`, `month`, `year`, `start_date`, `end_date`, `pic_id`; monitoring memakai `task_scope` untuk cakupan daftar |

## 2. Selisih FE saat ini dengan backend

### Status

| File | Sekarang | Ganti menjadi |
|---|---|---|
| `resources/js/pages/tasks/workflow.js` (STATUSES) | `ready_for_qa` "Ready for QA", `on_qa` "On QA" | `ready_review` "Ready Review", `on_review` "On Review" |
| `workflow.js` (`LOCKED_ASSIGNMENT`, transisi, `summary`) | `ready_for_qa`, `on_qa` | `ready_review`, `on_review` |
| `index.js` `STATUS_METRICS` (ringkasan dari `GET /api/tasks/monitoring`, PR #111) | status dan metrik `ready_for_qa`, `on_qa` | `ready_review: 'ready_review'`, `on_review: 'on_review'` (nama metrik `metrics.ready_review`, `metrics.on_review`) |

### PIC

| File | Sekarang | Ganti menjadi |
|---|---|---|
| `workflow.js` `isPic()` | `task.pic?.id === user.id` | `task.pics.some((pic) => pic.id === user.id)` |
| `workflow.js` cek Back Log → To Do | `!task.pic` | `task.pics.length === 0` |
| `index.js` sort/filter PIC | `task.pic?.fullname` | `task.pics.map((pic) => pic.fullname).join(', ')`; atau kirim `sort=pic` ke server (memakai PIC pertama menurut abjad) |
| `index.js` + `task-create-modal.blade.php` | `form.pic_id` (satu `<select>`), PIC diambil dari `divisions[].pics` | `form.pic_ids` (pilih banyak), opsi dari `create-options.pics` (semua divisi; boleh dikelompokkan dengan `division`) |
| `index.js` validasi To Do | `!pic_id` | `pic_ids.length === 0`; error backend di `errors.pic_ids` |
| `index.js` + `task-detail.blade.php` atur PIC | `assignForm.pic_id`, `current_pic`, `divisions[].pics`, tampil `task.pic` | `pic_ids` = **daftar PIC lengkap** setelah perubahan (PIC yang tidak dikirim dilepas), opsional `note`; tampilkan `current_pics`/`task.pics`; opsi dari `assignment-options.pics`; `division_id` untuk meneruskan (Admin, Manajer, Supervisor divisinya; ikuti `can_change_division`) |
| `index.js` toast setelah atur PIC | `data.pic?.fullname` | `data.pics` |
| `index.js` pesan error | `errors.pic_id` | `errors.pic_ids` atau `errors['pic_ids.N']` (N = urutan PIC yang ditolak) |
| `task-board.blade.php`, `task-table.blade.php`, `task-calendar.blade.php`, `task-detail.blade.php` | `task.pic`, `task.pic.fullname` | `task.pics` (bisa lebih dari satu) |
| `services/tasks.js` (komentar kontrak) | `pic_id` | `pic_ids`, `pics` |

### Akses (revisi PR)

| Hal | Sekarang di backend | Dampak FE |
|---|---|---|
| Daftar `all_tasks` | Semua tugas seluruh divisi untuk semua akun aktif | Staff/Freelance/Supervisor kini melihat tugas divisi lain; tampilkan read-only mengikuti `can_edit`, `can_assign`, `can_review`, `editable_fields`, `next_statuses` |
| Filter divisi di `meta.divisions` | Semua divisi untuk semua role | Tidak perlu perubahan bila dropdown sudah membaca `meta.divisions` |
| Tombol Buat Tugas | `meta.can_create` `true` untuk Admin, Manajer, Supervisor | Pakai `can_create`, bukan `role` |
| Detail tugas orang lain | `200`, bukan lagi `403` | Pesan "Akses ditolak" untuk detail tidak muncul lagi; aksi tetap disembunyikan lewat flag |
| Aksi tanpa hak | `403` | Tetap tangani `403` sebagai "Anda tidak memiliki akses" |
| Label monitoring `scope=tasklist` | Selalu "Seluruh divisi" | Tidak perlu perubahan bila label dibaca dari `scope.label` |
| Login akun nonaktif | `422` "Akun Anda nonaktif. Hubungi Admin." di `errors.username` | Sudah tertangani form login yang menampilkan error `username` |

### Lainnya

| Hal | Catatan |
|---|---|
| `PATCH /api/tasks/{id}` | Tidak lagi mengubah PIC; `pic_id`/`pic_ids` diabaikan. Gunakan endpoint assignment. |
| Histori | Perubahan PIC baru memakai `changes.pics` (daftar) dan action `assigned`/`unassigned`/`reassigned`; histori lama tetap `changes.pic`. Pakai `description` dari backend. |
| Hapus akun | `DELETE /api/accounts/{id}` bisa `422` bila akun punya riwayat tugas; tampilkan `message`. |
| Calendar & Timeline | Agenda kini dari `calendar_events`; id agenda dan aturan akses **sama dengan sebelumnya**: agenda bersama untuk semua, agenda internal untuk Admin dan semua divisi tujuannya (Manajer hanya divisinya). Field baru `is_shared` (tambahan, tidak wajib dipakai). `target_divisions` tetap berisi semua divisi tujuan. Agenda tidak lagi terhubung ke tugas: `task_id` `null` dan `can_view_detail` `false`, sehingga tombol "Lihat Detail Tugas Terkait" di pop-up agenda tidak muncul (tanpa perubahan kode FE). |

## 3. Kesiapan per fitur

| Fitur | Backend | Catatan untuk FE |
|---|---|---|
| Daftar `all_tasks` / `my_tasks`, Board, Table, filter, pagination | ✅ Siap | Akses baca global sudah berlaku |
| Task Detail (pics, assignments, history, evidence, reviews, version) | ✅ Siap | |
| Buat tugas dengan banyak PIC | ✅ Siap | Supervisor untuk divisinya |
| Atur PIC dan meneruskan (lintas divisi) | ✅ Siap | |
| Workflow status bersama oleh semua PIC | ✅ Siap | |
| Evidence (kirim, unduh) | ✅ Siap | Hapus evidence (TASK-18): tabel siap, endpoint belum ada |
| Review Approve / Request Revision, self-approval Manajer/Supervisor | ✅ Siap | Ikuti `can_review` |
| Monitoring | ✅ Siap | Kunci metrik status berganti nama |
| Calendar & Timeline | ✅ Siap | Tidak perlu perubahan FE; tombol tugas terkait di pop-up agenda tidak muncul lagi |
| Komentar & balasan | ⏳ Tabel siap | Endpoint dibuat di TASK-16 |
| Hapus Task Card | ✅ Siap (TASK-12) | `DELETE /api/tasks/{id}` dengan `version`; tampilkan tombol bila `can_delete`; tugas Done tidak bisa dihapus (`422`) |
| Filter tanggal, bulan, tahun, rentang | ✅ Siap (TASK-14) | Kirim parameter yang sama ke daftar dan monitoring agar Board, Table, dan KPI konsisten; kirim hanya parameter mode aktif |
| Pop-up Calendar | ✅ Siap (TASK-12) | `GET /api/tasks/{id}/preview`: ringkasan terbaru dan flag akses untuk satu tugas |
| Hapus evidence sendiri | ✅ Siap (TASK-13) | `DELETE /api/tasks/{id}/evidences/{evidence}`; tombol bila `can_delete` pada Evidence |
| PIC & divisi untuk Supervisor | ✅ Siap (TASK-13) | `pics` di create/assignment-options sudah dibatasi ke divisi Supervisor; `can_change_division` `false` dan `division_id` tidak ada di `editable_fields` untuk Supervisor |
| Kelola agenda (buat/ubah/hapus) | ⏳ Tabel siap | Endpoint belum ada |
| Nonaktifkan akun | ⏳ Kolom `is_active` dan penolakan login siap | Endpoint belum ada |
