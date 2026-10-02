# API Authentication

Autentikasi memakai **session bawaan Laravel** (cookie), bukan Bearer token. Setelah login, browser atau API client menyimpan cookie session, dan cookie itu otomatis dikirim di request berikutnya.

Base URL lokal: `http://127.0.0.1:8000/api`

## Aturan umum

Kirim header berikut di setiap request:

| Header | Nilai | Keterangan |
|---|---|---|
| `Accept` | `application/json` | Wajib, agar error dikembalikan dalam JSON |
| `Content-Type` | `application/json` | Untuk request yang punya body |
| `X-CSRF-TOKEN` | isi `<meta name="csrf-token">` | Wajib untuk POST, PUT, PATCH, DELETE dari halaman Blade |
| `X-XSRF-TOKEN` | isi cookie `XSRF-TOKEN` (sudah di-URL-decode) | Alternatif untuk client di luar Blade, misalnya Apidog |

Cukup kirim salah satu dari `X-CSRF-TOKEN` atau `X-XSRF-TOKEN`.

Halaman Blade yang memakai helper `http()` (lihat `CLAUDE.md`) sudah otomatis mengirim `X-CSRF-TOKEN`, sehingga tidak perlu memanggil `/csrf-cookie`.

## Endpoint

### `GET /csrf-cookie`

Mengisi cookie `XSRF-TOKEN` dan session. Dipakai oleh client di luar Blade sebelum login.

**Respons:** `204 No Content`

### `POST /login`

Dibatasi **5 percobaan per menit**.

**Body**

| Field | Tipe | Aturan |
|---|---|---|
| `username` | string | Wajib, 4-25 karakter, hanya huruf, angka, titik (`.`), dan underscore (`_`) |
| `password` | string | Wajib |

```json
{ "username": "manager", "password": "Manager123!" }
```

**200 OK**

```json
{
    "data": {
        "id": 1,
        "fullname": "Default Manager",
        "username": "manager",
        "role": { "code_role": "MGR", "name": "Manager" },
        "division": null
    },
    "message": "Login berhasil."
}
```

Gunakan `data.role.code_role` untuk menentukan halaman yang ditampilkan (`MGR` = Manager, `EMP` = Employee). Password tidak pernah dikembalikan oleh API.

**422 Unprocessable Content**, jika username atau password salah:

```json
{
    "message": "Username atau password salah.",
    "errors": { "username": ["Username atau password salah."] }
}
```

Jika format input salah, `errors` berisi pesan per field (`username`, `password`).

### `POST /logout`

Membutuhkan login. Session dihapus dan token CSRF diganti, jadi ambil token baru sebelum request berikutnya.

**200 OK**

```json
{ "message": "Logout berhasil." }
```

## Kode error umum

| Status | Arti | Contoh `message` |
|---|---|---|
| 401 | Belum login atau session habis | `Silakan login terlebih dahulu.` |
| 403 | Login, tapi role tidak punya akses | `Anda tidak memiliki akses untuk melakukan aksi ini.` |
| 404 | Data tidak ditemukan | `Data tidak ditemukan.` |
| 419 | Token CSRF tidak ada atau salah | `CSRF token mismatch.` |
| 422 | Validasi gagal atau aturan bisnis dilanggar | Lihat `message` dan `errors` |
| 429 | Terlalu banyak percobaan login | `Too Many Attempts.` |

Session berlaku 120 menit sejak aktivitas terakhir (`SESSION_LIFETIME`).

## Mencoba dengan Apidog atau Postman

1. `GET /api/csrf-cookie`, lalu tambahkan script post-processor:
   ```js
   pm.environment.set('xsrf', decodeURIComponent(pm.cookies.get('XSRF-TOKEN')));
   ```
2. `POST /api/login` dengan header `X-XSRF-TOKEN: {{xsrf}}` dan body raw JSON.
3. Untuk request selanjutnya, pakai header yang sama. Ulangi langkah 1 setelah login atau logout, karena token CSRF berganti.

Kirim username dan password di **body**, jangan di query params.
