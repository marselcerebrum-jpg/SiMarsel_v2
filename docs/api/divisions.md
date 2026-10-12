# API Divisions

Data divisi disimpan di tabel master `divisions` dan dipakai oleh entitas lain, misalnya `accounts.division_id`.

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
    "name": "Information Tech",
    "is_active": true
}
```

## `GET /api/divisions`

Mengambil seluruh divisi (aktif dan nonaktif), diurutkan berdasarkan `id`.

**200 OK**

```json
{ "data": [ { "id": 1, "...": "..." }, { "id": 2, "...": "..." } ] }
```

## `GET /api/divisions/{id}`

Mengambil satu divisi berdasarkan ID.

**200 OK**: objek Division di `data`.

## `POST /api/divisions`

Membuat divisi baru. Divisi yang baru dibuat langsung berstatus **aktif** (`is_active: true`). Status tidak bisa diatur dari request.

**Body**

| Field | Tipe | Aturan |
|---|---|---|
| `code_division` | string | Wajib, unik, maksimal 50 karakter |
| `name` | string | Wajib, unik, maksimal 25 karakter |

Isian yang hanya berisi spasi dianggap kosong.

```json
{ "code_division": "IT", "name": "Information Tech" }
```

**201 Created**

```json
{
    "data": { "id": 1, "code_division": "IT", "name": "Information Tech", "is_active": true },
    "message": "Divisi berhasil dibuat."
}
```

**422**, contoh:

```json
{
    "message": "Kode divisi sudah digunakan. (and 1 more error)",
    "errors": {
        "code_division": ["Kode divisi sudah digunakan."],
        "name": ["Nama divisi sudah digunakan."]
    }
}
```

## `PUT /api/divisions/{id}` atau `PATCH /api/divisions/{id}`

Memperbarui divisi berdasarkan ID. **Kirim hanya field yang ingin diubah**, karena field yang tidak dikirim tetap sama.

| Field | Tipe | Aturan |
|---|---|---|
| `code_division` | string | Tidak boleh kosong, unik, maksimal 50 karakter. Boleh sama dengan kode divisi itu sendiri |
| `name` | string | Tidak boleh kosong, unik, maksimal 25 karakter. Boleh sama dengan nama divisi itu sendiri |
| `is_active` | boolean | `true` untuk mengaktifkan, `false` untuk menonaktifkan |

```json
{ "name": "IT Support", "is_active": false }
```

**200 OK**: objek Division terbaru di `data`, dengan `"message": "Divisi berhasil diperbarui."`

## `DELETE /api/divisions/{id}`

Menghapus divisi secara permanen. Akun yang berada di divisi tersebut **tidak ikut terhapus**, hanya `division` akun itu menjadi `null`. Untuk menyembunyikan divisi tanpa menghapusnya, gunakan `is_active: false`.

**200 OK**

```json
{ "message": "Divisi berhasil dihapus." }
```

## Catatan untuk frontend

- Halaman/form pembuatan divisi hanya ditampilkan untuk Manager. Kolom input: **Nama divisi** (`name`) dan **Kode divisi** (`code_division`).
- Tampilkan `errors.<field>[0]` di bawah field yang sesuai jika mendapat 422.
