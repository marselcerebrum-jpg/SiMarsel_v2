# Tasklist Database Foundation — Schema Alignment

Jira: STEAM-268 (TASK-19), subtask STEAM-269 — *DB: Existing Schema Alignment & Migration Consolidation*.
Acuan: ERD To-Be SimMarsel Tasklist v1.0 (https://dbdiagram.io/d/TASKLIST-6aebf555f1d298b5736edc37).
Baseline: migration dan model di `dev` per 13 Nov 2026 (TASK-01 sampai TASK-11).

Seluruh perubahan bersifat **aditif**: tidak ada tabel atau kolom existing yang di-drop atau di-rename. Satu-satunya
perubahan nilai data adalah penamaan status QA (lihat bagian 3).

## 1. As-Is vs To-Be

| Entitas | As-Is (sebelum) | To-Be (ERD) | Perubahan |
|---|---|---|---|
| `roles` | ADM, MGR, EMP; `name` varchar(25) | + SPV, FRL; `name` varchar(50) | Kolom dilebarkan; SPV dan FRL ditambah lewat `RoleSeeder`. EMP tetap, nama tampil menjadi **Staff** |
| `permissions` | Tidak ada; aturan akses di `Task`/`TaskPolicy` | Ada | Tabel baru + `PermissionSeeder` |
| `role_permissions` | Tidak ada | PK (role_id, permission_id) | Tabel baru |
| `divisions` | `name` varchar(25) unique | varchar(100) | Kolom dilebarkan; unique tetap |
| `accounts` | Tanpa indikator aktif | `is_active` | Kolom baru, default `true` untuk semua akun existing |
| `platforms` | Sesuai ERD | — | Tidak berubah |
| `task_categories` | Sesuai ERD | — | Tidak berubah |
| `tasks` | `pic_id`, tanpa soft delete | `deleted_at`, `deleted_by`, index tambahan | Soft delete + index `(status, deadline)`, `start_date`, `deleted_at`. `pic_id` **dipertahankan** |
| `task_assignments` | Tidak ada (1 PIC di `tasks.pic_id`) | Multi-PIC | Tabel baru + backfill dari `pic_id` |
| `task_divisions` | Tidak ada | Divisi kolaborator | Tabel baru |
| `task_evidences` | Tanpa metadata file / soft delete | `mime_type`, `file_size`, `deleted_at`, `deleted_by` | Kolom baru (nullable) |
| `task_reviews` | `cycle`, `decision` varchar(20), tanpa `updated_at` | `decision` varchar(30), `updated_at` | Tidak diubah (lihat pemetaan) |
| `task_comments` | Tidak ada | Komentar + balasan | Tabel baru |
| `task_activities` | `action` varchar(30), `actor_name_snapshot` | `action` varchar(50) | Kolom `action` dilebarkan |
| `calendar_events` | Agenda di `schedule_events` (+ `schedule_event_divisions`) | `calendar_events` | Tabel baru sesuai ERD (lihat bagian 5) |

## 2. Pemetaan nama field / nilai

| ERD / kontrak | Implementasi | Catatan |
|---|---|---|
| Role "Manajer" | `MGR`, nama `Manager` | Kode existing dipertahankan |
| Role "Staff" | `EMP`, nama `Staff` | Kode existing dipertahankan agar akun tidak berubah role |
| Status Back Log / To Do / On Process | `backlog` / `todo` / `in_progress` | Tidak berubah |
| Status Ready Review / On Review | `ready_review` / `on_review` | Sebelumnya `ready_for_qa` / `on_qa` |
| Status Done | `done` | Tidak berubah |
| `task_reviews.decision` approved / revision_requested | `approve` / `revision` | Nilai existing dipertahankan, sesuai enum `TaskReviewDecision` |
| `task_reviews.updated_at` | — | Review bersifat append-only (satu baris per keputusan), jadi tidak perlu `updated_at` |
| — | `task_reviews.cycle` | Field existing di luar ERD, dipertahankan |
| — | `task_activities.actor_name_snapshot` | Field existing di luar ERD, dipertahankan |
| — | `task_assignments.active_flag` | Kolom generated untuk unique PIC aktif (bagian 4) |
| Metrik monitoring `ready_for_qa` / `on_qa` | `ready_review` / `on_review` | Kunci response `TaskRepository::STATUS_METRICS` ikut diganti |

## 3. Urutan migration dan dependensi

| # | Migration | Bergantung pada | Jenis |
|---|---|---|---|
| 1 | `2026_11_13_083909_widen_name_columns_on_roles_and_divisions_tables` | roles, divisions | Ubah panjang kolom |
| 2 | `2026_11_13_083911_add_is_active_to_accounts_table` | accounts | Tambah kolom |
| 3 | `2026_11_13_083913_create_permissions_table` | — | Tabel baru |
| 4 | `2026_11_13_083915_create_role_permissions_table` | #3, roles | Tabel baru |
| 5 | `2026_11_13_083917_add_soft_delete_and_indexes_to_tasks_table` | tasks, accounts | Tambah kolom + index |
| 6 | `2026_11_13_083919_rename_qa_statuses_to_review_statuses` | tasks, task_activities | **Data**: ganti nilai status |
| 7 | `2026_11_13_083921_create_task_assignments_table` | tasks, accounts, task_activities | Tabel baru + **backfill** |
| 8 | `2026_11_13_083922_create_task_divisions_table` | tasks, divisions | Tabel baru |
| 9 | `2026_11_13_083924_add_file_metadata_and_soft_delete_to_task_evidences_table` | task_evidences | Tambah kolom |
| 10 | `2026_11_13_083926_create_task_comments_table` | tasks, accounts | Tabel baru |
| 11 | `2026_11_13_083928_widen_action_on_task_activities_table` | task_activities | Ubah panjang kolom |
| 12 | `2026_11_13_083929_create_calendar_events_table` | divisions, accounts | Tabel baru |

Prasyarat: semua migration sebelumnya sudah jalan, termasuk `2026_11_05_*`, `2026_11_06_*`, dan `2026_11_09_*`.

### Migrasi status (#6)

- `tasks.status`: `ready_for_qa` → `ready_review`, `on_qa` → `on_review`.
- `task_activities.changes`: nilai di bawah kunci `status` (`old`/`new`) diganti dengan pemetaan yang sama, supaya
  riwayat lama dan baru terbaca konsisten. Kunci lain (misalnya judul yang kebetulan berisi `on_qa`) tidak disentuh.
- Ini satu-satunya penulisan ulang `task_activities` yang disengaja. Aplikasi tetap memperlakukan riwayat sebagai
  append-only (model `TaskActivity` menolak update/delete); migration memakai query builder.
- `down()` mengembalikan kedua tabel ke nilai lama.

### Backfill PIC (#7)

- Setiap `tasks.pic_id` yang terisi menjadi satu baris `task_assignments` yang aktif.
- `assigned_by` dan `assigned_at` diambil dari riwayat `assigned`/`reassigned` terakhir pada task tersebut. Jika tidak
  ada riwayat, dipakai `creator_id` dan `created_at` task.
- Memakai `insertOrIgnore`, sehingga aman bila dijalankan ulang.
- `tasks.pic_id` **tidak diubah dan tidak dihapus**. Kolom ini tetap menjadi sumber data kode existing sampai service,
  API, dan FE dipindah ke `task_assignments` (subtask berikutnya). Setelah itu baru `pic_id` dihentikan dengan migration
  terpisah.

## 4. Constraint penting

- **PIC aktif unik per task**: `task_assignments.active_flag` adalah kolom generated
  (`CASE WHEN unassigned_at IS NULL THEN 1 END`) dengan unique index `(task_id, account_id, active_flag)`. Karena unique
  index mengizinkan banyak NULL, riwayat assignment yang sudah dilepas tetap tersimpan, tetapi akun yang sama tidak
  bisa menjadi PIC aktif dua kali (MySQL error 1062).
- **Foreign key saat akun dihapus**: akun di aplikasi dihapus permanen (`AccountRepository`). Karena itu
  `task_assignments.account_id` dan `task_comments.author_id` memakai `cascadeOnDelete` agar penghapusan akun tidak
  gagal. Kolom pelaku (`assigned_by`, `unassigned_by`, `deleted_by`, `added_by`) memakai `nullOnDelete`. Jejak pelaku
  tetap tersimpan di `task_activities.actor_name_snapshot`. `calendar_events.creator_id` tidak memakai cascade
  (NOT NULL sesuai ERD), jadi akun yang masih punya agenda tidak bisa dihapus — perlu diputuskan saat fitur Calendar
  dipindah.
- **Soft delete**: `tasks`, `task_evidences`, `task_comments`, dan `calendar_events`. Foreign key ke `tasks` tetap
  `cascadeOnDelete`, tetapi karena task hanya di-soft delete, data terkait tidak ikut terhapus.

## 5. Hal yang belum dikerjakan di subtask ini

- **Logika multi-PIC** di service, API, dan FE masih membaca `tasks.pic_id`.
- **Penerapan permission** di kode: tabel dan seeder sudah ada, tetapi `TaskPolicy`/`Task` masih memakai cek role.
- **Calendar**: `calendar_events` sudah dibuat, tetapi endpoint Calendar/Timeline masih membaca `schedule_events`.
  Pemindahan data `schedule_events` → `calendar_events` dilakukan bersama perpindahan kodenya. Perlu diputuskan:
  `schedule_events.creator_id` boleh NULL sedangkan `calendar_events.creator_id` NOT NULL, dan `calendar_events`
  tidak punya `is_shared`, `task_id`, maupun target divisi.
- **Catatan kerja PIC (TASK-13)**: masih PENDING di ERD, menunggu keputusan SA.
- **Divisi yang dikelola Supervisor**: sementara memakai `accounts.division_id`.

## 6. Migration dari branch lama yang belum di-merge

Branch berikut membuat ulang migration yang **sudah ada** di `dev` dengan nama file berbeda (`2026_11_01_00000x_*`):
`feat/task-evidence-storage-submission-validation`, `feat/task-monitoring-aggregation-calculation`,
`feat/task-review-revision-workflow`, `feat/task-timeline-shared-schedule`.

Isinya (task_evidences, evidence_required, task_reviews, `tasks.is_shared`) sudah ada di `dev` atau tidak dipakai lagi
(`is_shared` diganti visibilitas global). **Jangan merge migration dari branch tersebut**, karena tabelnya akan dibuat
dua kali dan migration gagal.

## 7. Prosedur deploy, validasi, dan rollback

### Sebelum migrate

```bash
mysqldump -u <user> -p --single-transaction --routines <database> > backup-before-tasklist-foundation.sql
php artisan migrate:status      # pastikan hanya 12 migration 2026_11_13_* (dan prasyaratnya) yang Pending
```

Catat jumlah baris sebagai pembanding:

```sql
SELECT COUNT(*) FROM tasks;
SELECT COUNT(*) FROM tasks WHERE pic_id IS NOT NULL;
SELECT status, COUNT(*) FROM tasks GROUP BY status;
SELECT COUNT(*) FROM task_activities;
SELECT COUNT(*) FROM accounts;
```

### Migrate dan seed

```bash
php artisan migrate --force
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=PermissionSeeder --force
```

### Validasi

```sql
-- jumlah assignment aktif = jumlah task yang punya PIC
SELECT COUNT(*) FROM task_assignments WHERE unassigned_at IS NULL;
-- tidak ada lagi status QA lama
SELECT COUNT(*) FROM tasks WHERE status IN ('ready_for_qa', 'on_qa');
-- jumlah task, aktivitas, dan akun sama dengan sebelum migrate
SELECT COUNT(*) FROM tasks;
SELECT COUNT(*) FROM task_activities;
SELECT COUNT(*) FROM accounts;
-- 5 role, Admin/Manager/Supervisor 7 permission, Staff/Freelance 3
SELECT r.code_role, COUNT(rp.permission_id) FROM roles r LEFT JOIN role_permissions rp ON rp.role_id = r.id GROUP BY r.code_role;
```

### Rollback

```bash
php artisan migrate:rollback --step=12 --force
```

Semua migration punya `down()`. Rollback mengembalikan nilai status lama (di `tasks` dan `task_activities`) dan
menghapus tabel/kolom baru. `tasks.pic_id` tidak pernah diubah, jadi data PIC tetap utuh. Data yang **baru** ditulis ke
tabel/kolom baru setelah migrate (assignment tambahan, komentar, agenda) ikut hilang saat rollback — gunakan backup
bila data tersebut perlu dipulihkan.

### Hasil uji (13 Nov 2026)

- SQLite (test suite): `tests/Feature/Database/TasklistFoundationTest.php` — backfill PIC, unique PIC aktif, riwayat
  assignment, migrasi status + rollback, soft delete, komentar/balasan, divisi kolaborator, seeder role dan permission.
- MySQL 8.4 (database percobaan terpisah dengan data bentuk lama): migrate → validasi → rollback dengan data → migrate
  ulang. Status, riwayat, dan backfill sesuai; duplikat PIC aktif ditolak (error 1062); rollback mengembalikan data
  persis seperti sebelum migrate.
