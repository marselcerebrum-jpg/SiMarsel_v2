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
| `transactions` | Transaksi pembelian per platform (`trx_id` unik) |
| `downloaders` | Jumlah downloader per platform per tanggal |
| `sync_logs` | Riwayat sinkronisasi data per platform |
| `markaz_api_tokens` | Token API Markaz (hanya 1 token aktif) |
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

## Aturan bisnis

- Tidak ada registrasi mandiri. Akun hanya dibuat oleh Manager lewat `POST /api/accounts`.
- Hanya role Manager (`MGR`) yang boleh melihat, membuat, mengubah, dan menghapus akun.
- Manager tidak bisa menghapus akunnya sendiri.
- Semua role boleh melihat daftar dan detail divisi. Hanya role Manager (`MGR`) yang boleh membuat, mengubah, dan menghapus divisi.
- Password disimpan dalam bentuk hash (bcrypt) dan tidak pernah dikembalikan oleh API.
- Username: 4-25 karakter, hanya huruf, angka, titik, dan underscore.
- Transaksi dihitung **berhasil** jika `status_message` = `Success`. Transaksi lain diabaikan di semua KPI.
- Hanya transaksi Markaz dengan `status_message` = `Success` yang diambil dan disimpan ke `transactions`.
- `transactions.trx_id` unik, sehingga satu transaksi Markaz hanya tersimpan sekali.
- `downloaders` unik per kombinasi `platform_id` + `date`: satu platform pada satu tanggal hanya punya satu baris, dan sinkronisasi memakai upsert.
- `markaz_api_tokens.token` disimpan terenkripsi dan tidak pernah dikembalikan oleh API. Hanya boleh ada 1 token aktif; token lama diganti saat token baru disimpan.
- Satu user diidentifikasi dari `customer_email` (huruf kecil, tanpa spasi di awal atau akhir). Transaksi tanpa email tetap dihitung di revenue dan jumlah transaksi, tapi tidak dihitung sebagai user.
- Rumus KPI Ringkasan Performa ada di [api/performance.md](api/performance.md).
- Data `transactions` dan `downloaders` diisi dari sinkronisasi API Markaz, tidak dari seeder. Daftar platform yang disinkronkan diambil dari tabel `platforms` (master platform).

## Data awal (seeder)

| Data | Nilai |
|---|---|
| Role | `MGR` Manager (id 1), `EMP` Employee (id 2) |
| Akun manager default | username `manager`, password `Manager123!` |
| Divisi | Kosong |

Akun manager default hanya untuk penanda awal bahwa aplikasi berjalan. Ganti password-nya lewat `PATCH /api/accounts/1` sebelum dipakai di luar lingkungan lokal.

## Perubahan dari bawaan Laravel

- Tabel `users`, `password_reset_tokens`, dan `job_batches` tidak dipakai.
- `QUEUE_CONNECTION=database`: job sinkronisasi dan backfill disimpan di tabel `jobs`, job yang gagal di `failed_jobs`.
- Login memakai model `App\Models\Account` (`config/auth.php`).
- Timezone aplikasi `Asia/Jakarta` (`APP_TIMEZONE`), sehingga tanggal "hari ini" dan `created_at`/`updated_at` memakai WIB.
