# Database

Database: MySQL, nama `simarsel`. Struktur dibuat dari migration di `database/migrations`.

## Setup

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

## Tabel

| Tabel | Isi |
|---|---|
| `roles` | Role akun (`code_role` unik) |
| `divisions` | Master divisi (`code_division` dan `name` unik) |
| `accounts` | Akun yang bisa login (`username` unik) |
| `sessions` | Session login (token) per akun |
| `platforms` | Platform sumber data (`slug` unik) |
| `transactions` | Transaksi pembelian per platform (`platform_id` + `markaz_transaction_id` unik) |
| `downloaders` | Jumlah downloader per platform per tanggal |
| `sync_logs` | Riwayat sinkronisasi data per platform |
| `dashboard_snapshots` | Snapshot nilai Dashboard pada jam potong per platform, dataset, dan periode, beserta penanda kelengkapan |
| `markaz_api_tokens` | Token API Markaz (hanya 1 token aktif) |
| `funnels` | Master funnel kode promo (`name` unik) |
| `promo_sources` | Master sumber kode promo (`name` unik) |
| `promo_codes` | Master kode promo per platform (`platform_id` + `code` unik) |
| `promo_code_versions` | Riwayat funnel/source kode promo beserta rentang waktu berlakunya |
| `transaction_promo_codes` | Kode promo yang dipakai setiap transaksi, beserta snapshot funnel/source-nya |
| `jobs`, `failed_jobs` | Antrian (queue) Laravel untuk sinkronisasi dan backfill |
| `cache`, `cache_locks` | Dipakai untuk pembatasan percobaan login |
| `migrations` | Catatan migration milik Laravel |

## Relasi dan aturan hapus

| Relasi | Wajib | Jika data induk dihapus |
|---|---|---|
| `accounts.role_id` → `roles.id` | Ya | **Ditolak** selama role masih dipakai akun |
| `accounts.division_id` → `divisions.id` | Tidak | Akun tetap ada, `division_id` menjadi **NULL** |
| `sessions.user_id` → `accounts.id` | Tidak | Session **ikut terhapus**, sehingga akun langsung ter-logout |
| `transactions.platform_id` → `platforms.id` | Ya | **Ditolak** selama platform masih punya transaksi |
| `downloaders.platform_id` → `platforms.id` | Ya | **Ditolak** selama platform masih punya data downloader |
| `sync_logs.platform_id` → `platforms.id` | Ya | **Ditolak** selama platform masih punya log sinkronisasi |
| `dashboard_snapshots.platform_id` → `platforms.id` | Ya | **Ditolak** selama platform masih punya snapshot |
| `promo_codes.platform_id`, `promo_code_versions.platform_id`, `transaction_promo_codes.platform_id_snapshot` → `platforms.id` | Ya | **Ditolak** selama platform masih dipakai |
| `promo_codes.funnel_id` → `funnels.id`, `promo_codes.source_id` → `promo_sources.id` | Tidak | **Ditolak** selama funnel/source masih dipakai kode promo |
| `promo_code_versions.promo_code_id` → `promo_codes.id` | Ya | Riwayat versi **ikut terhapus** |
| `promo_code_versions.funnel_id`, `.source_id` | Tidak | Menjadi **NULL**, nama snapshot tetap tersimpan |
| `transaction_promo_codes.transaction_id` → `transactions.id` | Ya | Penggunaan kode **ikut terhapus** |
| `transaction_promo_codes.promo_code_id` → `promo_codes.id` | Tidak | **Ditolak** selama kode promo sudah dipakai transaksi |
| `transaction_promo_codes.funnel_id_snapshot`, `.source_id_snapshot` | Tidak | Menjadi **NULL**, nama snapshot tetap tersimpan |

## Aturan bisnis

- Tidak ada registrasi mandiri. Akun hanya dibuat oleh Manager lewat `POST /api/accounts`.
- Semua role boleh melihat daftar akun. Hanya role Manager (`MGR`) yang boleh membuat, mengubah, dan menghapus akun.
- Manager tidak bisa menghapus akunnya sendiri dan tidak bisa mengubah role akunnya sendiri.
- Semua role boleh melihat daftar dan detail divisi. Hanya role Manager (`MGR`) yang boleh membuat, mengubah, dan menghapus divisi.
- Password disimpan dalam bentuk hash (bcrypt) dan tidak pernah dikembalikan oleh API.
- Username: 4-25 karakter, hanya huruf, angka, titik, dan underscore.
- Transaksi dihitung **berhasil** jika `status_message` = `Success`. Transaksi lain diabaikan di semua KPI.
- Hanya transaksi Markaz dengan `status_message` = `Success` yang diambil dan disimpan ke `transactions`.
- Penggunaan promo, alert, dan backfill pendaftaran hanya memproses transaksi sukses. `transactions.promo_code_anomaly` menandai payload promo invalid: detail ada di file log, histori penggunaan tetap disimpan, dan seluruh query analisis promo/alert mengecualikannya. Ringkasan Performa umum tetap menghitung fakta transaksi sukses tersebut.
- Snapshot promo memakai master saat pertama diproses, tanpa melihat tanggal bayar; snapshot yang sudah terhubung tetap, termasuk hasil kosong. Rename menggunakan label master terbaru tanpa mengubah snapshot tersimpan.
- Satu transaksi Markaz tersimpan sekali per platform: `(platform_id, markaz_transaction_id)` unik. `markaz_transaction_id` boleh NULL pada histori yang belum punya ID sumber terverifikasi.
- `transactions.trx_id` tetap nomor untuk tampilan dan pencocokan, tetapi tidak lagi unik global: nilai yang sama boleh ada di platform berbeda.
- `downloaders` unik per kombinasi `platform_id` + `date`: satu platform pada satu tanggal hanya punya satu baris, dan sinkronisasi memakai upsert.
- `markaz_api_tokens.token` disimpan terenkripsi dan tidak pernah dikembalikan oleh API. Hanya boleh ada 1 token aktif; token lama diganti saat token baru disimpan.
- Satu user diidentifikasi dari `customer_email` (huruf kecil, tanpa spasi di awal atau akhir). Transaksi tanpa email tetap dihitung di revenue dan jumlah transaksi, tapi tidak dihitung sebagai user.
- Rumus KPI Ringkasan Performa ada di [api/performance.md](api/performance.md).
- Kolom transaksi untuk Dashboard (FIX-15-BE-02) ada di bagian [Kolom transaksi Dashboard](#kolom-transaksi-dashboard-fix-15-be-02).
- Kontrak baca tanggal transaksi, status laporan, dan metadata kelengkapan data untuk Analisis Performa ada di [transaction-dates-coverage.md](transaction-dates-coverage.md). Perubahan skemanya dimiliki FIX-15.
- Kode promo transaksi dinormalisasi ke `transaction_promo_codes` tanpa mengubah `transactions.promo_code`. Aturan lengkapnya ada di [promo-codes.md](promo-codes.md).
- Data `transactions` dan `downloaders` diisi dari sinkronisasi API Markaz, tidak dari seeder. Daftar platform yang disinkronkan diambil dari tabel `platforms` (master platform).

## Kolom transaksi Dashboard (FIX-15-BE-02)

Skema `transactions` mengikuti [kontrak tanggal transaksi](transaction-dates-coverage.md). DBML-nya ada di [database/transactions.dbml](database/transactions.dbml).

| Kolom | Null | Isi |
|---|---|---|
| `markaz_transaction_id` | Ya | ID transaksi Markaz. Key sumber bersama `platform_id` |
| `markaz_customer_id` | Ya | ID akun pembayar dari API. `markaz_user_id` lama belum dipakai sebagai akun pembayar sampai pemetaannya terverifikasi |
| `markaz_ref_id` | Ya | Terisi berarti anggota grup; nilainya `markaz_transaction_id` pembeli pada platform yang sama |
| `markaz_created_at` | Ya | Waktu transaksi dibuat di Markaz (WIB) |
| `markaz_paid_at` | Ya | `paid_date` terbaru dari API apa adanya |
| `paid_date`, `paid_time` | Ya | Tanggal dan jam bayar valid terakhir untuk laporan. Pending boleh kosong |
| `synced_at` | Ya | Waktu SiMarsel terakhir mengambil transaksi |
| `source_status`, `effective_status` | Ya | Kode status Markaz (0 Pending, 1 Success, 2 Expired, 3 Failed, 4 Canceled) |
| `is_claim_coupon` | Tidak (default `false`) | Checkout klaim kupon |
| `product_price`, `voucher_discount`, `admin_fee`, `net_nominal` | Ya | Rincian nominal dari sumber. `nominal` tetap tagihan |
| `customer_name`, `customer_phone` | Ya | Kontak pembeli. Email tetap di `customer_email` |

- Kolom sumber bernilai NULL pada transaksi lama. Migration tidak mengisinya dari `created_at`, `updated_at`, atau nilai lokal lain; pengisian histori di luar FIX-15.
- Index: `(platform_id, markaz_transaction_id)` unik, `trx_id`, `(platform_id, markaz_ref_id)` untuk anggota grup, `markaz_created_at` untuk dasar tanggal dibuat, dan `(paid_date, paid_time)` untuk dasar tanggal bayar.
- PK `transactions.id` dan FK `transaction_promo_codes.transaction_id` tidak berubah.
- Rollback migration ditolak selama ada transaksi tanpa `paid_date` atau `trx_id` yang sama di beberapa baris, agar data Pending dan transaksi lintas platform tidak hilang.

## Snapshot Dashboard (FIX-15-BE-03)

`dashboard_snapshots` menyimpan nilai satu platform dan dataset pada jam potong (`cutoff_at`) untuk pembanding jam setara. DBML-nya, termasuk struktur `payload`, ada di [database/dashboard_snapshots.dbml](database/dashboard_snapshots.dbml). Model: `App\Models\DashboardSnapshot`.

| Kolom | Null | Isi |
|---|---|---|
| `platform_id` | Tidak | Platform pemilik snapshot |
| `dataset` | Tidak | `transactions` atau `downloaders` (`App\Enums\DashboardDataset`) |
| `date_from`, `date_to` | Tidak | Periode rekap (WIB, inklusif) |
| `cutoff_at` | Tidak | Jam rekap (WIB): kejadian sebelum jam ini |
| `is_complete` | Tidak (default `false`) | Data lengkap sampai `cutoff_at` saat dicatat |
| `payload` | Tidak | Salinan nilai pada `cutoff_at` |
| `recorded_at` | Tidak | Waktu catatan disimpan, bukan jam rekap |

- Unik per `(platform_id, dataset, date_from, date_to, cutoff_at)`. Dua jam potong pada platform dan tanggal yang sama (misalnya 13.00 dan 14.00 WIB) tersimpan sebagai dua baris.
- `downloaders.total` tetap satu total harian terbaru per `(platform_id, date)` dan dibaca terpisah dari snapshot. Snapshot tidak menggantikan dan tidak mengubah tabel itu.
- `payload` transaksi memuat metrik dasar tanggal dibuat dan dasar tanggal bayar, termasuk pembayaran dalam periode dari transaksi yang dibuat sebelum `date_from`. `payload` Downloader memuat total kumulatif per tanggal yang tercakup.
- `payload` adalah salinan saat dicatat: perubahan status atau tanggal bayar transaksi setelahnya tidak mengubah isinya.
- Batas data lengkap = `cutoff_at` terakhir yang punya snapshot `is_complete = true` di **semua** platform terpilih untuk dataset dan periode yang sama (`DashboardSnapshot::latestCompleteCutoff()`). Snapshot `is_complete = false` tetap tersimpan, tetapi tidak pernah dipakai sebagai batas lengkap.
- `sync_logs.finished_at` hanya jam job selesai, bukan bukti kelengkapan.
- `DashboardSnapshot::toComparisonRow()` mengeluarkan baris yang dibaca `ComparisonSnapshotService::read()`. Penerbitan snapshot oleh job dan rumus agregasi di luar FIX-15.

## Data awal (seeder)

| Data | Nilai |
|---|---|
| Role | `MGR` Manager (id 1), `EMP` Employee (id 2) |
| Akun manager default | username `manager`, password `Manager123!` |
| Divisi | Kosong |
| Funnel | Aplikasi, Marketing, Sales, Ads, Live, Lainnya |
| Source promo | Aplikasi, Instagram, TikTok, Telegram, Sales, Ads, Live, Reseller, Lainnya |

Akun manager default hanya untuk penanda awal bahwa aplikasi berjalan. Ganti password-nya lewat `PATCH /api/accounts/1` sebelum dipakai di luar lingkungan lokal.

## Perubahan dari bawaan Laravel

- Tabel `users`, `password_reset_tokens`, dan `job_batches` tidak dipakai.
- `QUEUE_CONNECTION=database`: job sinkronisasi dan backfill disimpan di tabel `jobs`, job yang gagal di `failed_jobs`.
- Login memakai model `App\Models\Account` (`config/auth.php`).
- Timezone aplikasi `Asia/Jakarta` (`APP_TIMEZONE`), sehingga tanggal "hari ini" dan `created_at`/`updated_at` memakai WIB.
