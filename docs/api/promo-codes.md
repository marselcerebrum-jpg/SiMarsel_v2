# API Master Kode Promo

Daftar, Single Entry, edit metadata, dan hapus kode promo untuk tab **Kode Promo** di halaman Manajemen Promo. Aturan versi dan snapshot ada di [promo-codes.md](../promo-codes.md); master Funnel, Source, dan Platform ada di [promo-master-data.md](promo-master-data.md).

Wajib login. **Semua user yang login** boleh melihat, menambah, mengubah, dan menghapus kode promo. Aturan header dan kode error umum ada di [authentication.md](authentication.md).

## Objek kode promo

Tidak ada field tipe kode maupun status aktif/nonaktif.

```json
{
    "id": 12,
    "code": "CPNS26",
    "platform": { "id": 2, "slug": "jadiasn", "name": "JADIASN" },
    "funnel": { "id": 1, "name": "Aplikasi" },
    "source": null,
    "usages_count": 128,
    "created_at": "2026-09-01T08:00:00+07:00",
    "updated_at": "2026-09-15T09:30:00+07:00"
}
```

| Field | Keterangan |
|---|---|
| `code` | Disimpan tanpa spasi di awal/akhir dan dalam huruf besar |
| `funnel`, `source` | `null` jika belum diisi (berlaku untuk semua kode, termasuk reseller) |
| `usages_count` | Jumlah transaksi yang memakai kode ini. `0` berarti kode boleh dihapus dan kode/platform-nya boleh diubah. |
| `versions` | Hanya di detail, create, dan update: riwayat metadata, terbaru lebih dulu (lihat di bawah) |

## `GET /api/promo-codes`

| Parameter | Wajib | Keterangan |
|---|---|---|
| `search` | Tidak | Mencari di kode, nama/slug platform, nama funnel, dan nama source |
| `platform_id` | Tidak | Hanya kode milik platform ini |
| `per_page` | Tidak | 1-100, default 15 |
| `page` | Tidak | Halaman, default 1 |

Urutan: kode yang terakhir dibuat lebih dulu. Respons memakai pagination Laravel: `data`, `links`, dan `meta` (`current_page`, `last_page`, `per_page`, `total`, ...).

## `POST /api/promo-codes` (Single Entry)

```json
{ "code": "CPNS26", "platform_id": 2, "funnel_id": 1, "source_id": null }
```

- `code` dan `platform_id` wajib. `funnel_id` dan `source_id` boleh `null` atau tidak dikirim.
- Membuat versi metadata pertama dengan `effective_from` = saat disimpan.
- Transaksi lama dengan platform + kode yang sama yang **belum terpetakan** langsung dihubungkan ke kode ini dan diklasifikasi dengan funnel/source yang didaftarkan. Penggunaan yang sudah terklasifikasi hanya dihubungkan; snapshot funnel/source-nya tidak ditimpa.
- Endpoint ini juga dipakai untuk mendaftarkan kode dari alert kode belum terdaftar (lihat di bawah).

**201 Created**, dengan `message: "Kode promo berhasil dibuat."`.

## `POST /api/promo-codes/bulk` (Bulk Entry)

Menyimpan banyak kode sekaligus dari form bergaya spreadsheet. Setiap baris divalidasi dengan **aturan yang sama persis dengan Single Entry** (kode/platform wajib, funnel/source boleh kosong, unik per platform + kode).

```json
{
    "rows": [
        { "code": "CPNS26", "platform_id": 2, "funnel_id": 1, "source_id": 2 },
        { "code": "BUMN26", "platform_id": 3 },
        { "code": "", "platform_id": null, "funnel_id": null, "source_id": null }
    ]
}
```

| Aturan | Perilaku |
|---|---|
| Kolom | `code`, `platform_id`, `funnel_id`, `source_id` (ID dari dropdown). `client_row_id` opsional untuk korelasi respons; tidak disimpan. Field lain diabaikan; tidak ada tipe/status. |
| Baris invalid | Tidak membatalkan baris lain. Baris yang valid tetap disimpan. |
| Kode sudah terdaftar | Baris **gagal** dengan alasan `Kode promo CPNS26 sudah terdaftar di platform JADIASN.` Data yang ada tidak diubah dan tidak diduplikasi. |
| Kode sama dua kali dalam satu kiriman | Baris pertama disimpan; baris berikutnya gagal dengan `Duplikat dengan baris 1.` |
| Baris kosong | Baris yang semua kolomnya kosong (sisa baris form) **diabaikan** dan tidak dihitung |
| Kode yang berhasil | Langsung punya versi metadata awal, dan transaksi lama yang belum terpetakan ikut dihubungkan (sama seperti Single Entry) |
| Batas | 1 sampai 500 baris per kiriman |

**200 OK**, selalu, walaupun ada baris yang gagal:

```json
{
    "data": {
        "summary": { "total": 2, "succeeded": 1, "failed": 1 },
        "rows": [
            { "row": 1, "status": "created", "code": "CPNS26", "promo_code": { "id": 12, "code": "CPNS26", "...": "objek kode promo" }, "errors": {} },
            { "row": 2, "status": "failed", "code": "BUMN26", "promo_code": null, "errors": { "code": ["Kode promo BUMN26 sudah terdaftar di platform CEREBRUM."] } }
        ]
    }
}
```

- `row` = nomor baris di form (mulai dari 1), sehingga baris yang diabaikan tidak menggeser nomor baris lain.
- `client_row_id` = string ID sementara dari client (maksimal 100 karakter dan unik dalam kiriman), dikembalikan pada setiap hasil termasuk yang gagal; `null` untuk client lama yang tidak mengirimnya. FE memasangkan hasil berdasarkan ID ini. Ini bukan kunci idempotensi dan tidak memerlukan kolom database.
- Hasil paste Platform/Funnel/Source yang tidak ditemukan ditandai invalid di FE dan tidak dikirim sebagai `null`; baris valid lain tetap disimpan. Backend tetap menolak ID yang invalid atau tidak ditemukan. Pengguna boleh memilih kosong secara sengaja untuk Funnel/Source.
- Selama request berlangsung, aksi yang mengubah baris dan submit ulang dinonaktifkan. Ringkasan FE juga mencakup baris paste invalid yang ditahan di layar.
- `status` = `created` atau `failed`. `errors` memakai key yang sama dengan field (`code`, `platform_id`, `funnel_id`, `source_id`), jadi bisa ditampilkan langsung di sel yang salah.
- **422** untuk bentuk kiriman yang salah: `rows` kosong (`Isi minimal satu baris kode promo.`), lebih dari 500 baris (`Maksimal 500 baris kode promo dalam sekali simpan.`), baris yang bukan objek, atau `client_row_id` invalid/duplikat.

## `GET /api/promo-codes/{id}`

Detail beserta `versions`:

```json
"versions": [
    { "id": 8, "code": "CPNS26", "platform_id": 2, "funnel": { "id": 2, "name": "Marketing" }, "source": null, "effective_from": "2026-09-15T09:30:00+07:00", "effective_until": null },
    { "id": 5, "code": "CPNS26", "platform_id": 2, "funnel": { "id": 1, "name": "Aplikasi" }, "source": null, "effective_from": "2026-09-01T08:00:00+07:00", "effective_until": "2026-09-15T09:30:00+07:00" }
]
```

Nama funnel/source di `versions` adalah nama **saat versi itu dibuat**, bukan nama master sekarang. `effective_until: null` adalah versi yang sedang berlaku.

## `PUT` / `PATCH /api/promo-codes/{id}`

Kirim hanya field yang diubah: `code`, `platform_id`, `funnel_id`, `source_id`. `funnel_id: null` atau `source_id: null` mengosongkannya.

| Perubahan | Aturan |
|---|---|
| Funnel / Source | Selalu boleh. Versi aktif ditutup dan versi baru dimulai **saat disimpan**; user tidak mengisi tanggal efektif. Snapshot transaksi yang sudah terklasifikasi **tidak berubah**; hanya transaksi setelah waktu itu yang memakai funnel/source baru. |
| Kode / Platform | Hanya boleh jika kode **belum pernah dipakai** transaksi (`usages_count` = 0). Juga dicatat sebagai versi baru. |
| Tidak ada yang berubah | Tidak membuat versi baru |

**Konfirmasi di FE:** jika Funnel dan/atau Source diubah, tampilkan peringatan konfirmasi sebelum mengirim request. Batal = jangan kirim; Lanjut = kirim ke BE.

**200 OK**, dengan `message: "Kode promo berhasil diperbarui."`.

## `DELETE /api/promo-codes/{id}`

- Kode yang **sudah dipakai** transaksi tidak bisa dihapus (**409**).
- Kode yang belum pernah dipakai dihapus bersama riwayat versinya sendiri. Penggunaan transaksi tidak pernah ikut terhapus.

**200 OK**: `{ "message": "Kode promo berhasil dihapus." }`

## `GET /api/promo-codes/unregistered` (Alert kode belum terdaftar)

Kode promo yang muncul di transaksi Markaz tetapi **belum punya master** untuk platformnya, satu baris per platform + kode yang dinormalisasi. Parameter `search` (kode, raw code, nama/slug platform), `platform_id`, `per_page` (1-100, default 15), dan `page` sama dengan daftar kode promo. `meta.total` = jumlah kode belum terdaftar (bisa dipakai untuk badge alert).

```json
{
    "data": [
        {
            "code": "CPNS26",
            "raw_codes": [" cpns26 ", "CPNS26"],
            "platform": { "id": 2, "slug": "jadiasn", "name": "JADIASN" },
            "usages_count": 15,
            "transactions_count": 14,
            "first_used_on": "2026-09-01",
            "last_used_on": "2026-10-20",
            "register": { "code": "CPNS26", "platform_id": 2 }
        }
    ],
    "links": { "...": "..." },
    "meta": { "total": 1, "...": "..." }
}
```

| Field | Keterangan |
|---|---|
| `code` | Kode yang dinormalisasi (trim + huruf besar), sama dengan yang akan disimpan di master |
| `raw_codes` | Semua penulisan asli dari Markaz untuk kode ini |
| `usages_count` | Jumlah baris penggunaan kode (satu transaksi bisa memakai kode yang sama di dua posisi) |
| `transactions_count` | Jumlah transaksi berbeda yang memakai kode ini |
| `first_used_on`, `last_used_on` | Tanggal bayar transaksi pertama dan terakhir yang memakai kode ini |
| `register` | Isi awal form pendaftaran: kirim ke `POST /api/promo-codes` bersama `funnel_id`/`source_id` pilihan user |

Urutan: penggunaan terbanyak lebih dulu.

**Alur daftar dari alert:** user memilih "Daftarkan" → FE membuka form Single Entry dengan Kode Promo dan Platform dari `register` → `POST /api/promo-codes`. Validasi unik platform + kode tetap berlaku. Setelah berhasil, penggunaan lama dengan platform + kode yang sama dihubungkan ke kode baru (`usages_count` di respons) dan kode itu tidak lagi muncul di alert. Penggunaan yang sudah terklasifikasi hanya dihubungkan, snapshot funnel/source-nya tidak ditimpa.

Kode yang **sudah terdaftar tapi belum pernah dipakai tidak muncul** di alert; kode itu ada di `GET /api/promo-codes` dengan `usages_count: 0`.

## Error

| Status | Kondisi | Pesan |
|---|---|---|
| 422 | Kode kosong | `Kode promo wajib diisi.` |
| 422 | Platform kosong | `Platform wajib diisi.` |
| 422 | Platform/funnel/source tidak ada | `Platform tidak ditemukan.` / `Funnel tidak ditemukan.` / `Source tidak ditemukan.` |
| 422 | Kode sudah ada di platform yang sama | `Kode promo CPNS26 sudah terdaftar di platform JADIASN.` (kode sama di platform lain boleh). Pesan yang sama juga dikembalikan jika dua request menyimpan kode yang sama bersamaan, bukan 500. |
| 422 | `per_page` di luar 1-100 | `Jumlah per halaman harus di antara 1 dan 100.` |
| 409 | Ubah kode/platform yang sudah dipakai | `Kode promo dan platform tidak dapat diubah karena kode ini sudah digunakan oleh 12 transaksi. Funnel dan source tetap bisa diubah; buat kode promo baru jika kodenya berbeda.` |
| 409 | Hapus kode yang sudah dipakai | `Kode promo tidak dapat dihapus karena sudah digunakan oleh 12 transaksi.` |
