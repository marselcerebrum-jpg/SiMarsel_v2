# API Detail Performa Paket

Data tabel Detail Performa Paket: Revenue, Trx, Low/Avg/High Price, dan Low/Avg/High Trx setiap paket, beserta Search dan Export. Filternya sama dengan [Ringkasan Performa Produk](product-performance.md), sehingga kedua tabel selalu konsisten.

Wajib login. Semua role (Manager dan Employee) boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md).

## Query parameter

Dipakai oleh kedua endpoint di bawah.

| Parameter | Wajib | Format | Keterangan |
|---|---|---|---|
| `platform` | Tidak | slug platform | Source App. Kosongkan untuk menghitung semua platform |
| `year` | Ya | angka 4 digit, minimal `2000` | Tahun |
| `month` | Tidak | angka `1`-`12` | Bulan. Kosongkan untuk menghitung satu tahun penuh |
| `search` | Tidak | teks, maksimal 100 karakter | Cari nama paket. Tidak membedakan huruf besar/kecil, cocok di bagian mana pun dari nama |

## Rumus per paket

Paket = kolom `transactions.product`. Hanya transaksi dengan `status_message` = `Success` yang dihitung.

| Field | Kolom tabel | Rumus |
|---|---|---|
| `name` | Paket | Nama paket. `null` untuk transaksi tanpa nama paket (digabung jadi satu baris) |
| `revenue` | Revenue | Jumlah `nominal` |
| `transactions` | Trx | Jumlah transaksi |
| `low_price` | Low Price | `nominal` terendah dari satu transaksi |
| `avg_price` | Avg Price | `revenue ÷ transactions`, dibulatkan 2 desimal |
| `high_price` | High Price | `nominal` tertinggi dari satu transaksi |
| `low_trx` | Low Trx | Jumlah transaksi terendah dalam **satu hari** |
| `avg_trx` | Avg Trx | `transactions ÷ active_days`, dibulatkan 2 desimal |
| `high_trx` | High Trx | Jumlah transaksi tertinggi dalam **satu hari** |
| `active_days` | - | Jumlah hari yang punya minimal 1 transaksi paket itu |

- Low/Avg/High Trx hanya memakai **hari yang ada penjualannya**. Hari tanpa transaksi tidak dihitung sebagai 0.
- Urutan: revenue terbesar lebih dulu, lalu jumlah transaksi terbesar, lalu nama paket A-Z.
- Jika `platform` kosong, paket dengan nama yang sama di beberapa platform digabung menjadi satu baris.

Contoh: Paket CPNS terjual 3 kali tanggal 1 (100rb, 150rb, 200rb) dan 1 kali tanggal 2 (350rb). Hasilnya Trx 4, Revenue 800rb, Low/Avg/High Price 100rb/200rb/350rb, Low/Avg/High Trx 1/2/3.

## `GET /api/performance/packages`

Contoh: `GET /api/performance/packages?platform=app-a&year=2026&month=9&search=cpns`

### 200 OK

```json
{
    "data": {
        "filter": { "platform": null, "year": 2026, "month": 9, "search": null, "date_from": "2026-09-01", "date_to": "2026-09-30" },
        "is_empty": false,
        "message": null,
        "totals": { "packages": 2, "revenue": 1000000, "transactions": 6 },
        "packages": [
            {
                "name": "Paket CPNS",
                "revenue": 800000,
                "transactions": 4,
                "low_price": 100000,
                "avg_price": 200000,
                "high_price": 350000,
                "low_trx": 1,
                "avg_trx": 2,
                "high_trx": 3,
                "active_days": 2
            }
        ]
    }
}
```

`totals` dihitung dari paket yang tampil, jadi ikut berubah saat `search` diisi.

Jika tidak ada data, respons tetap **200** dengan `is_empty: true`, `message: "Data tidak tersedia untuk filter yang dipilih."`, dan `packages` kosong.

## `GET /api/performance/packages/export`

Mengunduh file CSV berisi baris yang sama dengan `GET /api/performance/packages` untuk filter yang sama, termasuk `search`.

- Nama file: `detail-performa-paket-{platform atau semua-platform}-{tahun}[-{bulan}].csv`, contoh `detail-performa-paket-app-a-2026-09.csv`.
- Encoding UTF-8 dengan BOM, sehingga langsung terbaca benar di Excel.
- Kolom: `No, Paket, Revenue, Trx, Low Price, Avg Price, High Price, Low Trx, Avg Trx, High Trx`. Paket tanpa nama ditulis `Tanpa nama paket`.

Cara memanggil dari halaman Blade: arahkan browser ke URL export, misalnya `window.location = '/api/performance/packages/export?' + params`. Cookie session ikut terkirim, jadi tidak perlu `fetch()`.

## 422 Validasi gagal

Sama dengan [Ringkasan Performa Produk](product-performance.md#422-validasi-gagal), ditambah:

| Kondisi | Pesan |
|---|---|
| `search` lebih dari 100 karakter | `Pencarian maksimal 100 karakter.` |
