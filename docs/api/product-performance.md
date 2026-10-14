# API Ringkasan Performa Produk

Performa setiap produk berdasarkan filter Source App/Platform, Tahun, dan Bulan, untuk tabel dan grafik Ringkasan Performa Produk. Data diambil dari tabel `transactions`, dengan rumus yang sama seperti [Ringkasan Performa](performance.md).

Wajib login. Semua role (Manager dan Employee) boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md).

## `GET /api/performance/products`

### Query parameter

| Parameter | Wajib | Format | Keterangan |
|---|---|---|---|
| `platform` | Tidak | slug platform | Source App. Kosongkan untuk menghitung semua platform |
| `year` | Ya | angka 4 digit, minimal `2000` | Tahun |
| `month` | Tidak | angka `1`-`12` | Bulan. Kosongkan untuk menghitung satu tahun penuh |

Contoh:

- `GET /api/performance/products?year=2026&month=9`: semua platform, September 2026.
- `GET /api/performance/products?platform=app-a&year=2026`: platform `app-a`, Januari-Desember 2026.

`filter.date_from` dan `filter.date_to` di respons menunjukkan rentang tanggal yang dipakai.

### Rumus per produk

Produk dikelompokkan berdasarkan kolom `transactions.product`. Hanya transaksi dengan `status_message` = `Success` yang dihitung.

| Field | Rumus |
|---|---|
| `name` | Nama produk (`transactions.product`). `null` untuk transaksi tanpa nama produk; semuanya digabung jadi satu baris. |
| `revenue` | Jumlah `nominal` |
| `transactions` | Jumlah transaksi |
| `new_users` | Jumlah transaksi dengan `product_type` = `Perdana` |
| `repeat_orders` | Jumlah transaksi dengan `product_type` = `Perpanjangan` |
| `users` | Jumlah email unik (huruf kecil, tanpa spasi di awal atau akhir). Transaksi tanpa email tidak dihitung. |
| `contribution_percentage` | `revenue` produk ÷ total revenue × 100, dibulatkan 2 desimal. `0` jika total revenue = 0. |

- Hanya produk yang punya transaksi berhasil di filter itu yang ditampilkan.
- Urutan: revenue terbesar lebih dulu, lalu jumlah transaksi terbesar, lalu nama produk A-Z.
- Jika `platform` kosong, produk dengan nama yang sama di beberapa platform digabung menjadi satu baris.
- Satu email yang membeli dua produk berbeda dihitung di `users` **kedua** produk.

### 200 OK

```json
{
    "data": {
        "filter": { "platform": null, "year": 2026, "month": 9, "date_from": "2026-09-01", "date_to": "2026-09-30" },
        "is_empty": false,
        "message": null,
        "totals": { "products": 2, "revenue": 800000, "transactions": 4, "new_users": 2, "repeat_orders": 2 },
        "products": [
            { "name": "Paket CPNS", "revenue": 600000, "transactions": 3, "new_users": 1, "repeat_orders": 2, "users": 2, "contribution_percentage": 75 },
            { "name": "Paket BUMN", "revenue": 200000, "transactions": 1, "new_users": 1, "repeat_orders": 0, "users": 0, "contribution_percentage": 25 }
        ],
        "labels": ["Paket CPNS", "Paket BUMN"],
        "datasets": [
            { "label": "Revenue", "data": [600000, 200000] }
        ]
    }
}
```

`labels` dan `datasets` bisa langsung dipakai di Chart.js.

### Data kosong

Jika tidak ada transaksi berhasil untuk filter yang dipilih, respons tetap **200** dengan `is_empty: true`, `message: "Data tidak tersedia untuk filter yang dipilih."`, `products` kosong, dan semua total bernilai `0`.

### 422 Validasi gagal

| Kondisi | Pesan |
|---|---|
| `year` kosong | `Tahun wajib diisi.` |
| `year` bukan angka | `Tahun harus berupa angka.` |
| `year` bukan 4 digit | `Tahun harus 4 digit, misalnya 2026.` |
| `month` di luar 1-12 | `Bulan harus antara 1 sampai 12.` |
| Slug platform tidak ada | `Platform tidak ditemukan.` |
