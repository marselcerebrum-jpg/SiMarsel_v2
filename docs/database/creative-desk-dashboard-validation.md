# Validasi Sumber Data & Schema Dashboard CreativeDesk (SAV-15)

Tanggal audit: 24 November 2026 | Parent: `SAV-3` | Subtask: `SAV-15` | Sifat: **Read-Only** (tanpa migration, backfill, perubahan constraint, atau pembuatan tabel baru).

## 1. Sumber Acuan & Perintah Validasi

- Runtime & migration SiMarsel V2: [simarsel-erd.dbml](simarsel-erd.dbml), `database/migrations/` (50 file), dan `database/database.sqlite` (33 tabel).
- Schema & runtime CreativeDesk As-Is: `CreaBoard/server/db.js`, `CreaBoard/server/rules.js`, dan `CreaBoard/data/pglite` (12 tabel).
- Rancangan ERD To-Be: `CreativeDesk To-Be.sql` (export ERD `CreativeDesk-To-Be`).

Validator read-only untuk dijalankan ulang di lokal, staging, atau production:

```bash
php artisan creative-desk:validate-dashboard-data
```

## 2. Daftar Sumber Data & Hasil Pemeriksaan Relasi

| Tabel | Status Runtime SiMarsel | Struktur As-Is (`CreaBoard`) | Rancangan To-Be (`CreativeDesk To-Be.sql`) | Catatan & Dampak Dashboard |
|---|---|---|---|---|
| `accounts` | Tersedia (5 baris) | `users` (`id int`) | `accounts` (`id bigint`, `division_id bigint`, `fullname`) | Master akun SiMarsel (`id bigint unsigned`, `is_active`, `division_id` nullable). Wajib pakai mapping `SAV-10`, jangan samakan ID angka. |
| `divisions` | Tersedia (1 baris: `MKT`) | Tidak ada (hanya `users.role`) | `divisions` (`id bigint`, `name`) | Divisi `Creative` belum tersedia di runtime lokal (hanya `Marketing`). |
| `options` | Belum ada (`CreaBoard`: 25 baris) | `id varchar(64) PK`, `key`, `label`, `semantic`, `sort`, `archived`, `category` | Sama | Sumber master Apps (`key='app'`). Terbukti berbeda dari `platforms` SiMarsel (`app:1=JadiASN` vs `platforms.id=1=CEREBRUM`). |
| `contents` | Belum ada (`CreaBoard`: 11 baris) | `id int`, `app`, `type`, `created_date`, `upload_date`, `script_status`, `creative_status`, `qc_status`, `priority`, `creative_user_id`, `marketing_user_id`, `published_*`, `archived`, `*_at` | `id bigint`, FK ke `options(id)`, `priority` logis tanpa FK, usulan `editor_account_id bigint` FK ke `accounts(id)` | `creative_status` berlabel UI "Status Produksi" tanpa mengubah kolom DB. Penamaan `editor_account_id` vs `creative_user_id` perlu ditetapkan di `SAV-48`/`SAV-10`. |
| `kpis` | Belum ada (`CreaBoard`: 6 baris) | `UNIQUE(user_id, app, type, work_date)`, `upload_date`, `amount > 0`, `user_id` FK `ON DELETE CASCADE` | `app varchar(64)` (logis tanpa FK) | Grain target per staff. Risiko hilang histori jika `ON DELETE CASCADE` terbawa ke SiMarsel. |
| `calendar_plans` | Belum ada (`CreaBoard`: 5 baris) | `PK(app, type, date)`, `amount`, `color` | `PK(app, type, date)`, `app` logis tanpa FK | Grain target agregat kalender. Relasi ke `kpis` hanya logis lewat `(app, type, work_date=date)`. |
| `events` | Belum ada (`CreaBoard`: 43 baris) | `content_id` FK `ON DELETE CASCADE`, `user_id` nullable, `field`, `from_value`, `to_value`, `at` (ISO UTC) | `id bigint`, `content_id bigint`, `user_id bigint`, `at datetime`, usulan `reason text` nullable | `events.reason` belum ada di As-Is; format `at` UTC perlu dinormalisasi ke `Asia/Jakarta`. |

## 3. Bukti Query Validasi Read-Only

### A. Runtime SiMarsel V2 (`php artisan creative-desk:validate-dashboard-data`)

- `Tabel inti SiMarsel (accounts & divisions)`: **PASS** (`accounts=5`, `divisions=1`)
- `Tabel sumber CreativeDesk`: **FAIL** `[SAV-48/SAV-10]` (belum ada: `options`, `contents`, `kpis`, `calendar_plans`, `events`)
- `Master divisi Marketing & Creative`: **WARN** `[SAV-10]` (belum ada divisi `Creative`)
- `Akun dengan division_id NULL`: **WARN** (2 akun: `manager`, `admin`)
- 10 pemeriksaan tabel CreativeDesk lainnya: **SKIP** (otomatis aktif saat tabel tersedia)

### B. Runtime CreativeDesk As-Is (`CreaBoard/data/pglite`)

Query pembuktian risiko double-counting antara `calendar_plans` dan `kpis`:

```sql
SELECT cp.app, cp.type, cp.date, cp.amount AS plan_amount,
       COUNT(k.id) AS kpi_rows, SUM(k.amount) AS kpi_sum,
       (cp.amount * COUNT(k.id)) AS naive_join_plan_sum
FROM calendar_plans cp
JOIN kpis k ON k.app = cp.app AND k.type = cp.type AND k.work_date = cp.date
GROUP BY cp.app, cp.type, cp.date, cp.amount
ORDER BY cp.date, cp.app;
```

Hasil aktual: pada `(app:1, Video, 2026-11-24)`, 1 baris `calendar_plans` (`plan_amount=2`) berpasangan dengan 2 baris `kpis` (`kpi_sum=5`), sehingga direct join menggandakan target kalender menjadi `4`. Agregasi `calendar_plans` dan `kpis` wajib dipisah.

Pemeriksaan integritas `CreaBoard`: 0 orphan pada `contents -> options`, 0 orphan pada `events -> contents` (43 baris), 0 `archived` dari 11 konten.

## 4. Daftar Gap & Rekomendasi Tindak Lanjut

| ID | Temuan Gap | Dampak Dashboard | Pemilik | Tindak Lanjut |
|---|---|---|---|---|
| `GAP-01` | 5 tabel CreativeDesk belum tersedia di migration & runtime DB SiMarsel V2 | Dashboard belum dapat membaca data aktual SiMarsel | `SAV-48` & `SAV-10` | Selesaikan review ERD (`SAV-53`/`SAV-54`) & migration alignment (`SAV-10`/`SAV-36`), lalu jalankan ulang validator. |
| `GAP-02` | Usulan `contents.editor_account_id` (To-Be) vs `contents.creative_user_id` (As-Is) | Query filter & agregasi Editor harus memakai kolom final | `SAV-48` & `SAV-10` | Kunci satu nama kolom kanonis di ERD To-Be final. |
| `GAP-03` | Mapping `users.id` legacy ke `accounts.id` (`SAV-10`/`SAV-35`) belum tersedia | Risiko orphan referensi user; data lokal hanya dummy (0% match) | `SAV-10` / `SAV-35` | Selesaikan validasi mapping pada data staging/produksi sebelum migrasi. |
| `GAP-04` | Divisi `Creative` belum tersedia di runtime lokal; 2 akun `division_id = NULL` | Filter divisi & eligibility Editor belum lengkap di lokal | `SAV-10` / `SAV-12` | Tambahkan master divisi Creative; tangani `division_id = NULL` sebagai unassigned. |
| `GAP-05` | `CreativeDesk To-Be.sql` belum mencantumkan seluruh kolom operasional (`created_date`, `upload_date`, `*_at`, `published_*`, `archived`, kolom `kpis`) dan aturan `ON DELETE` | Risiko kehilangan histori bila `ON DELETE CASCADE` As-Is terbawa | `SAV-48` / `SAV-53` / `SAV-54` | Lengkapi ERD To-Be final beserta index & kebijakan `ON DELETE RESTRICT/SET NULL`. |
| `GAP-06` | Timestamp As-Is memakai ISO UTC (`...Z`), sedangkan SiMarsel memakai `Asia/Jakarta` | Risiko pergeseran tanggal jam 00:00 sampai 06:59 WIB | `SAV-10` / `SAV-48` | Normalisasikan timestamp ke `Asia/Jakarta` saat migrasi. |
| `GAP-07` | Kolom `events.reason` (nullable) belum ada di schema As-Is | Diperlukan untuk alasan perubahan deadline (`SAV-13`/`SAV-42`) | `SAV-48` / `SAV-42` | Sertakan `events.reason` nullable pada migration alignment. |
