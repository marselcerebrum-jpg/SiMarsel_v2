# API Ringkasan Produk

Data untuk halaman **Ringkasan Produk** (`/performance/products`): pilihan filter Source App/Platform, Tahun, dan Bulan, serta data setiap kartu. Bentuk respons mengikuti kontrak di `resources/js/services/products.js`.

Wajib login. Semua role (Manager dan Employee) boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md).

| Endpoint | Kartu di halaman |
|---|---|
| `GET /api/products/filters` | Dropdown filter |
| `GET /api/products/top-packages/revenue` | Top Paket Berdasarkan Pendapatan |
| `GET /api/products/top-packages/volume` | Top Paket Berdasarkan Volume Transaksi |
| `GET /api/products/performance` | Tabel Detail Performa Paket (search dan export dilakukan di frontend) |

## Sumber data

- Tabel `transactions`, hanya transaksi dengan `status_message` = `Success`.
- Paket = kolom `transactions.product`. Produk tanpa nama ditampilkan sebagai `Tanpa nama paket`, sehingga `name` tidak pernah `null`.
- Platform = `transactions.platform_id` → `platforms.slug`. Periode = `transactions.paid_date`.

## Filter

Ketiga endpoint data menerima filter yang sama, sehingga semua kartu selalu menampilkan periode dan platform yang sama.

| Parameter | Wajib | Format | Keterangan |
|---|---|---|---|
| `platform` | Tidak | slug platform | Source App. Kosongkan untuk semua platform |
| `year` | Ya | angka 4 digit, minimal `2000` | Tahun |
| `month` | Tidak | angka `1`-`12` | Bulan. Kosongkan untuk satu tahun penuh |
| `limit` | Tidak | angka `1`-`50` | Hanya untuk `top-packages`. Default `10` |

Ketiga filter bisa dipakai bersamaan, contohnya `?platform=jadiasn&year=2026&month=10`.

## `GET /api/products/filters`

Pilihan untuk dropdown. `platform` (opsional) membatasi `years` dan `months` ke platform itu.

```json
{
    "data": {
        "platforms": [{ "slug": "jadiasn", "name": "JadiASN" }, { "slug": "jadibumn", "name": "JadiBUMN" }],
        "years": [2026, 2025],
        "months": { "2026": [9, 10], "2025": [12] }
    }
}
```

| Field | Isi |
|---|---|
| `platforms` | Semua platform di master `platforms`, urut nama A-Z |
| `years` | Tahun yang punya transaksi, dari yang terbaru. Jika belum ada transaksi sama sekali, berisi tahun ini saja |
| `months` | Bulan yang punya transaksi, per tahun |

## `GET /api/products/top-packages/revenue` dan `/volume`

Nama produk Markaz berformat `<Nama Paket> - Masa Aktif <durasi>`, lalu dipecah menjadi `name` dan `active_period`. Jika formatnya berbeda, `name` berisi nama lengkap dan `active_period` bernilai `null`. Paket yang sama dengan masa aktif berbeda adalah baris terpisah.

| Endpoint | Urutan |
|---|---|
| `/revenue` | `revenue` terbesar, lalu `transactions`, lalu nama produk A-Z |
| `/volume` | `transactions` terbesar, lalu `revenue`, lalu nama produk A-Z |

```json
{
    "data": [
        {
            "name": "Bimbel Persiapan Tes SKD CPNS 2026",
            "active_period": "1 Tahun",
            "product": "Bimbel Persiapan Tes SKD CPNS 2026 - Masa Aktif 1 Tahun",
            "revenue": 1000000,
            "transactions": 3
        }
    ]
}
```

## `GET /api/products/performance`

Satu baris per paket, urut `revenue` terbesar. `name` berisi nama produk lengkap (termasuk masa aktif).

| Field | Rumus |
|---|---|
| `revenue` | Jumlah `nominal` |
| `trx` | Jumlah transaksi |
| `low_price` / `high_price` | `nominal` terendah / tertinggi dari satu transaksi |
| `avg_price` | `revenue ÷ trx`, dibulatkan 2 desimal |
| `low_trx` / `high_trx` | Jumlah transaksi terendah / tertinggi dalam satu hari |
| `avg_trx` | `trx ÷` jumlah hari yang ada penjualannya, dibulatkan 2 desimal |

Low/Avg/High Trx hanya memakai hari yang ada penjualannya; hari tanpa transaksi tidak dihitung sebagai 0.

```json
{
    "data": [
        {
            "name": "Bimbel Persiapan Tes SKD CPNS 2026 - Masa Aktif 1 Tahun",
            "revenue": 1000000,
            "trx": 3,
            "low_price": 300000,
            "low_trx": 1,
            "avg_price": 333333.33,
            "avg_trx": 1.5,
            "high_price": 400000,
            "high_trx": 2
        }
    ]
}
```

## Data kosong

Jika filter tidak punya data, ketiga endpoint data tetap mengembalikan **200** dengan `{ "data": [] }`. Halaman menampilkan kondisi kosong di setiap kartu.

## 422 Validasi gagal

| Kondisi | Pesan |
|---|---|
| `year` kosong | `Tahun wajib diisi.` |
| `year` bukan angka / bukan 4 digit | `Tahun harus berupa angka.` / `Tahun harus 4 digit, misalnya 2026.` |
| `month` di luar 1-12 | `Bulan harus antara 1 sampai 12.` |
| `limit` di luar 1-50 | `Jumlah paket harus antara 1 sampai 50.` |
| Slug platform tidak ada | `Platform tidak ditemukan.` |
