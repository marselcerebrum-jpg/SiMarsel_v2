# API Divisions

Data divisi disimpan di tabel master `divisions` dan dipakai oleh entitas lain, misalnya `accounts.division_id`.

Endpoint di halaman ini **wajib login** dan **hanya untuk role Manager** (`MGR`). Aturan header, CSRF, dan kode error umum ada di [authentication.md](authentication.md).

| Kondisi | Respons |
|---|---|
| Belum login | `401` `Silakan login terlebih dahulu.` |
| Login sebagai Employee | `403` `Anda tidak memiliki akses untuk melakukan aksi ini.` |

## Objek Division

```json
{
    "id": 1,
    "code_division": "IT",
    "name": "Information Tech",
    "is_active": true
}
```

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

## Catatan untuk frontend

- Halaman/form pembuatan divisi hanya ditampilkan untuk Manager. Kolom input: **Nama divisi** (`name`) dan **Kode divisi** (`code_division`).
- Tampilkan `errors.<field>[0]` di bawah field yang sesuai jika mendapat 422.
