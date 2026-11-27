# Create Konten Database Design, ERD Alignment & Traceability Package

Story: `SAV-48` (Create Konten Database Design & ERD Alignment).
Subtask tercakup: `SAV-53` (Desain ERD To-Be), `SAV-54` (Review & Migration Readiness), `SAV-49` (Dokumentasi & Traceability Package).
Keterkaitan lintas tiket: `SAV-10` (`SAV-35`, `SAV-36`), `SAV-13` (`SAV-42`), `SAV-3` (`SAV-15` PR #151, `SAV-16`).

Dokumen ini menjadi sumber kebenaran desain database Create Konten pada SiMarsel V2 (MySQL InnoDB `utf8mb4`). Diagram DBML terintegrasi disimpan di [simarsel-erd.dbml](simarsel-erd.dbml).

## 1. Keputusan Arsitektur: Greenfield di SiMarsel V2 (SAV-35 / SAV-48) - Status: Disetujui

> **Status persetujuan:** keputusan membangun ulang modul Create Konten dari awal (greenfield) **sudah disetujui** (reviewer: Adib Najwan). Tindak lanjut: lanjut ke implementasi migration `SAV-10`/`SAV-35` dan `SAV-13`/`SAV-42` sesuai urutan di bagian 3.

Modul Create Konten dibangun dari awal (greenfield) dengan pusat data langsung di database MySQL SiMarsel V2 (`simarsel`). Dokumen ini memuat desain To-Be final tanpa backfill data eksternal:

1. **Langsung memakai tabel `accounts` SiMarsel:** seluruh referensi aktor (`marketing_user_id`, `editor_account_id`, `published_by`, `events.user_id`, `kpis.user_id`, `user_apps.user_id`, `footage.uploaded_by`) langsung mengacu pada `accounts.id` (`bigint unsigned`) dengan `ON DELETE RESTRICT`.
2. **Tanpa tabel mapping akun terpisah:** tidak diperlukan karena modul dibangun dari awal tanpa backfill data akun eksternal.
3. **Tanpa duplikasi autentikasi atau sesi:** login, session, role, dan divisi memakai tabel `accounts`, `roles`, `divisions`, dan `sessions` eksisting SiMarsel V2.
4. **Master Apps tetap di `options` (`key = 'app'`):** terpisah dari `platforms` SiMarsel agar tidak mencampur platform transaksi Markaz dengan daftar Apps produksi konten.
5. **Timezone diseragamkan ke `Asia/Jakarta` (`APP_TIMEZONE`):** semua kolom tanggal (`date`) memakai format `YYYY-MM-DD` dan kolom waktu (`datetime`/`timestamp`) memakai standar waktu SiMarsel.

## 2. Desain ERD To-Be & Resolusi Gap PR #151 (SAV-53)

### 2.1 Penetapan Keputusan Kunci

| Gap / Konflik | Keputusan Final To-Be | Alasan |
|---|---|---|
| `GAP-01`: 5 tabel inti belum ada di SiMarsel | Tambah 8 tabel Create Konten via Laravel migration (`options`, `contents`, `events`, `calendar_plans`, `kpis`, `user_apps`, `footage`, `drive_folders`) | Membuka blokir Dashboard (`SAV-15`/`SAV-16`) dan Create Konten (`SAV-10..14`) sekaligus. |
| `GAP-02`: Penamaan kolom Editor di `contents` | **Ditetapkan `contents.editor_account_id`** (`bigint unsigned null`, FK `accounts.id` `RESTRICT`) | Konsisten dengan ERD To-Be final, memberi status `PASS` pada validator `SAV-15` (PR #151), dan menunjuk langsung ke `accounts.id`. Catatan implementasi `SAV-12`/`SAV-39` memakai `editor_account_id`. |
| `GAP-03`: Strategi integrasi akun `SAV-35` | **Greenfield langsung ke `accounts.id` (disetujui)**; semua kolom akun memakai FK `accounts.id` (`bigint unsigned`) | Modul dibangun dari awal, jadi tabel mapping akun terpisah tidak dibuat. |
| `GAP-04`: Master divisi `Marketing` / `Creative` dan `accounts.division_id` nullable | Tidak menambah seeder `divisions` ke `DatabaseSeeder` (menjaga kompatibilitas `DivisionCrudTest` yang mengasumsikan `divisions` kosong saat test); `accounts.division_id` tetap nullable | UI dan API menangani akun `division_id = NULL` sebagai unassigned; divisi `Marketing` dan `Creative` dikelola melalui master divisi. |
| `GAP-05`: Kolom operasional & `ON DELETE CASCADE` berisiko | Seluruh kolom operasional lengkap di migration; semua FK akun & `events.content_id` memakai `ON DELETE RESTRICT` (kecuali `footage.content_id` `SET NULL`) | Mencegah penghapusan permanen konten atau akun menghapus History (`events`) dan target (`kpis`). |
| `GAP-06`: Timestamp ISO UTC vs `Asia/Jakarta` | Kolom `*_at` dan `events.at` memakai tipe `datetime`, tanggal memakai `date` (`YYYY-MM-DD`) dengan `APP_TIMEZONE=Asia/Jakarta` | Menghindari pergeseran tanggal jam 00:00 sampai 06:59 WIB pada laporan Dashboard dan KPI. |
| `GAP-07`: Kolom `events.reason` belum ada | Ditambahkan lewat migration `SAV-42` sebagai `text` nullable beserta index `(content_id, field)` | Mendukung pengisian deadline pertama (`reason = NULL`) dan perubahan deadline wajib alasan (`SAV-13`). |
| Inkompatibilitas FK MySQL (`ERROR 3780`) | Semua FK ke `accounts.id` memakai `foreignId()` (`bigint unsigned`), bukan `bigint` signed | Terbukti di MySQL 8.0: `bigint` signed ditolak dengan `ERROR 3780`. |
| Pengisian deadline pertama (`SAV-13` A.2-A.3) | `contents.upload_date` dibuat **`date` nullable** | Memungkinkan konten dibuat tanpa deadline lalu deadline diisi pertama kali dengan `from_value = NULL` dan `reason = NULL`. |

### 2.2 Data Dictionary Ringkas 8 Tabel To-Be

#### `options`
- `id` `varchar(64)` PK (format `app:1`, `script:1`, `talent:1`, `talentName:1`, `creative:1`, `qc:1`, `priority:1`)
- `key` `varchar(20)` NOT NULL (`app`, `talentName`, `script`, `talent`, `creative`, `qc`, `priority`)
- `label` `varchar(255)` NOT NULL
- `semantic` `varchar(50)` NOT NULL default `''`
- `sort` `int` NOT NULL default `0`
- `archived` `boolean` NOT NULL default `false`
- `category` `varchar(255)` NULL
- `created_at`, `updated_at` `timestamp` NULL
- Index: `(key, archived, sort)` (`options_key_archived_sort_index`)

#### `contents`
- `id` `bigint unsigned` PK auto_increment
- `title` `text` NOT NULL
- `app` `varchar(64)` NOT NULL, FK -> `options(id)` `RESTRICT`
- `type` `varchar(20)` NOT NULL (`Video`, `Carousel`, `Singlepost`)
- `type_no` `int unsigned` NULL
- `created_date` `date` NOT NULL
- `upload_date` `date` NULL (deadline konten)
- `script_status` `varchar(64)` NOT NULL, FK -> `options(id)` `RESTRICT`
- `talent_status` `varchar(64)` NULL, FK -> `options(id)` `RESTRICT`
- `talent_name` `varchar(64)` NULL, FK -> `options(id)` `RESTRICT` (model/on-camera, bukan akun)
- `creative_status` `varchar(64)` NOT NULL, FK -> `options(id)` `RESTRICT` (UI label: Status Produksi)
- `qc_status` `varchar(64)` NOT NULL, FK -> `options(id)` `RESTRICT`
- `priority` `varchar(64)` NOT NULL default `'priority:1'` (relasi logis ke `options(id)` `key='priority'`, tanpa FK)
- `link` `text` NULL (satu Link Hasil per konten)
- `notes` `text` NULL
- `sheet` `longtext` NULL (JSON worksheet per tipe konten)
- `marketing_user_id` `bigint unsigned` NULL, FK -> `accounts(id)` `RESTRICT`
- `editor_account_id` `bigint unsigned` NULL, FK -> `accounts(id)` `RESTRICT`
- `script_ready_at`, `talent_done_at`, `creative_done_at`, `link_at`, `qc_at` `datetime` NULL
- `published_url` `text` NULL, `published_date` `date` NULL, `published_by` `bigint unsigned` NULL FK -> `accounts(id)` `RESTRICT`
- `archived` `boolean` NOT NULL default `false`
- `revision` `int unsigned` NOT NULL default `1`
- `created_at`, `updated_at` `timestamp` NULL
- Unique: `(type, type_no)` (`contents_type_no_unique`)
- Index: `upload_date`, `created_date`, `(app, type, archived)`, `(editor_account_id, archived)`, `(creative_status, archived)`

#### `events`
- `id` `bigint unsigned` PK auto_increment
- `content_id` `bigint unsigned` NOT NULL, FK -> `contents(id)` **`RESTRICT`**
- `user_id` `bigint unsigned` NULL, FK -> `accounts(id)` **`RESTRICT`**
- `field` `varchar(255)` NOT NULL
- `from_value` `longtext` NULL
- `to_value` `longtext` NULL
- `reason` `text` NULL (ditambahkan pada `SAV-42`; `NULL` untuk event selain perubahan deadline existing)
- `at` `datetime` NOT NULL
- Index: `(content_id, at)` (`events_content_at_index`), `(content_id, field)` (`events_content_field_index`)

#### `calendar_plans`
- `app` `varchar(64)` NOT NULL (relasi logis ke `options(id)` `key='app'`, tanpa FK)
- `type` `varchar(20)` NOT NULL
- `date` `date` NOT NULL
- `amount` `int unsigned` NULL
- `color` `varchar(30)` NULL
- `created_at`, `updated_at` `timestamp` NULL
- PK: `(app, type, date)`
- Index: `date` (`calendar_plans_date_index`)

#### `kpis`
- `id` `bigint unsigned` PK auto_increment
- `user_id` `bigint unsigned` NOT NULL, FK -> `accounts(id)` **`RESTRICT`**
- `app` `varchar(64)` NOT NULL (relasi logis ke `options(id)` `key='app'`, tanpa FK)
- `type` `varchar(20)` NOT NULL
- `work_date` `date` NOT NULL
- `upload_date` `date` NOT NULL
- `amount` `int unsigned` NOT NULL
- `created_at`, `updated_at` `timestamp` NULL
- Unique: `(user_id, app, type, work_date)` (`kpis_user_app_type_work_date_unique`)
- Index: `(app, type, work_date)`, `upload_date`
- Aturan agregasi Dashboard (`SAV-15`): `kpis` dan `calendar_plans` memiliki grain berbeda; jangan langsung di-join untuk menjumlahkan target agar tidak terjadi double-counting.

#### `user_apps`
- `user_id` `bigint unsigned` NOT NULL, FK -> `accounts(id)` `RESTRICT`
- `app` `varchar(64)` NOT NULL, FK -> `options(id)` `RESTRICT`
- PK: `(user_id, app)`; Index: `app`

#### `footage`
- `id` `bigint unsigned` PK auto_increment
- `content_id` `bigint unsigned` NULL, FK -> `contents(id)` `SET NULL`
- `app` `varchar(64)` NOT NULL, FK -> `options(id)` `RESTRICT`
- `type` `varchar(20)` NOT NULL
- `kind` `varchar(10)` NOT NULL (`file` | `link`)
- `title` `text` NOT NULL
- `url` `text` NULL, `file_path` `varchar(500)` NULL, `mime` `varchar(100)` NULL, `size` `bigint unsigned` NULL
- `uploaded_by` `bigint unsigned` NULL, FK -> `accounts(id)` `RESTRICT`
- `created_at`, `updated_at` `timestamp` NULL
- Index: `(app, type)`, `content_id`

#### `drive_folders`
- `app` `varchar(64)` NOT NULL, FK -> `options(id)` `RESTRICT`
- `type` `varchar(20)` NOT NULL
- `url` `text` NOT NULL
- `created_at`, `updated_at` `timestamp` NULL
- PK: `(app, type)`

## 3. Checklist Review Teknis & Migration Readiness (SAV-54)

- [x] Seluruh PK dan FK akun memakai `bigint unsigned` selaras dengan `accounts.id` (`$table->id()`).
- [x] Tidak ada `ON DELETE CASCADE` pada `events.content_id`, `events.user_id`, `kpis.user_id`, maupun kolom akun di `contents`.
- [x] Grain unik `kpis(user_id, app, type, work_date)`, `calendar_plans(app, type, date)`, dan `contents(type, type_no)` terjaga.
- [x] Master Apps terisolasi pada `options(key = 'app')`, tidak tercampur dengan `platforms.id`.
- [x] Tidak mengubah tabel eksisting SiMarsel (`accounts`, `roles`, `divisions`, `tasks`, `transactions`, `promo_codes`, `calendar_events`).
- [x] Urutan migration aman terhadap dependensi FK:
  1. `options`
  2. `contents` (bergantung pada `options`, `accounts`)
  3. `events` (bergantung pada `contents`, `accounts`)
  4. `calendar_plans`
  5. `kpis` (bergantung pada `accounts`)
  6. `user_apps` (bergantung pada `accounts`, `options`)
  7. `footage` (bergantung pada `contents`, `options`, `accounts`)
  8. `drive_folders` (bergantung pada `options`)
  9. `events.reason` nullable + `events_content_field_index` (`SAV-42`)
- [x] Master default `options` dirancang disediakan lewat `CreativeDeskOptionSeeder` (idempoten, aman dijalankan berulang) pada implementasi `SAV-10`/`SAV-35`.
- [x] Strategi rollback: urutan `down()` membalik ketergantungan FK (`events.reason` -> `drive_folders` -> `footage` -> `user_apps` -> `kpis` -> `calendar_plans` -> `events` -> `contents` -> `options`), telah diverifikasi pada simulasi draft migration di SQLite dan MySQL 8.0 (implementasi migration dijalankan pada `SAV-10`/`SAV-35` dan `SAV-13`/`SAV-42`).

## 4. Matriks Traceability Requirement (SAV-49)

| Requirement / Ticket | Elemen Database Terkait | Status Verifikasi |
|---|---|---|
| `SAV-10` / `SAV-35` (Integrasi akun & master Apps) | Seluruh FK akun -> `accounts.id` (`bigint unsigned`, `RESTRICT`); master Apps -> `options(key='app')` | Terverifikasi desain; pendekatan greenfield disetujui, siap implementasi migration |
| `SAV-11` / `SAV-51` (Worksheet & 3 tipe konten) | `contents` (`type`, `type_no`, `sheet`, `options` FK), `CreativeDeskOptionSeeder` | Terverifikasi desain |
| `SAV-12` / `SAV-39` (Editor, divisi, Talent, 1 Link Hasil, Status Produksi) | `contents.editor_account_id` -> `accounts.id` (+ `divisions`), `contents.talent_name` -> `options(key='talentName')`, `contents.link`, `contents.creative_status` | Terverifikasi desain |
| `SAV-13` / `SAV-42` / `SAV-43` (Deadline reason & History integrity) | `contents.upload_date` nullable, `events.reason` nullable, `events.content_id` `ON DELETE RESTRICT`, `events.user_id` `RESTRICT`, `contents.archived` | Terverifikasi desain |
| `SAV-14` / `SAV-45` (Filter konsisten Dashboard-Worksheet) | Index `contents(app, type, archived)`, `(editor_account_id, archived)`, `(creative_status, archived)`, `upload_date`, `created_date` | Terverifikasi desain |
| `SAV-3` / `SAV-15` PR #151 (`GAP-01`..`GAP-07`) | Seluruh 5 tabel Dashboard (`options`, `contents`, `kpis`, `calendar_plans`, `events`), `editor_account_id`, `events.reason`, `RESTRICT` FK, `Asia/Jakarta` datetime | Selaras 100% dengan `CreativeDeskDashboardDataValidator` |
