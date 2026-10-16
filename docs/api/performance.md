# API Performa

Menyediakan total KPI performa untuk periode dan platform yang dipilih, beserta perbandingannya dengan periode sebelumnya. Semua rumus dihitung di backend, sehingga frontend cukup menampilkan nilainya.

Wajib login. Semua role (Manager dan Employee) boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md).

## `GET /api/performance/summary`

### Query parameter

Memakai [filter Ringkasan Performa](performance-filter.md): beberapa platform (`platforms[]`) dan satu mode periode (default bulan berjalan, rentang tanggal, bulan, beberapa tahun, atau rentang tahun). Tanpa parameter, data bulan berjalan sampai hari ini yang ditampilkan.

Contoh:

- `GET /api/performance/summary?date_from=2026-09-01&date_to=2026-09-30&platforms[]=app-a`
- `GET /api/performance/summary?years[]=2025&years[]=2026`

### Periode pembanding

Setiap mode filter punya periode pembanding sendiri, misalnya rentang tanggal dibandingkan dengan jumlah hari yang sama tepat sebelumnya, bulan dibandingkan dengan bulan sebelumnya, dan setiap tahun dibandingkan dengan tahun sebelumnya. Tabel lengkapnya ada di [filter Ringkasan Performa](performance-filter.md#perbandingan-periode-naik--turun--sama).

| Periode dipilih | Periode sebelumnya |
|---|---|
| 1-30 Sep 2026 (30 hari) | 2-31 Agu 2026 |
| 10 Sep 2026 (1 hari) | 9 Sep 2026 |
| Tahun 2025 + 2026 | Tahun 2024 + 2025 |

Jika beberapa tahun dipilih, setiap KPI adalah **gabungan** semua tahun tersebut.

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
        "filter": {
            "mode": "date_range",
            "date_from": "2026-09-01",
            "date_to": "2026-09-30",
            "platform": "app-a",
            "platforms": ["app-a"],
            "years": [],
            "month": null,
            "ranges": [{ "date_from": "2026-09-01", "date_to": "2026-09-30" }]
        },
        "previous_period": {
            "date_from": "2026-08-02",
            "date_to": "2026-08-31",
            "ranges": [{ "date_from": "2026-08-02", "date_to": "2026-08-31" }]
        },
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

Lihat [filter Ringkasan Performa](performance-filter.md#422-validasi-gagal), misalnya lebih dari satu mode periode, format tanggal salah, atau slug platform tidak ditemukan.

## `GET /api/performance/trend`

Data deret waktu untuk grafik dan tabel Analisa Tren & Performa. Filter-nya sama dengan Ringkasan Performa ([filter Ringkasan Performa](performance-filter.md)). Wajib login, dan semua role boleh mengakses.

Backend mengirim data yang sama untuk semua bentuk visualisasi (Bar, Line, Area, Pie, Table). Pilihan bentuk visualisasi diatur di frontend lewat dropdown, tanpa request ulang ke backend.

### Query parameter

| Parameter | Wajib | Nilai |
|---|---|---|
| Platform dan periode | Tidak | Sama seperti `/summary`, lihat [filter Ringkasan Performa](performance-filter.md) |
| `metric` | Ya | `revenue`, `transactions`, `downloaders`, `conversion`, `new_users`, `repeat_orders` |
| `period` | Tidak | `daily`, `weekly`, `monthly`. Jika kosong: `daily` untuk default, rentang tanggal, dan bulan; `monthly` untuk tahun dan rentang tahun. |

Contoh:

- `GET /api/performance/trend?date_from=2026-09-01&date_to=2026-09-30&platforms[]=app-a&metric=revenue&period=weekly`
- `GET /api/performance/trend?year=2026&month=9&metric=revenue` (rincian harian)
- `GET /api/performance/trend?years[]=2025&years[]=2026&metric=revenue` (perbandingan per bulan)

### Pembagian periode

| Period | Satu titik data | Label | Batas rentang |
|---|---|---|---|
| `daily` | 1 tanggal | `01 Sep 2026` | Maksimal 366 hari |
| `weekly` | Senin - Minggu | `01 - 06 Sep 2026`, `31 Aug - 03 Sep 2026` | Maksimal 366 hari |
| `monthly` | 1 bulan kalender | `Sep 2026` | Tanpa batas |

- Minggu dan bulan pertama atau terakhir **dipotong mengikuti rentang filter**. Contohnya, filter mulai Selasa 1 Sep menghasilkan minggu pertama 1 - 6 Sep.
- Periode tanpa data tetap muncul dengan nilai `0`, supaya grafik tidak bolong.
- Periode yang **dimulai setelah hari ini** (misalnya sisa hari di bulan berjalan, atau Nov - Des di tahun berjalan) bernilai `null`, begitu juga `change_percentage` dan `trend`-nya. Datanya memang belum ada, jadi grafik tidak turun ke 0. Hari ini sendiri tetap punya nilai.
- Tanggal mengikuti timezone aplikasi `Asia/Jakarta`.
- Batas 366 hari dihitung dari jumlah hari semua tahun yang dipilih. Contohnya, `year_from=2024&year_to=2026` hanya bisa memakai `monthly`.

### Perbandingan beberapa tahun

Jika lebih dari satu tahun dipilih lewat `years[]`, periodenya selalu `monthly`, dan grafik membandingkan tahun-tahun tersebut bulan per bulan:

- `labels` berisi nama bulan: `["Jan", "Feb", ..., "Dec"]`.
- `datasets` berisi **satu dataset per tahun**, dengan `label` berupa tahunnya.
- `rows` tetap berurutan per bulan dari tahun pertama sampai tahun terakhir (`Jan 2025` ... `Dec 2026`).
- `change_percentage` dan `trend` setiap baris dibandingkan dengan **bulan yang sama di tahun pilihan sebelumnya**. Contohnya, dengan `years[]=2024&years[]=2026`, Mar 2026 dibandingkan dengan Mar 2024. Baris di tahun pertama bernilai `null` karena tidak punya pembanding.

```json
{
    "data": {
        "period": "monthly",
        "labels": ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
        "datasets": [
            { "label": "2025", "data": [0, 0, 200000, 0, 0, 0, 50000, 0, 0, 0, 0, 0] },
            { "label": "2026", "data": [0, 0, 400000, 0, 0, 0, 0, 0, 470000, 30000, null, null] }
        ],
        "rows": [
            { "label": "Mar 2025", "period_start": "2025-03-01", "period_end": "2025-03-31", "value": 200000, "change_percentage": null, "trend": null },
            { "label": "Mar 2026", "period_start": "2026-03-01", "period_end": "2026-03-31", "value": 400000, "change_percentage": 100, "trend": "up" },
            { "label": "Nov 2026", "period_start": "2026-11-01", "period_end": "2026-11-30", "value": null, "change_percentage": null, "trend": null }
        ]
    }
}
```

Contoh `rows` di atas hanya sebagian. Respons asli berisi 24 baris.

Jika hanya satu tahun dipilih, bentuknya sama seperti biasa: label `Jan 2026` ... `Dec 2026` dengan satu dataset.

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
        "filter": { "mode": "date_range", "date_from": "2026-09-01", "date_to": "2026-09-30", "platform": "app-a", "platforms": ["app-a"], "...": "lihat performance-filter.md" },
        "metric": { "key": "revenue", "label": "Revenue", "unit": "currency" },
        "period": "weekly",
        "is_empty": false,
        "message": null,
        "labels": ["01 - 06 Sep 2026", "07 - 13 Sep 2026", "14 - 20 Sep 2026", "21 - 27 Sep 2026", "28 - 30 Sep 2026"],
        "datasets": [
            { "label": "Revenue", "data": [300000, 300000, 50000, 0, 400000] }
        ],
        "rows": [
            { "label": "01 - 06 Sep 2026", "period_start": "2026-09-01", "period_end": "2026-09-06", "value": 300000, "change_percentage": null, "trend": null },
            { "label": "07 - 13 Sep 2026", "period_start": "2026-09-07", "period_end": "2026-09-13", "value": 300000, "change_percentage": 0, "trend": "same" },
            { "label": "14 - 20 Sep 2026", "period_start": "2026-09-14", "period_end": "2026-09-20", "value": 50000, "change_percentage": -83.33, "trend": "down" }
        ]
    }
}
```

| Field | Dipakai untuk |
|---|---|
| `labels`, `datasets` | Langsung ke Chart.js: `new Chart(canvas, { type, data: { labels, datasets } })` |
| `rows` | Isi tabel (termasuk kolom Perubahan), dan tooltip saat hover (label periode, rentang tanggal, nilai) |
| `metric.unit` | Format angka: `currency` → Rupiah, `percent` → tambah `%`, `count` → angka biasa |

### Field setiap item `rows`

| Field | Keterangan |
|---|---|
| `label` | Label periode, sama dengan `labels` |
| `period_start`, `period_end` | Rentang tanggal periode itu (`YYYY-MM-DD`) |
| `value` | Nilai metric di periode itu, sama dengan `datasets[0].data` (atau dataset tahunnya saat membandingkan beberapa tahun) |
| `change_percentage` | `(value - value baris sebelumnya) ÷ value baris sebelumnya × 100`, dibulatkan 2 desimal. `null` untuk baris pertama, untuk periode setelah hari ini, atau jika nilai baris sebelumnya `0`/`null`. Saat membandingkan beberapa tahun, pembandingnya adalah bulan yang sama di tahun pilihan sebelumnya. |
| `trend` | `up`, `down`, atau `same` dibanding baris sebelumnya (atau bulan yang sama di tahun sebelumnya); nilai `null` dihitung sebagai `0`. `null` untuk baris pertama dan periode setelah hari ini. |

Aturan `change_percentage` dan `trend` sama persis dengan perbandingan periode di `/summary`.

Jika rentang tidak punya data sama sekali, respons tetap **200** dengan `is_empty: true` dan `message: "Data tidak tersedia untuk filter yang dipilih."`.

### 422 Validasi gagal

Selain validasi [filter Ringkasan Performa](performance-filter.md#422-validasi-gagal):

| Kondisi | Pesan |
|---|---|
| `metric` kosong | `Metric wajib diisi.` |
| Nilai di luar pilihan | `Metric tidak valid.` / `Periode tidak valid.` |
| Rentang lebih dari 366 hari untuk `daily` atau `weekly` | `Rentang tanggal untuk periode daily maksimal 366 hari.` |
| Beberapa tahun dipilih dengan `period` selain `monthly` | `Perbandingan beberapa tahun hanya mendukung periode monthly.` |
