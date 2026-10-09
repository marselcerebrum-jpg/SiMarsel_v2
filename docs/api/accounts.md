# API Accounts (CRUD User)

Semua endpoint di halaman ini **wajib login** dan **hanya untuk role Manager** (`MGR`). Aturan header, CSRF, dan kode error umum ada di [authentication.md](authentication.md).

| Kondisi | Respons |
|---|---|
| Belum login | `401` `Silakan login terlebih dahulu.` |
| Login sebagai Employee | `403` `Anda tidak memiliki akses untuk melakukan aksi ini.` |
| ID akun tidak ada | `404` `Data tidak ditemukan.` |

## Objek Account

```json
{
    "id": 2,
    "fullname": "Budi Santoso",
    "username": "budi",
    "role": { "code_role": "EMP", "name": "Employee" },
    "division": { "code_division": "IT", "name": "Information Tech" }
}
```

`division` bernilai `null` jika akun tidak punya divisi. Password tidak pernah dikembalikan.

## `GET /api/accounts`

Mengambil seluruh akun, diurutkan berdasarkan `id`.

**200 OK**

```json
{ "data": [ { "id": 1, "...": "..." }, { "id": 2, "...": "..." } ] }
```

## `POST /api/accounts`

Mendaftarkan akun baru. Tidak ada fitur registrasi mandiri, jadi akun hanya bisa dibuat oleh Manager.

**Body**

| Field | Tipe | Aturan |
|---|---|---|
| `fullname` | string | Wajib, maksimal 255 karakter |
| `username` | string | Wajib, unik, 4-25 karakter, hanya huruf, angka, titik, dan underscore |
| `password` | string | Wajib, 8-255 karakter |
| `role_id` | integer | Wajib, harus ada di tabel `roles` |
| `division_id` | integer / null | Opsional, harus ada di tabel `divisions` |

```json
{
    "fullname": "Budi Santoso",
    "username": "budi",
    "password": "Budi12345",
    "role_id": 2,
    "division_id": null
}
```

**201 Created**: objek Account di `data`, dengan `"message": "Akun berhasil dibuat."`

**422**, contoh:

```json
{
    "message": "Username sudah digunakan.",
    "errors": { "username": ["Username sudah digunakan."] }
}
```

## `PUT /api/accounts/{id}` atau `PATCH /api/accounts/{id}`

Memperbarui akun berdasarkan ID. **Kirim hanya field yang ingin diubah**, karena field yang tidak dikirim tetap sama. Aturannya sama dengan `POST`, dengan dua perbedaan:

- `username` boleh tetap sama dengan username akun itu sendiri.
- `password` hanya diubah jika dikirim. Jangan kirim field ini jika password tidak diganti.

```json
{ "fullname": "Budi S.", "division_id": 1 }
```

**200 OK**: objek Account terbaru di `data`, dengan `"message": "Akun berhasil diperbarui."`

## `DELETE /api/accounts/{id}`

Menghapus akun berdasarkan ID. Session milik akun tersebut ikut terhapus, sehingga akun itu langsung ter-logout.

**200 OK**

```json
{ "message": "Akun berhasil dihapus." }
```

**422**, jika Manager mencoba menghapus akunnya sendiri:

```json
{ "message": "Anda tidak dapat menghapus akun Anda sendiri." }
```

## Catatan untuk frontend

- Daftar divisi untuk pilihan `division_id` diambil dari `GET /api/divisions` ([divisions.md](divisions.md)).
- Belum ada endpoint untuk daftar role. Saat ini ID role dari seeder adalah `1` = Manager (`MGR`) dan `2` = Employee (`EMP`).
- Tampilkan `errors.<field>[0]` di bawah field yang sesuai jika mendapat 422.
