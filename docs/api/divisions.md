# API Divisions (CRUD Divisi)

Semua endpoint di halaman ini **wajib login** dan **hanya untuk role Manager** (`MGR`). Aturan header, CSRF, dan kode error umum ada di [authentication.md](authentication.md).

| Kondisi | Respons |
|---|---|
| Belum login | `401` `Silakan login terlebih dahulu.` |
| Login sebagai Employee | `403` `Anda tidak memiliki akses untuk melakukan aksi ini.` |
| ID divisi tidak ada | `404` `Data tidak ditemukan.` |

## Objek Division

```json
{
    "id": 1,
    "code_division": "IT",
    "name": "Information Tech"
}
```

## `GET /api/divisions`

Mengambil seluruh divisi, diurutkan berdasarkan `id`.

**200 OK**

```json
{ "data": [ { "id": 1, "...": "..." }, { "id": 2, "...": "..." } ] }
```

## `GET /api/divisions/{id}`

Mengambil satu divisi berdasarkan ID.

**200 OK**: objek Division di `data`.

## `POST /api/divisions`

Membuat divisi baru.

**Body**

| Field | Tipe | Aturan |
|---|---|---|
| `code_division` | string | Wajib, unik, maksimal 50 karakter |
| `name` | string | Wajib, unik, maksimal 25 karakter |

```json
{ "code_division": "IT", "name": "Information Tech" }
```

**201 Created**: objek Division di `data`, dengan `"message": "Divisi berhasil dibuat."`

**422**, contoh:

```json
{
    "message": "Kode divisi sudah digunakan.",
    "errors": { "code_division": ["Kode divisi sudah digunakan."] }
}
```

## `PUT /api/divisions/{id}` atau `PATCH /api/divisions/{id}`

Memperbarui divisi berdasarkan ID. **Kirim hanya field yang ingin diubah**, karena field yang tidak dikirim tetap sama. Aturannya sama dengan `POST`, tetapi `code_division` dan `name` boleh tetap sama dengan milik divisi itu sendiri.

```json
{ "name": "IT Support" }
```

**200 OK**: objek Division terbaru di `data`, dengan `"message": "Divisi berhasil diperbarui."`

## `DELETE /api/divisions/{id}`

Menghapus divisi berdasarkan ID. Akun yang berada di divisi tersebut **tidak ikut terhapus**, hanya `division` akun itu menjadi `null`.

**200 OK**

```json
{ "message": "Divisi berhasil dihapus." }
```
