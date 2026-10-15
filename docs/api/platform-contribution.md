# API Kontribusi Platform

Kontribusi setiap platform terhadap performa keseluruhan, untuk Pie Chart dan tabel Kontribusi Platform. Filter dan rumusnya sama dengan [Ringkasan Performa](performance.md), sehingga angkanya selalu konsisten dengan kartu KPI dan grafik.

Wajib login. Semua role (Manager dan Employee) boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md).

## `GET /api/performance/platform-contribution`

### Query parameter

Sama persis dengan `/api/performance/summary`.

| Parameter | Wajib | Format | Keterangan |
|---|---|---|---|
| `date_from` | Ya | `YYYY-MM-DD` | Tanggal mulai (inklusif) |
| `date_to` | Ya | `YYYY-MM-DD` | Tanggal akhir (inklusif), tidak boleh sebelum `date_from` |
| `platform` | Tidak | slug platform | Kosongkan untuk menampilkan semua platform |

Contoh: `GET /api/performance/platform-contribution?date_from=2026-09-01&date_to=2026-09-30`

### Rumus per platform

Hanya transaksi dengan `status_message` = `Success` yang dihitung.

| Field | Rumus |
|---|---|
| `revenue` | Jumlah `nominal` |
| `transactions` | Jumlah transaksi |
| `users` | Jumlah email unik (huruf kecil, tanpa spasi di awal atau akhir). Transaksi tanpa email tidak dihitung. |
| `contribution_percentage` | `revenue` platform ÷ total revenue × 100, dibulatkan 2 desimal. `0` jika total revenue = 0. |

- **Semua platform di master platform selalu ditampilkan**, termasuk yang tidak punya transaksi di periode itu (nilainya `0`).
- Urutan: revenue terbesar lebih dulu, lalu nama platform A-Z.
- Jika filter `platform` dipilih, hanya platform itu yang muncul, dengan kontribusi `100` (atau `0` jika tidak ada transaksi).
- Karena pembulatan, jumlah semua `contribution_percentage` bisa 99.99 atau 100.01.

### Catatan `users`

Satu email yang bertransaksi di dua platform dihitung di **kedua** platform. Karena itu `totals.users` (jumlah `users` semua platform) bisa lebih besar dari `unique_users` di Ringkasan Performa. `totals.revenue` dan `totals.transactions` selalu sama dengan `total_revenue` dan `total_transactions` di Ringkasan Performa.

### 200 OK

```json
{
    "data": {
        "filter": { "date_from": "2026-09-01", "date_to": "2026-09-30", "platform": null },
        "is_empty": false,
        "message": null,
        "totals": { "revenue": 1150000, "transactions": 6, "users": 4 },
        "platforms": [
            { "slug": "app-a", "name": "App A", "revenue": 1000000, "transactions": 4, "users": 2, "contribution_percentage": 86.96 },
            { "slug": "app-b", "name": "App B", "revenue": 150000, "transactions": 2, "users": 2, "contribution_percentage": 13.04 },
            { "slug": "app-c", "name": "App C", "revenue": 0, "transactions": 0, "users": 0, "contribution_percentage": 0 }
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
| `platforms` | Tabel kontribusi (Nama → Revenue → Transaksi → User → Persentase) dan isi tooltip tambahan |
| `totals` | Baris total di tabel |

### Data kosong

Jika tidak ada transaksi `Success` di periode yang dipilih, respons tetap **200**, dengan `is_empty: true`, `message: "Data tidak tersedia untuk filter yang dipilih."`, dan semua nilai `0`. Tampilkan `message` sebagai kondisi kosong, bukan pie chart.

### 422 Validasi gagal

Sama dengan `/api/performance/summary`: tanggal kosong atau salah format, `date_to` sebelum `date_from`, atau slug platform tidak ditemukan.
