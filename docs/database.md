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
| `divisions` | Divisi (`code_division` dan `name` unik) |
| `accounts` | Akun yang bisa login (`username` unik) |
| `sessions` | Session login (token) per akun |
| `cache`, `cache_locks` | Dipakai untuk pembatasan percobaan login |
| `migrations` | Catatan migration milik Laravel |

## Relasi dan aturan hapus

| Relasi | Wajib | Jika data induk dihapus |
|---|---|---|
| `accounts.role_id` → `roles.id` | Ya | **Ditolak** selama role masih dipakai akun |
| `accounts.division_id` → `divisions.id` | Tidak | Akun tetap ada, `division_id` menjadi **NULL** |
| `sessions.user_id` → `accounts.id` | Tidak | Session **ikut terhapus**, sehingga akun langsung ter-logout |

## Aturan bisnis

- Tidak ada registrasi mandiri. Akun hanya dibuat oleh Manager lewat `POST /api/accounts`.
- Hanya role Manager (`MGR`) yang boleh melihat, membuat, mengubah, dan menghapus akun.
- Manager tidak bisa menghapus akunnya sendiri.
- Password disimpan dalam bentuk hash (bcrypt) dan tidak pernah dikembalikan oleh API.
- Username: 4-25 karakter, hanya huruf, angka, titik, dan underscore.

## Data awal (seeder)

| Data | Nilai |
|---|---|
| Role | `MGR` Manager (id 1), `EMP` Employee (id 2) |
| Akun manager default | username `manager`, password `Manager123!` |
| Divisi | Kosong |

Akun manager default hanya untuk penanda awal bahwa aplikasi berjalan. Ganti password-nya lewat `PATCH /api/accounts/1` sebelum dipakai di luar lingkungan lokal.

## Perubahan dari bawaan Laravel

- Tabel `users`, `password_reset_tokens`, `jobs`, `job_batches`, dan `failed_jobs` dihapus karena tidak dipakai.
- `QUEUE_CONNECTION=sync`, karena tabel `jobs` dihapus.
- Login memakai model `App\Models\Account` (`config/auth.php`).
