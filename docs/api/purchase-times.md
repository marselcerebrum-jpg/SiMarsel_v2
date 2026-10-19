# API Distribusi Waktu Pembelian

Jumlah transaksi per rentang waktu pembelian, untuk Pie Chart di card Waktu Pembelian. Filter dan aturan transaksinya sama dengan [Ringkasan Performa](performance.md), sehingga totalnya selalu sama dengan KPI Total Transaksi.

Wajib login. Semua role (Manager dan Employee) boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md).

## `GET /api/performance/purchase-times`

### Query parameter

Sama persis dengan `/api/performance/summary`, lihat [filter Ringkasan Performa](performance-filter.md): beberapa platform (`platforms[]`) dan satu mode periode (default bulan berjalan, rentang tanggal, bulan, beberapa tahun, atau rentang tahun).

Contoh:

- `GET /api/performance/purchase-times?date_from=2026-09-01&date_to=2026-09-30`
- `GET /api/performance/purchase-times?year=2026&month=9&platforms[]=jadiasn&platforms[]=cerebrum`
- `GET /api/performance/purchase-times?years[]=2025&years[]=2026` (gabungan kedua tahun)

### Rentang waktu

Hanya transaksi dengan `status_message` = `Success` yang dihitung. Jam diambil dari `paid_time` transaksi (WIB, sesuai data Markaz).

| `key` | `label` | Jam |
|---|---|---|
| `dawn` | Dini Hari | 00:00 - 05:59 |
| `morning` | Pagi | 06:00 - 10:59 |
| `midday` | Siang | 11:00 - 14:59 |
| `afternoon` | Sore | 15:00 - 17:59 |
| `night` | Malam | 18:00 - 23:59 |
| `unknown` | Tidak diketahui | Transaksi tanpa `paid_time` |

- Kelima rentang jam **selalu dikirim** dengan urutan di atas, termasuk yang jumlahnya `0`, supaya warna pie selalu konsisten.
- `unknown` hanya muncul jika ada transaksi tanpa jam pembelian. Rentang ini tetap dihitung supaya total sama dengan KPI.
- `percentage` = `transactions` ÷ total transaksi × 100, dibulatkan 1 desimal. `0` jika total = 0. Karena pembulatan, jumlahnya bisa 99.9 atau 100.1.

### 200 OK

```json
{
    "data": {
        "filter": { "mode": "date_range", "date_from": "2026-09-01", "date_to": "2026-09-30", "platform": null, "platforms": [], "...": "lihat performance-filter.md" },
        "is_empty": false,
        "message": null,
        "totals": { "transactions": 9 },
        "time_ranges": [
            { "key": "dawn", "label": "Dini Hari", "start": "00:00", "end": "05:59", "transactions": 2, "percentage": 22.2 },
            { "key": "morning", "label": "Pagi", "start": "06:00", "end": "10:59", "transactions": 2, "percentage": 22.2 },
            { "key": "midday", "label": "Siang", "start": "11:00", "end": "14:59", "transactions": 1, "percentage": 11.1 },
            { "key": "afternoon", "label": "Sore", "start": "15:00", "end": "17:59", "transactions": 1, "percentage": 11.1 },
            { "key": "night", "label": "Malam", "start": "18:00", "end": "23:59", "transactions": 2, "percentage": 22.2 },
            { "key": "unknown", "label": "Tidak diketahui", "start": null, "end": null, "transactions": 1, "percentage": 11.1 }
        ],
        "labels": [
            "Dini Hari (00:00 - 05:59)",
            "Pagi (06:00 - 10:59)",
            "Siang (11:00 - 14:59)",
            "Sore (15:00 - 17:59)",
            "Malam (18:00 - 23:59)",
            "Tidak diketahui"
        ],
        "datasets": [
            { "label": "Transaksi", "data": [2, 2, 1, 1, 2, 1] }
        ]
    }
}
```

| Field | Dipakai untuk |
|---|---|
| `labels`, `datasets` | Langsung ke Chart.js: `new Chart(canvas, { type: 'pie', data: { labels, datasets } })`. Label sudah berisi rentang jam, jadi tooltip bawaan sudah menampilkan rentang jam dan jumlah transaksi. |
| `time_ranges` | Isi tooltip tambahan (misalnya `percentage`) atau legenda; urutannya sama dengan `labels` |
| `totals.transactions` | Total transaksi di card, sama dengan KPI Total Transaksi |

### Data kosong

Jika tidak ada transaksi `Success` di periode yang dipilih, respons tetap **200**, dengan `is_empty: true`, `message: "Data tidak tersedia untuk filter yang dipilih."`, dan kelima rentang bernilai `0`. Tampilkan `message` sebagai kondisi kosong, bukan pie chart.

### 422 Validasi gagal

Sama dengan [filter Ringkasan Performa](performance-filter.md#422-validasi-gagal), misalnya lebih dari satu mode periode, format tanggal salah, atau slug platform tidak ditemukan.
