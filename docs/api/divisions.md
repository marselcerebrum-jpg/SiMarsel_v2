# API Divisions

Data divisi disimpan di tabel master `divisions` dan dipakai oleh entitas lain, misalnya `accounts.division_id`.

Semua endpoint di halaman ini **wajib login**. Aturan header, CSRF, dan kode error umum ada di [authentication.md](authentication.md).

| Endpoint | Role yang boleh |
|---|---|
| `GET /api/divisions`, `GET /api/divisions/{id}` | Semua role (Manager dan Employee) |
| `POST`, `PUT`/`PATCH`, `DELETE` | Hanya Manager (`MGR`) |

| Kondisi | Respons |
|---|---|
| Belum login | `401` `Silakan login terlebih dahulu.` |
| Employee mencoba membuat, mengubah, atau menghapus divisi | `403` `Anda tidak memiliki akses untuk melakukan aksi ini.` |
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

Membuat divisi baru. Divisi langsung tersimpan di tabel master dan bisa langsung dipakai, misalnya sebagai `division_id` akun.

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
    "data": { "id": 1, "code_division": "IT", "name": "Information Tech" },
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

Memperbarui divisi berdasarkan ID. **Kirim hanya field yang ingin diubah**, karena field yang tidak dikirim tetap sama. Aturannya sama dengan `POST`, tetapi `code_division` dan `name` boleh tetap sama dengan milik divisi itu sendiri.

```json
{ "name": "IT Support" }
```

**200 OK**: objek Division terbaru di `data`, dengan `"message": "Divisi berhasil diperbarui."`

## `DELETE /api/divisions/{id}`

Menghapus divisi secara permanen. Akun yang berada di divisi tersebut **tidak ikut terhapus**, hanya `division` akun itu menjadi `null`.

**200 OK**

```json
{ "message": "Divisi berhasil dihapus." }
```

## Catatan untuk frontend

- Daftar divisi (`GET /api/divisions`) bisa dipakai di halaman mana pun untuk semua role, misalnya untuk pilihan divisi.
- Halaman/form pembuatan divisi hanya ditampilkan untuk Manager. Kolom input: **Nama divisi** (`name`) dan **Kode divisi** (`code_division`).
- Tampilkan `errors.<field>[0]` di bawah field yang sesuai jika mendapat 422.
