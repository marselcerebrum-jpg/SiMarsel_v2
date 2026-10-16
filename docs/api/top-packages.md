# API Top Paket Berdasarkan Pendapatan

Daftar paket dengan Total Pendapatan tertinggi, untuk chart Top Paket dan tooltip-nya (Nama Paket, Masa Aktif, Total Pendapatan). Filter dan rumusnya sama dengan [Ringkasan Performa Produk](product-performance.md).

Wajib login. Semua role (Manager dan Employee) boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md).

## `GET /api/performance/top-packages`

### Query parameter

| Parameter | Wajib | Format | Keterangan |
|---|---|---|---|
| `platform` | Tidak | slug platform | Source App. Kosongkan untuk menghitung semua platform |
| `year` | Ya | angka 4 digit, minimal `2000` | Tahun |
| `month` | Tidak | angka `1`-`12` | Bulan. Kosongkan untuk menghitung satu tahun penuh |
| `limit` | Tidak | angka `1`-`50` | Jumlah paket teratas yang dikembalikan. Default `10` |

Contoh: `GET /api/performance/top-packages?platform=jadiasn&year=2026&month=10&limit=5`

### Aturan

- Paket = kolom `transactions.product`. Hanya transaksi dengan `status_message` = `Success` yang dihitung.
- Nama produk dari Markaz berformat `<Nama Paket> - Masa Aktif <durasi>`, lalu dipecah menjadi `name` dan `active_period`. Paket yang sama dengan masa aktif berbeda (misalnya 6 Bulan dan 1 Tahun) adalah **baris terpisah**.
- Jika nama produk tidak mengikuti format itu, `name` berisi nama lengkap dan `active_period` bernilai `null`.
- Urutan: `revenue` terbesar lebih dulu, lalu jumlah transaksi terbesar, lalu nama produk A-Z.
- `totals` dan `contribution_percentage` dihitung dari **semua** paket di filter itu, bukan hanya paket yang tampil sesuai `limit`.

| Field per paket | Isi |
|---|---|
| `rank` | Peringkat, mulai dari 1 |
| `product` | Nama produk lengkap dari database |
| `name` | Nama Paket (tanpa masa aktif). `null` untuk transaksi tanpa nama produk |
| `active_period` | Masa Aktif, misalnya `6 Bulan`, `1 Tahun`, `2 Tahun` |
| `revenue` | Total Pendapatan: jumlah `nominal` |
| `transactions` | Jumlah transaksi |
| `contribution_percentage` | `revenue` paket ÷ total revenue semua paket × 100, dibulatkan 2 desimal |

### 200 OK

```json
{
    "data": {
        "filter": { "platform": null, "year": 2026, "month": 10, "limit": 10, "date_from": "2026-10-01", "date_to": "2026-10-31" },
        "is_empty": false,
        "message": null,
        "totals": { "packages": 3, "revenue": 1000000 },
        "packages": [
            {
                "rank": 1,
                "product": "Bimbel Persiapan Tes SKD CPNS 2026 - Masa Aktif 1 Tahun",
                "name": "Bimbel Persiapan Tes SKD CPNS 2026",
                "active_period": "1 Tahun",
                "revenue": 500000,
                "transactions": 2,
                "contribution_percentage": 50
            }
        ],
        "labels": ["Bimbel Persiapan Tes SKD CPNS 2026 (1 Tahun)"],
        "datasets": [
            { "label": "Total Pendapatan", "data": [500000] }
        ]
    }
}
```

### Untuk chart dan tooltip

- `labels` dan `datasets` langsung dipakai di Chart.js. Label berisi nama paket dan masa aktif, supaya tetap unik walaupun satu paket punya beberapa masa aktif.
- Untuk tooltip, ambil `packages[index]` sesuai titik yang di-hover, lalu tampilkan `name`, `active_period`, dan `revenue`.

### Data kosong

Jika tidak ada transaksi berhasil untuk filter yang dipilih, respons tetap **200** dengan `is_empty: true`, `message: "Data tidak tersedia untuk filter yang dipilih."`, `packages` kosong, dan total bernilai `0`.

### 422 Validasi gagal

Sama dengan [Ringkasan Performa Produk](product-performance.md#422-validasi-gagal), ditambah:

| Kondisi | Pesan |
|---|---|
| `limit` di luar 1-50 | `Jumlah paket harus antara 1 sampai 50.` |
