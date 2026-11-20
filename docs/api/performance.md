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

Setiap mode filter punya periode pembanding sendiri, misalnya rentang tanggal dibandingkan dengan jumlah hari yang sama tepat sebelumnya, bulan (termasuk default bulan berjalan) dibandingkan dengan seluruh bulan sebelumnya walaupun belum selesai, dan setiap tahun dibandingkan dengan tahun sebelumnya. Tabel lengkapnya ada di [filter Ringkasan Performa](performance-filter.md#perbandingan-periode-naik--turun--sama).

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
| `period=hourly` | `Periode tidak valid.` Tren metrik ini hanya membaca total harian; tren per jam ada di `/trend/sales`. |

## `GET /api/performance/trend/sales`

Dataset grafik gabungan Omzet dan Transaksi (FIX-06). Satu titik berisi sisi aktif dan sisi pembanding, masing-masing dengan waktu aslinya. Wajib login, dan semua role boleh mengakses. Ekspor Laporan Dashboard memakai dataset dan `granularity` yang sama.

### Query parameter

| Parameter | Wajib | Nilai |
|---|---|---|
| `preset` dan parameter periode | Ya | Sama seperti resolver periode, lihat [Kontrak Periode](../period-resolver.md#parameter). Tren ini selalu memakai resolver |
| `platforms[]`, `platform` | Tidak | Slug platform. Kosong berarti semua platform |
| `granularity` | Tidak | `hourly`, `daily`, `weekly`, `monthly`. Jika kosong, memakai default di bawah |

Contoh: `GET /api/performance/trend/sales?preset=today&platforms[]=app-a`

### Pengelompokan

| Preset | Default |
|---|---|
| `today`, `yesterday` | `hourly` |
| `last_7_days`, `last_30_days` | `daily` |
| `last_90_days` | `weekly` |
| `last_year` | `monthly` |
| `this_month`, `last_month`, `date_range` | 1 hari `hourly`, sampai 31 hari `daily`, sampai 92 hari `weekly`, lebih lama `monthly` |

| `granularity` | Rentang maksimal | Bucket |
|---|---|---|
| `hourly` | 31 hari | Satu jam WIB, label `11 Nov 2026 14.00` |
| `daily` | 366 hari | Satu hari, label `11 Nov 2026` |
| `weekly` | 366 hari | Senin–Minggu, dipotong ke rentang, label `04 - 08 Nov 2026` |
| `monthly` | Tanpa batas | Bulan kalender, dipotong ke rentang, label `Nov 2026` |

`granularities` di response berisi pilihan yang boleh dipakai untuk rentang aktif.

Bucket pembanding dipasangkan per posisi. Bulan dipasangkan dengan bulan kalender pembanding. Pengelompokan lain mengambil panjang yang sama (jam atau hari) dari awal periode pembanding, sehingga minggu pertama yang terpotong 4 hari dibandingkan dengan 4 hari. Posisi yang tidak terjangkau periode pembanding bernilai `comparison: null`.

### Nilai per bucket

| Nilai | Dasar tanggal | Isi |
|---|---|---|
| `revenue` (Omzet) | Tanggal bayar valid terakhir | Jumlah `net_nominal` pembelian Success, tanpa klaim |
| `success_payments` (Transaksi Berhasil) | Tanggal bayar valid terakhir | Jumlah pembelian Success, tanpa klaim |
| `total_checkouts` (Total Transaksi) | `markaz_created_at` | Semua checkout termasuk klaim kupon dan tanpa status |

Pembelian grup dihitung sekali dari baris pembeli. Rumusnya memakai agregasi bersama FIX-02-BE-02 (`TransactionAggregationService`), sehingga jumlah semua bucket satu seri sama dengan agregat periode pada dasar tanggal yang sama (`totals`).

### Kelengkapan bucket

| Keadaan | `availability` | `as_of` | `values` |
|---|---|---|---|
| Bucket lengkap | `complete` | Akhir bucket | Angka. Tanpa transaksi bernilai `0` |
| Batas lengkap jatuh di dalam bucket | `partial` | Batas lengkap | Angka sampai batas itu |
| Bucket mulai pada atau setelah batas lengkap, atau cakupan belum diketahui | `unavailable` | `null` | `null`, bukan `0` |
| Pembanding membutuhkan catatan yang tidak ada atau belum lengkap | `unavailable` | `null` | `null` (titik pembanding kosong) |

### Sumber pembanding (`comparison.source`)

| Keadaan periode pembanding | Sumber per bucket |
|---|---|
| `state_basis = latest` (Kemarin, 7/30/90 Hari Terakhir, Bulan Lalu) | `latest`: status dan tanggal bayar terbaru |
| `state_basis = at_cutoff` (Hari Ini, Bulan Ini, Tahun Lalu, rentang yang berakhir hari ini) | Hari penuh sebelum hari batas: `latest`. Jam pada hari batas: `snapshot` |

Bucket `snapshot` dihitung dari catatan `dashboard_snapshots` satu hari (`date_from = date_to` = hari batas) yang lengkap di semua platform terpilih: nilai catatan pada akhir bucket dikurangi nilai catatan pada awal bucket (awal hari = 0). Contoh: pembanding 13.00–14.00 = catatan 14.00 dikurangi catatan 13.00. Payload catatan harus memuat `revenue`, `success_payments`, dan `total_checkouts`. Perubahan status atau tanggal bayar setelahnya hanya mengubah sisi aktif, tidak pernah mengubah bucket `snapshot`.

### 200 OK

```json
{
    "data": {
        "filter": { "mode": "today", "period": { "active": { "availability": "partial", "cutoff_at": "2026-11-11T14:00:00+07:00" } } },
        "granularity": "hourly",
        "default_granularity": "hourly",
        "granularities": ["hourly", "daily", "weekly", "monthly"],
        "series": [
            { "key": "revenue", "label": "Omzet", "side": "active", "value": "revenue", "unit": "currency", "axis": "currency", "date_basis": "paid", "visible": true },
            { "key": "comparison_revenue", "label": "Omzet Pembanding", "side": "comparison", "value": "revenue", "unit": "currency", "axis": "currency", "date_basis": "paid", "visible": true },
            { "key": "success_payments", "label": "Transaksi Berhasil", "side": "active", "value": "success_payments", "unit": "count", "axis": "count", "date_basis": "paid", "visible": true },
            { "key": "total_checkouts", "label": "Total Transaksi", "side": "active", "value": "total_checkouts", "unit": "count", "axis": "count", "date_basis": "created", "visible": false }
        ],
        "is_empty": false,
        "message": null,
        "totals": { "availability": "partial", "as_of": "2026-11-11T14:00:00+07:00", "values": { "revenue": 150000, "success_payments": 1, "total_checkouts": 1 } },
        "buckets": [
            {
                "index": 13,
                "active": {
                    "start_at": "2026-11-11T13:00:00+07:00", "end_at": "2026-11-11T14:00:00+07:00", "label": "11 Nov 2026 13.00",
                    "availability": "complete", "as_of": "2026-11-11T14:00:00+07:00", "source": "latest",
                    "values": { "revenue": 0, "success_payments": 0, "total_checkouts": 0 }
                },
                "comparison": {
                    "start_at": "2026-11-10T13:00:00+07:00", "end_at": "2026-11-10T14:00:00+07:00", "label": "10 Nov 2026 13.00",
                    "availability": "complete", "as_of": "2026-11-10T14:00:00+07:00", "source": "snapshot",
                    "values": { "revenue": 150000, "success_payments": 1, "total_checkouts": 2 }
                }
            },
            {
                "index": 14,
                "active": {
                    "start_at": "2026-11-11T14:00:00+07:00", "end_at": "2026-11-11T15:00:00+07:00", "label": "11 Nov 2026 14.00",
                    "availability": "unavailable", "as_of": null, "source": "latest", "values": null
                },
                "comparison": {
                    "start_at": "2026-11-10T14:00:00+07:00", "end_at": "2026-11-10T15:00:00+07:00", "label": "10 Nov 2026 14.00",
                    "availability": "unavailable", "as_of": null, "source": null, "values": null
                }
            }
        ]
    }
}
```

`series` menjelaskan seri awal grafik: `side` dan `value` menunjuk nilai di setiap bucket, `axis` memisahkan sumbu Rupiah dan Jumlah Transaksi, dan `visible = false` berarti seri baru tampil setelah diaktifkan dari legenda.

### 422 Validasi gagal

| Kondisi | Field | Pesan |
|---|---|---|
| `preset` kosong | `preset` | `Preset periode wajib diisi.` |
| Nilai di luar pilihan | `granularity` | `Pengelompokan tidak valid.` |
| Rentang lebih panjang dari batas pengelompokan | `granularity` | `Rentang tanggal untuk pengelompokan hourly maksimal 31 hari.` |

### Batasan

- Baris `transactions` tanpa `markaz_transaction_id` atau `markaz_created_at` (impor lama sebelum FIX-15) tidak dapat dibaca kontrak tanggal dan tidak dihitung.
- Batas `hourly` 31 hari dipilih agar satu grafik tidak lebih dari 744 titik. Implementasi lama mendukung `daily`/`weekly` sampai 366 hari dan `monthly` tanpa batas; batas itu dipertahankan.
