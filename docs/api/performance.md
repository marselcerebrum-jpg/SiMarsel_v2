# API Performa

Menyediakan total KPI performa untuk periode dan platform yang dipilih, beserta perbandingannya dengan periode sebelumnya. Semua rumus dihitung di backend, sehingga frontend cukup menampilkan nilainya.

Wajib login. Semua role (Manager dan Employee) boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md).

## `GET /api/performance/summary`

### Query parameter

| Parameter | Wajib | Format | Keterangan |
|---|---|---|---|
| `date_from` | Ya | `YYYY-MM-DD` | Tanggal mulai (inklusif) |
| `date_to` | Ya | `YYYY-MM-DD` | Tanggal akhir (inklusif), tidak boleh sebelum `date_from` |
| `platform` | Tidak | slug platform | Kosongkan untuk menghitung semua platform |

Contoh: `GET /api/performance/summary?date_from=2026-09-01&date_to=2026-09-30&platform=app-a`

### Periode pembanding

Periode sebelumnya memiliki jumlah hari yang sama dan berakhir tepat sehari sebelum `date_from`.

| Periode dipilih | Periode sebelumnya |
|---|---|
| 1-30 Sep 2026 (30 hari) | 2-31 Agu 2026 |
| 10 Sep 2026 (1 hari) | 9 Sep 2026 |

### Rumus KPI

Semua KPI hanya memakai transaksi dengan `status_message` = `Success`.

| Key | KPI | Sumber | Rumus |
|---|---|---|---|
| `total_revenue` | Total Revenue | `transactions` | Jumlah `nominal` |
| `total_transactions` | Total Transaksi | `transactions` | Jumlah transaksi |
| `total_downloaders` | Total Downloader | `downloaders` | Jumlah `total` |
| `unique_users` | User Unik | `transactions` | Jumlah email berbeda. Email disamakan huruf kecilnya dan spasinya dibuang, jadi `Alice@x.com` dan ` alice@x.com ` dihitung satu user. Transaksi tanpa email tidak dihitung. |
| `new_users` | User Baru | `transactions` | Jumlah transaksi berhasil dengan `product_type` = `Perdana` |
| `repeat_orders` | Repeat Order | `transactions` | Jumlah transaksi berhasil dengan `product_type` = `Perpanjangan` |
| `average_order_value` | AOV | `transactions` | `total_revenue ÷ total_transactions`, dibulatkan 2 desimal. `null` jika tidak ada transaksi. |
| `conversion_rate` | Konversi (%) | keduanya | `total_transactions ÷ total_downloaders × 100`, dibulatkan 2 desimal. `null` jika downloader = 0. |

`new_users` dan `repeat_orders` menghitung **transaksi**, bukan orang. Satu email yang melakukan dua kali `Perpanjangan` dihitung 2.

> **Perubahan untuk frontend:** key `repeat_order_users` diganti menjadi `repeat_orders`.

### Bentuk setiap KPI

| Field | Keterangan |
|---|---|
| `value` | Nilai di periode yang dipilih |
| `previous_value` | Nilai di periode sebelumnya |
| `change_percentage` | `(value - previous_value) ÷ previous_value × 100`, dibulatkan 2 desimal. `null` jika `previous_value` = 0 atau `null`. |
| `trend` | `up` (naik), `down` (turun), atau `same` (sama). Nilai `null` dibandingkan sebagai 0. |

### 200 OK

```json
{
    "data": {
        "filter": { "date_from": "2026-09-01", "date_to": "2026-09-30", "platform": "app-a" },
        "previous_period": { "date_from": "2026-08-02", "date_to": "2026-08-31" },
        "is_empty": false,
        "message": null,
        "kpis": {
            "total_revenue": { "value": 1000000, "previous_value": 500000, "change_percentage": 100, "trend": "up" },
            "total_transactions": { "value": 4, "previous_value": 1, "change_percentage": 300, "trend": "up" },
            "total_downloaders": { "value": 100, "previous_value": 50, "change_percentage": 100, "trend": "up" },
            "unique_users": { "value": 2, "previous_value": 1, "change_percentage": 100, "trend": "up" },
            "new_users": { "value": 2, "previous_value": 1, "change_percentage": 100, "trend": "up" },
            "repeat_orders": { "value": 2, "previous_value": 0, "change_percentage": null, "trend": "up" },
            "average_order_value": { "value": 250000, "previous_value": 500000, "change_percentage": -50, "trend": "down" },
            "conversion_rate": { "value": 4, "previous_value": 2, "change_percentage": 100, "trend": "up" }
        }
    }
}
```

### Data kosong

Jika periode yang dipilih tidak punya transaksi berhasil dan tidak punya data downloader, respons tetap **200**, dengan:

```json
{
    "data": {
        "is_empty": true,
        "message": "Data tidak tersedia untuk filter yang dipilih.",
        "kpis": { "...": "nilai 0, atau null untuk average_order_value dan conversion_rate" }
    }
}
```

Tampilkan `message` sebagai kondisi kosong di halaman.

### 422 Validasi gagal

| Kondisi | Pesan |
|---|---|
| `date_from` atau `date_to` kosong | `Tanggal mulai wajib diisi.` / `Tanggal akhir wajib diisi.` |
| Format tanggal salah | `Tanggal mulai harus berformat YYYY-MM-DD.` |
| `date_to` sebelum `date_from` | `Tanggal akhir tidak boleh sebelum tanggal mulai.` |
| Slug platform tidak ada | `Platform tidak ditemukan.` |

## `GET /api/performance/trend`

Data deret waktu untuk grafik dan tabel Analisa Tren & Performa. Filter-nya sama dengan Ringkasan Performa. Wajib login, dan semua role boleh mengakses.

Backend mengirim data yang sama untuk semua bentuk visualisasi (Bar, Line, Area, Pie, Table). Pilihan bentuk visualisasi diatur di frontend lewat dropdown, tanpa request ulang ke backend.

### Query parameter

| Parameter | Wajib | Nilai |
|---|---|---|
| `date_from`, `date_to`, `platform` | Sama seperti `/summary` | |
| `metric` | Ya | `revenue`, `transactions`, `downloaders`, `conversion`, `new_users`, `repeat_orders` |
| `period` | Ya | `daily`, `weekly`, `monthly` |

Contoh: `GET /api/performance/trend?date_from=2026-09-01&date_to=2026-09-30&platform=app-a&metric=revenue&period=weekly`

### Pembagian periode

| Period | Satu titik data | Label | Batas rentang |
|---|---|---|---|
| `daily` | 1 tanggal | `01 Sep 2026` | Maksimal 366 hari |
| `weekly` | Senin - Minggu | `01 - 06 Sep 2026`, `31 Aug - 03 Sep 2026` | Maksimal 366 hari |
| `monthly` | 1 bulan kalender | `Sep 2026` | Tanpa batas |

- Minggu dan bulan pertama atau terakhir **dipotong mengikuti rentang filter**. Contohnya, filter mulai Selasa 1 Sep menghasilkan minggu pertama 1 - 6 Sep.
- Periode tanpa data tetap muncul dengan nilai `0`, supaya grafik tidak bolong.
- Tanggal mengikuti timezone aplikasi `Asia/Jakarta`.

### Rumus per titik data

Rumusnya sama dengan Ringkasan Performa, tapi dihitung terpisah untuk setiap periode.

| `metric` | Label | `unit` | Nilai per periode |
|---|---|---|---|
| `revenue` | Revenue | `currency` | Jumlah `nominal` transaksi `Success` |
| `transactions` | Transactions | `count` | Jumlah transaksi `Success` |
| `downloaders` | Downloader | `count` | Jumlah `downloaders.total` |
| `conversion` | Conversion | `percent` | Transaksi `Success` ÷ downloader × 100. `null` jika periode itu tidak punya downloader. |
| `new_users` | User Baru | `count` | Jumlah transaksi `Success` dengan `product_type` = `Perdana` |
| `repeat_orders` | Repeat Order | `count` | Jumlah transaksi `Success` dengan `product_type` = `Perpanjangan` |

Untuk `revenue`, `transactions`, `downloaders`, `new_users`, dan `repeat_orders`, jumlah semua titik sama dengan angka di `/summary`. `conversion` adalah persentase, jadi tidak dijumlahkan: nilainya dihitung ulang dari transaksi dan downloader di setiap periode.

### 200 OK

```json
{
    "data": {
        "filter": { "date_from": "2026-09-01", "date_to": "2026-09-30", "platform": "app-a" },
        "metric": { "key": "revenue", "label": "Revenue", "unit": "currency" },
        "period": "weekly",
        "is_empty": false,
        "message": null,
        "labels": ["01 - 06 Sep 2026", "07 - 13 Sep 2026", "14 - 20 Sep 2026", "21 - 27 Sep 2026", "28 - 30 Sep 2026"],
        "datasets": [
            { "label": "Revenue", "data": [300000, 300000, 50000, 0, 400000] }
        ],
        "rows": [
            { "label": "01 - 06 Sep 2026", "period_start": "2026-09-01", "period_end": "2026-09-06", "value": 300000 }
        ]
    }
}
```

| Field | Dipakai untuk |
|---|---|
| `labels`, `datasets` | Langsung ke Chart.js: `new Chart(canvas, { type, data: { labels, datasets } })` |
| `rows` | Isi tabel, dan tooltip saat hover (label periode, rentang tanggal, nilai) |
| `metric.unit` | Format angka: `currency` → Rupiah, `percent` → tambah `%`, `count` → angka biasa |

Jika rentang tidak punya data sama sekali, respons tetap **200** dengan `is_empty: true` dan `message: "Data tidak tersedia untuk filter yang dipilih."`.

### 422 Validasi gagal

Selain validasi filter di `/summary`:

| Kondisi | Pesan |
|---|---|
| `metric` atau `period` kosong | `Metric wajib diisi.` / `Periode wajib diisi.` |
| Nilai di luar pilihan | `Metric tidak valid.` / `Periode tidak valid.` |
| Rentang lebih dari 366 hari untuk `daily` atau `weekly` | `Rentang tanggal untuk periode daily maksimal 366 hari.` |
