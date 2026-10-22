# API Master Data Promo: Funnel, Source, dan Platform

Pengelolaan master Funnel dan Source untuk tab **Master Data** di halaman Manajemen Promo, serta daftar Platform yang hanya bisa dibaca.

Wajib login. **Semua user yang login** (Manager dan Employee) boleh melihat, membuat, mengubah, dan menghapus Funnel dan Source, karena master ini dikelola oleh tim Sales/Marketing. Aturan header dan kode error umum ada di [authentication.md](authentication.md).

## Objek Funnel / Source

Funnel dan Source punya bentuk yang sama.

| Field | Tipe | Keterangan |
|---|---|---|
| `id` | integer | |
| `name` | string | Unik, maksimal 100 karakter |
| `promo_codes_count` | integer | Jumlah kode promo yang saat ini memakai funnel/source ini |

```json
{ "id": 1, "name": "Aplikasi", "promo_codes_count": 3 }
```

## Endpoint

| Method | Funnel | Source | Keterangan |
|---|---|---|---|
| GET | `/api/funnels` | `/api/promo-sources` | Daftar, urut dari yang pertama dibuat |
| POST | `/api/funnels` | `/api/promo-sources` | Tambah. Body: `{ "name": "Webinar" }` → **201** |
| GET | `/api/funnels/{id}` | `/api/promo-sources/{id}` | Detail |
| PUT / PATCH | `/api/funnels/{id}` | `/api/promo-sources/{id}` | Ubah nama. Body: `{ "name": "Webinar CPNS" }` |
| DELETE | `/api/funnels/{id}` | `/api/promo-sources/{id}` | Hapus (lihat aturan di bawah) |

### 200 / 201 OK

```json
{
    "data": { "id": 7, "name": "Webinar", "promo_codes_count": 0 },
    "message": "Funnel berhasil dibuat."
}
```

Pesan: `Funnel berhasil dibuat.` / `diperbarui.` / `dihapus.`, dan `Source berhasil ...` untuk Source. DELETE hanya mengembalikan `{ "message": "..." }`.

### Validasi nama (422)

| Kondisi | Pesan |
|---|---|
| Kosong | `Nama funnel wajib diisi.` / `Nama source wajib diisi.` |
| Lebih dari 100 karakter | `Nama funnel maksimal 100 karakter.` |
| Sudah dipakai | `Nama funnel sudah digunakan.` (menyimpan nama sendiri saat update tidak dianggap duplikat) |
| Nama kelompok sistem | `Nama funnel tidak boleh memakai nama kelompok sistem (Tanpa Kode, Tanpa Funnel, Tanpa Sumber).` |

Spasi di awal dan akhir nama otomatis dibuang.

### Hapus

- **Ditolak (409)** jika masih dipakai oleh kode promo (`promo_codes_count` > 0):

```json
{ "message": "Funnel tidak dapat dihapus karena masih digunakan oleh 3 kode promo. Ganti funnel pada kode promo tersebut terlebih dahulu." }
```

- Boleh dihapus jika hanya muncul di **riwayat** (versi metadata lama atau snapshot transaksi). Riwayat itu tetap menyimpan **nama** funnel/source-nya, hanya `id`-nya yang menjadi `null`.

### Ganti nama

Mengganti nama funnel/source **tidak mengubah** snapshot historis. Transaksi yang sudah terklasifikasi tetap menampilkan nama lama, sesuai aturan di [promo-codes.md](../promo-codes.md).

## Data awal

| Master | Nilai |
|---|---|
| Funnel | Aplikasi, Marketing, Sales, Ads, Live, Lainnya |
| Source | Aplikasi, Instagram, TikTok, Telegram, Sales, Ads, Live, Reseller, Lainnya |

**Tanpa Kode**, **Tanpa Funnel**, dan **Tanpa Sumber** adalah kelompok hasil analisis sistem, bukan data master, sehingga tidak ada di daftar dan tidak bisa dibuat.

## `GET /api/platforms`

Daftar platform dari master platform global, **hanya baca**. Platform tidak bisa dibuat, diubah, atau dihapus lewat API.

```json
{
    "data": [
        { "id": 1, "slug": "cerebrum", "name": "CEREBRUM" },
        { "id": 2, "slug": "jadiasn", "name": "JADIASN" }
    ]
}
```
