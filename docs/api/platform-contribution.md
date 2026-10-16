# API Kontribusi Platform

Kontribusi setiap platform terhadap performa keseluruhan, untuk Pie Chart dan tabel Kontribusi Platform. Filter dan rumusnya sama dengan [Ringkasan Performa](performance.md), sehingga angkanya selalu konsisten dengan kartu KPI dan grafik.

Wajib login. Semua role (Manager dan Employee) boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md).

## `GET /api/performance/platform-contribution`

### Query parameter

Sama persis dengan `/api/performance/summary`, lihat [filter Ringkasan Performa](performance-filter.md): beberapa platform (`platforms[]`) dan satu mode periode (default bulan berjalan, rentang tanggal, bulan, beberapa tahun, atau rentang tahun).

Contoh:

- `GET /api/performance/platform-contribution?date_from=2026-09-01&date_to=2026-09-30`
- `GET /api/performance/platform-contribution?year=2026&month=9&platforms[]=jadiasn&platforms[]=cerebrum`
- `GET /api/performance/platform-contribution?years[]=2025&years[]=2026` (gabungan kedua tahun)

### Rumus per platform

Hanya transaksi dengan `status_message` = `Success` yang dihitung.

| Field | Rumus |
|---|---|
| `rank` | Peringkat berdasarkan urutan di bawah, mulai dari `1` |
| `revenue` | Jumlah `nominal` |
| `transactions` | Jumlah transaksi |
| `users` | Jumlah email unik (huruf kecil, tanpa spasi di awal atau akhir). Transaksi tanpa email tidak dihitung. |
| `contribution_percentage` | `revenue` platform ÷ total revenue × 100, dibulatkan **1 desimal**. `null` jika total revenue = 0, tampilkan sebagai **N/A**. |

- **Semua platform di master platform selalu ditampilkan**, termasuk yang tidak punya transaksi di periode itu (nilainya `0`).
- Jika beberapa platform dipilih lewat `platforms[]`, hanya platform-platform itu yang muncul, dan persentasenya dihitung dari total revenue platform yang dipilih.
- Urutan: revenue terbesar lebih dulu, lalu nama platform A-Z.
- Jika filter `platform` dipilih, hanya platform itu yang muncul, dengan kontribusi `100` (atau `null`/N/A jika tidak ada transaksi), dan `totals.platforms` = `1`.
- Karena pembulatan, jumlah semua `contribution_percentage` bisa 99.9 atau 100.1.

### Catatan `users`

Satu email yang bertransaksi di dua platform dihitung di **kedua** platform. Karena itu `totals.users` (jumlah `users` semua platform) bisa lebih besar dari `unique_users` di Ringkasan Performa. `totals.revenue` dan `totals.transactions` selalu sama dengan `total_revenue` dan `total_transactions` di Ringkasan Performa.

### 200 OK

```json
{
    "data": {
        "filter": { "mode": "date_range", "date_from": "2026-09-01", "date_to": "2026-09-30", "platform": null, "platforms": [], "...": "lihat performance-filter.md" },
        "is_empty": false,
        "message": null,
        "totals": { "revenue": 1150000, "transactions": 6, "users": 4, "platforms": 3 },
        "platforms": [
            { "rank": 1, "slug": "app-a", "name": "App A", "revenue": 1000000, "transactions": 4, "users": 2, "contribution_percentage": 87.0 },
            { "rank": 2, "slug": "app-b", "name": "App B", "revenue": 150000, "transactions": 2, "users": 2, "contribution_percentage": 13.0 },
            { "rank": 3, "slug": "app-c", "name": "App C", "revenue": 0, "transactions": 0, "users": 0, "contribution_percentage": 0.0 }
        ],
        "labels": ["App A", "App B", "App C"],
        "datasets": [
            { "label": "Revenue", "data": [1000000, 150000, 0] }
        ]
    }
}
```

| Field | Dipakai untuk |
|---|---|
| `labels`, `datasets` | Langsung ke Chart.js: `new Chart(canvas, { type: 'pie', data: { labels, datasets } })`. Nama platform muncul di tooltip saat hover. |
| `platforms` | Ranking table (Nama → Revenue → Transaksi → User → Persentase), progress bar (panjang = `contribution_percentage` dari skala 0–100), dan tooltip (nama, revenue, persentase) |
| `totals.revenue`, `totals.platforms` | Ringkasan di atas tabel: Total Revenue seluruh platform dan jumlah platform yang tercakup filter |
| `totals` | Baris total di tabel |

### Data kosong

Jika tidak ada transaksi `Success` di periode yang dipilih, respons tetap **200**, dengan `is_empty: true`, `message: "Tidak ada data kontribusi platform untuk filter yang dipilih."`, semua nilai `0`, dan `contribution_percentage: null` (N/A) karena total revenue = 0. `totals.platforms` tetap berisi jumlah platform. Tampilkan `message` sebagai kondisi kosong.

### 422 Validasi gagal

Sama dengan [filter Ringkasan Performa](performance-filter.md#422-validasi-gagal), misalnya lebih dari satu mode periode, format tanggal salah, atau slug platform tidak ditemukan.
