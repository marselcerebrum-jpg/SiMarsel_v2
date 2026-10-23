# API Analisis Promo

Filter analisis, kartu KPI ringkasan, rekap per funnel, per source, dan per kode promo, serta detail penggunaan kode untuk halaman Analisis Promo. Semua komponen analisis promo memakai **filter yang sama**, jadi kirim query parameter yang sama ke setiap endpoint.

Wajib login. **Semua user yang login** boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md). Cara penggunaan kode promo disimpan ada di [promo-codes.md](../promo-codes.md).

| Endpoint | Untuk |
|---|---|
| `GET /api/promo-analysis/filters` | Pilihan dropdown filter |
| `GET /api/promo-analysis/summary` | Kartu KPI |
| `GET /api/promo-analysis/funnels` | Tabel transaksi dan revenue per funnel |
| `GET /api/promo-analysis/sources` | Tabel penggunaan dan revenue per source |
| `GET /api/promo-analysis/codes` | Tabel rekap per kode promo |
| `GET /api/promo-analysis/codes/usages` | Detail penggunaan satu kode (saat baris rekap diklik) |

## Sumber data

- Tabel `transactions`, hanya transaksi dengan `status_message` = `Success`, sama seperti Ringkasan Performa.
- Periode memakai `paid_date` Markaz.
- Penggunaan kode dari `transaction_promo_codes`, termasuk kode yang **belum terdaftar** di master.
- Semua KPI dihitung **per transaksi unik** (satu baris `transactions`), bukan dari penjumlahan baris penggunaan kode. Transaksi dengan dua kode tetap dihitung satu kali, dan nominalnya dijumlahkan satu kali.

## `GET /api/promo-analysis/filters`

```json
{
    "data": {
        "platforms": [
            { "id": 1, "slug": "cerebrum", "name": "CEREBRUM" },
            { "id": 2, "slug": "jadiasn", "name": "JADIASN" }
        ],
        "payment_methods": ["BCA", "GOPAY", "QRIS", "Tidak diketahui"]
    }
}
```

- `platforms`: daftar Platform global, sama dengan `GET /api/platforms`. Kirim `slug`-nya ke filter.
- `payment_methods`: metode pembayaran dari transaksi sukses, A-Z. Metode yang hanya beda huruf besar/kecil atau spasi digabung jadi satu. Transaksi tanpa metode pembayaran muncul sebagai `Tidak diketahui`.

## Filter

| Parameter | Format | Keterangan |
|---|---|---|
| `platforms[]` | slug platform, boleh lebih dari satu | Kosongkan untuk semua platform. `platform` (satu slug) juga diterima. |
| `payment_methods[]` | nama dari `filters`, boleh lebih dari satu | Tidak membedakan huruf besar/kecil dan spasi. `Tidak diketahui` = transaksi tanpa metode pembayaran. Kosongkan untuk semua metode. |
| `date_from`, `date_to` | `YYYY-MM-DD` | Mode **Rentang Tanggal** |
| `year`, `month` | angka, bulan 1-12 | Mode **Bulan & Tahun** |
| `search` | teks, maks. 100 karakter | Pencarian kode promo (lihat di bawah) |

### Periode

Pilih **salah satu** mode. Tanpa parameter periode = bulan berjalan; ini juga kondisi setelah **Reset**.

| Mode (`filter.mode`) | Data yang dihitung | Dibandingkan dengan |
|---|---|---|
| `current_month` (default) | Tanggal 1 bulan ini sampai hari ini | Seluruh bulan sebelumnya |
| `date_range` | `date_from` sampai `date_to` | Jumlah hari yang sama tepat sebelumnya |
| `month` | Satu bulan penuh | Bulan sebelumnya |

Contoh: `date_from=2026-09-05&date_to=2026-09-12` (8 hari) dibandingkan dengan 28 Agu - 4 Sep 2026; `year=2026&month=9` dibandingkan dengan Agustus 2026. Rinciannya sama dengan [filter Ringkasan Performa](performance-filter.md#perbandingan-periode-naik--turun--sama).

Mode tahun (`years[]`, `year_from`, `year_to`) **tidak tersedia** di Analisis Promo dan ditolak dengan 422.

### Pencarian kode promo

- Cocok sebagian dan tidak membedakan huruf besar/kecil, di kode yang dinormalisasi maupun nilai asli dari Markaz. Kode yang belum terdaftar juga ditemukan.
- Pencarian **mempersempit transaksi**: hanya transaksi yang memakai kode yang cocok yang dihitung. Akibatnya, saat `search` aktif, **Transaksi/Revenue Tanpa Promo selalu 0** dan persentase pakai promo **100%** (jika ada data).
- Kode berbeda yang dihitung hanya kode yang cocok dengan pencarian. Misalnya `search=cpns` pada transaksi `CPNS26` + `RESELLER01` hanya menghitung `CPNS26`.

## `GET /api/promo-analysis/summary`

Contoh: `GET /api/promo-analysis/summary?year=2026&month=9&platforms[]=jadiasn&payment_methods[]=BCA`

### Rumus KPI

| KPI | Kartu | Rumus |
|---|---|---|
| `promo_transactions` | Total Transaksi dengan Promo | Transaksi unik yang punya minimal satu penggunaan kode promo |
| `non_promo_transactions` | Total Transaksi Tanpa Promo | Transaksi unik tanpa penggunaan kode promo |
| `promo_revenue` | Revenue dengan Promo | Jumlah `nominal` transaksi dengan promo, satu kali per transaksi |
| `non_promo_revenue` | Revenue tanpa Promo | Jumlah `nominal` transaksi tanpa promo |
| `promo_transaction_percentage` | Persentase Transaksi Pakai Promo | `promo_transactions` ÷ seluruh transaksi unik hasil filter × 100, dibulatkan 2 desimal. `null` jika tidak ada transaksi. |
| `used_promo_codes` | Kode Promo Digunakan | Jumlah kode berbeda (platform + kode yang dinormalisasi) yang dipakai transaksi hasil filter, **termasuk kode belum terdaftar**. Bukan "kode aktif": tidak ada status aktif/nonaktif. |

Kode yang sama di platform lain dihitung sebagai kode berbeda, sesuai aturan master kode promo.

Transaksi dengan **lebih dari 2 kode** adalah anomali: tidak dibuatkan baris penggunaan (lihat [promo-codes.md](../promo-codes.md)), sehingga terhitung sebagai transaksi tanpa promo. Transaksi ini tercatat di `storage/logs/promo-code-anomalies.log`.

### Naik / turun

Setiap KPI berisi nilai periode sekarang dan periode pembanding:

| Field | Keterangan |
|---|---|
| `value` | Nilai periode sekarang |
| `previous_value` | Nilai periode pembanding, dengan filter platform, metode pembayaran, dan pencarian yang sama |
| `change_percentage` | `(value - previous_value) ÷ previous_value × 100`, dibulatkan 2 desimal. `null` jika `previous_value` 0 atau `null`. |
| `trend` | `up`, `down`, atau `same` |

### 200 OK

`GET /api/promo-analysis/summary?year=2026&month=9`:

```json
{
    "data": {
        "filter": {
            "mode": "month",
            "date_from": "2026-09-01",
            "date_to": "2026-09-30",
            "platform": null,
            "platforms": [],
            "years": [2026],
            "month": 9,
            "ranges": [{ "date_from": "2026-09-01", "date_to": "2026-09-30" }],
            "payment_methods": [],
            "search": null
        },
        "previous_period": {
            "date_from": "2026-08-01",
            "date_to": "2026-08-31",
            "ranges": [{ "date_from": "2026-08-01", "date_to": "2026-08-31" }]
        },
        "is_empty": false,
        "message": null,
        "kpis": {
            "promo_transactions": { "value": 3, "previous_value": 1, "change_percentage": 200, "trend": "up" },
            "non_promo_transactions": { "value": 2, "previous_value": 1, "change_percentage": 100, "trend": "up" },
            "promo_revenue": { "value": 700000, "previous_value": 150000, "change_percentage": 366.67, "trend": "up" },
            "non_promo_revenue": { "value": 800000, "previous_value": 50000, "change_percentage": 1500, "trend": "up" },
            "promo_transaction_percentage": { "value": 60, "previous_value": 50, "change_percentage": 20, "trend": "up" },
            "used_promo_codes": { "value": 3, "previous_value": 1, "change_percentage": 200, "trend": "up" }
        }
    }
}
```

Jika tidak ada transaksi untuk filter itu, respons tetap **200** dengan `is_empty: true` dan `message: "Data tidak tersedia untuk filter yang dipilih."`.

## `GET /api/promo-analysis/funnels`

Jumlah transaksi dan revenue menurut **funnel utama** setiap transaksi. Endpoint ini menerima semua filter di atas.

### Kelompok funnel

Setiap transaksi masuk **tepat ke satu** kelompok:

| Kondisi transaksi | Kelompok |
|---|---|
| Tanpa kode promo | **Tanpa Kode** (`no_code`) |
| Kode utama punya funnel | Funnel kode utama (`funnel`) |
| Kode utama punya funnel yang sudah dihapus dari master | Baris tersendiri dengan nama funnel saat transaksi (`deleted_funnel`) |
| Kode utama tanpa funnel, termasuk kode belum terdaftar | **Tanpa Funnel** (`no_funnel`) |

- **Kode utama** = kode posisi 1. Untuk dua kode, funnel **tetap** mengikuti kode posisi 1, walaupun kode posisi 2 (misalnya reseller) punya funnel.
- Kode reseller yang **berdiri sendiri** menentukan funnel transaksinya, termasuk jika Markaz mengirim posisi 1 kosong (`["", "RESELLER01"]`).
- Funnel diambil dari **snapshot** pada penggunaan kode, yaitu funnel yang berlaku pada waktu bayar transaksi, bukan funnel kode sekarang.
- Nama funnel memakai nama master **sekarang**, jadi funnel yang di-rename tetap satu baris.
- Transaksi anomali (lebih dari 2 kode) tidak punya baris penggunaan, sehingga masuk **Tanpa Kode**, konsisten dengan KPI.

### Rumus

| Field | Rumus |
|---|---|
| `transactions` | Jumlah transaksi unik di kelompok itu, bukan jumlah penggunaan kode |
| `revenue` | Jumlah **seluruh** `nominal` transaksi di kelompok itu, termasuk transaksi dua-kode |
| `contribution_percentage` | `revenue` ÷ total revenue seluruh transaksi hasil filter × 100, dibulatkan 2 desimal. `0` jika total = 0. |
| `totals.transactions`, `totals.revenue` | Seluruh transaksi hasil filter. Selalu sama dengan jumlah semua baris, dan sama dengan transaksi/revenue promo + tanpa promo di `/summary`. |

Karena pembulatan, jumlah semua persentase bisa 99.99 atau 100.01.

### Urutan baris

Semua funnel master **selalu tampil**, termasuk yang 0, urut sesuai master (Aplikasi, Marketing, Sales, Ads, Live, Lainnya, lalu funnel baru). Setelah itu funnel yang sudah dihapus (A-Z), **Tanpa Funnel**, dan **Tanpa Kode**.

### 200 OK

`GET /api/promo-analysis/funnels?year=2026&month=9`:

```json
{
    "data": {
        "filter": { "mode": "month", "date_from": "2026-09-01", "date_to": "2026-09-30", "...": "sama dengan /summary" },
        "is_empty": false,
        "message": null,
        "totals": { "transactions": 7, "revenue": 2800000 },
        "funnels": [
            { "key": "funnel", "funnel": { "id": 1, "name": "Aplikasi" }, "name": "Aplikasi", "transactions": 2, "revenue": 300000, "contribution_percentage": 10.71 },
            { "key": "funnel", "funnel": { "id": 2, "name": "Marketing" }, "name": "Marketing", "transactions": 0, "revenue": 0, "contribution_percentage": 0 },
            { "key": "funnel", "funnel": { "id": 3, "name": "Sales" }, "name": "Sales", "transactions": 2, "revenue": 700000, "contribution_percentage": 25 },
            { "key": "funnel", "funnel": { "id": 4, "name": "Ads" }, "name": "Ads", "transactions": 0, "revenue": 0, "contribution_percentage": 0 },
            { "key": "funnel", "funnel": { "id": 5, "name": "Live" }, "name": "Live", "transactions": 0, "revenue": 0, "contribution_percentage": 0 },
            { "key": "funnel", "funnel": { "id": 6, "name": "Lainnya" }, "name": "Lainnya", "transactions": 0, "revenue": 0, "contribution_percentage": 0 },
            { "key": "no_funnel", "funnel": null, "name": "Tanpa Funnel", "transactions": 2, "revenue": 1100000, "contribution_percentage": 39.29 },
            { "key": "no_code", "funnel": null, "name": "Tanpa Kode", "transactions": 1, "revenue": 700000, "contribution_percentage": 25 }
        ]
    }
}
```

| Field | Keterangan |
|---|---|
| `key` | `funnel`, `deleted_funnel`, `no_funnel`, atau `no_code` |
| `funnel` | `{ id, name }` funnel master; `null` untuk kelompok lain |
| `name` | Nama yang ditampilkan di kolom Funnel |

Jika tidak ada transaksi, respons tetap **200** dengan `is_empty: true`, `message`, dan semua kelompok bernilai 0.

## `GET /api/promo-analysis/sources`

Jumlah penggunaan dan revenue menurut **source setiap kode promo**. Endpoint ini menerima semua filter di atas.

Berbeda dengan tabel Funnel yang menghitung **transaksi**, tabel Source menghitung **penggunaan kode**:

| Kondisi transaksi | Penggunaan | Revenue |
|---|---|---|
| Satu kode | +1 untuk source kode itu | Seluruh nominal ke source kode itu |
| Dua kode | +1 untuk source **masing-masing** kode | Kode posisi 1: **Rp0**. Kode posisi 2 (reseller): **seluruh** nominal. |
| Posisi 1 kosong (`["", "RESELLER01"]`) | +1 untuk source kode itu | Seluruh nominal, karena hanya ada satu kode |
| Tanpa kode | Tidak dihitung | Tidak masuk tabel Source; tetap ada di KPI dan Funnel |

- Source diambil dari **snapshot** pada penggunaan kode itu sendiri (source yang berlaku pada waktu bayar). Kode utama dan kode kedua masing-masing memakai source-nya sendiri.
- Kode tanpa source, termasuk kode belum terdaftar, masuk **Tanpa Sumber** (`no_source`).
- Source yang sudah dihapus dari master tampil sebagai baris tersendiri dengan nama saat transaksi (`deleted_source`).
- Nama source memakai nama master **sekarang**, jadi source yang di-rename tetap satu baris.
- Saat `search` aktif, hanya kode yang **cocok** dengan pencarian yang dihitung. Misalnya `search=reseller` pada transaksi `CPNS26` + `RESELLER01` hanya menghitung `RESELLER01` (1 penggunaan, seluruh nominal).
- Transaksi anomali (lebih dari 2 kode) tidak punya baris penggunaan, sehingga tidak masuk tabel Source.

### Rumus

| Field | Rumus |
|---|---|
| `usages` | Jumlah penggunaan kode di source itu. Total penggunaan **boleh lebih besar** dari jumlah transaksi. |
| `revenue` | Jumlah nominal yang dikreditkan ke source itu (lihat tabel di atas) |
| `contribution_percentage` | `revenue` ÷ total revenue **seluruh** transaksi hasil filter × 100, dibulatkan 2 desimal. `0` jika total = 0. |
| `totals.transactions`, `totals.revenue` | Seluruh transaksi hasil filter, termasuk transaksi tanpa kode. Sama dengan `/summary`. |
| `totals.usages`, `totals.source_revenue` | Jumlah semua baris tabel Source. `source_revenue` sama dengan Revenue dengan Promo di `/summary`. |

Persentase Source **tidak harus berjumlah 100%**, karena revenue transaksi tanpa kode tidak masuk tabel Source tetapi tetap ada di total revenue.

### Urutan baris

Semua source master **selalu tampil**, termasuk yang 0, urut sesuai master (Aplikasi, Instagram, TikTok, Telegram, Sales, Ads, Live, Reseller, Lainnya, lalu source baru). Setelah itu source yang sudah dihapus (A-Z), lalu **Tanpa Sumber**. Tidak ada baris Tanpa Kode.

### 200 OK

`GET /api/promo-analysis/sources?year=2026&month=9`:

```json
{
    "data": {
        "filter": { "mode": "month", "date_from": "2026-09-01", "date_to": "2026-09-30", "...": "sama dengan /summary" },
        "is_empty": false,
        "message": null,
        "totals": { "transactions": 6, "revenue": 2100000, "usages": 7, "source_revenue": 1500000 },
        "sources": [
            { "key": "source", "source": { "id": 1, "name": "Aplikasi" }, "name": "Aplikasi", "usages": 0, "revenue": 0, "contribution_percentage": 0 },
            { "key": "source", "source": { "id": 2, "name": "Instagram" }, "name": "Instagram", "usages": 2, "revenue": 100000, "contribution_percentage": 4.76 },
            { "key": "source", "source": { "id": 3, "name": "TikTok" }, "name": "TikTok", "usages": 1, "revenue": 0, "contribution_percentage": 0 },
            { "key": "source", "source": { "id": 8, "name": "Reseller" }, "name": "Reseller", "usages": 2, "revenue": 500000, "contribution_percentage": 23.81 },
            { "key": "no_source", "source": null, "name": "Tanpa Sumber", "usages": 2, "revenue": 900000, "contribution_percentage": 42.86 }
        ]
    }
}
```

(Dipersingkat; source master lain tetap tampil dengan nilai 0.)

| Field | Keterangan |
|---|---|
| `key` | `source`, `deleted_source`, atau `no_source` |
| `source` | `{ id, name }` source master; `null` untuk kelompok lain |
| `name` | Nama yang ditampilkan di kolom Source |

Jika tidak ada transaksi, respons tetap **200** dengan `is_empty: true`, `message`, dan semua source bernilai 0.

## `GET /api/promo-analysis/codes`

Rekap jumlah penggunaan dan revenue atribusi **per kode promo**. Endpoint ini menerima semua filter di atas, ditambah:

| Parameter | Format | Keterangan |
|---|---|---|
| `sort` | `code`, `platform`, `funnel`, `source`, `usages`, `revenue`, `contribution` | Default `revenue`. `contribution` sama dengan urutan `revenue`. |
| `direction` | `asc` / `desc` | Default `desc` |
| `per_page` | 1-100 | Default 15 |
| `page` | angka | Default 1 |

Pencarian memakai `search` dari filter bersama: hanya kode yang cocok yang ditampilkan, termasuk kode yang belum terdaftar.

### Aturan

- **Satu baris** per platform + kode yang dinormalisasi (` cpns26 ` dan `CPNS26` adalah kode yang sama). Kode yang sama di platform lain adalah baris lain.
- Funnel dan source diambil dari **snapshot historis** setiap penggunaan. Jika metadata kode diubah di tengah periode, barisnya dipecah per metadata yang berlaku saat itu, misalnya `CPNS26 · Aplikasi` dan `CPNS26 · Marketing`. Dengan begitu angkanya selalu cocok dengan tabel Funnel dan Source.
- **Penggunaan** = setiap kemunculan kode per transaksi. Transaksi dua-kode menambah satu penggunaan ke masing-masing kode.
- **Revenue atribusi**, sama dengan tabel Source: satu kode mendapat seluruh nominal. Pada dua kode, kode posisi 1 mendapat **Rp0** dan kode posisi 2 mendapat **seluruh** nominal. Ini atribusi khusus rekap kode, bukan revenue funnel.
- **Kode belum terdaftar** tetap muncul dengan `is_registered: false`, tanpa funnel/source, dan semua penulisan aslinya ada di `raw_codes`.
- Tidak ada kolom tipe kode promo.

| Field | Keterangan |
|---|---|
| `code` | Kode yang dinormalisasi |
| `raw_codes` | Semua penulisan asli dari Markaz di hasil filter |
| `is_registered`, `promo_code_id` | Apakah kode sudah ada di master, dan id master-nya (`null` jika belum terdaftar) |
| `funnel`, `source` | `{ id, name }` dengan nama master sekarang. `id: null` dengan nama saat transaksi untuk funnel/source yang sudah dihapus dari master; `null` jika tidak ada. |
| `usages` | Jumlah penggunaan kode |
| `revenue` | Revenue atribusi kode |
| `contribution_percentage` | `revenue` ÷ total revenue **seluruh** transaksi hasil filter × 100, 2 desimal; `0` jika total 0 |
| `detail` | Parameter untuk membuka detail penggunaan: `platform_id` dan `code` |
| `totals` | `transactions` dan `revenue` seluruh transaksi hasil filter (sama dengan `/summary`), serta `usages` dan `code_revenue` semua kode (bukan hanya halaman ini) |

Urutan cadangan, jika nilai `sort` sama: kode A-Z, lalu nama platform, funnel, dan source.

### 200 OK

`GET /api/promo-analysis/codes?year=2026&month=9`:

```json
{
    "data": [
        {
            "code": "RESELLER01",
            "raw_codes": ["RESELLER01"],
            "is_registered": true,
            "promo_code_id": 7,
            "platform": { "id": 2, "slug": "jadiasn", "name": "JADIASN" },
            "funnel": { "id": 3, "name": "Sales" },
            "source": { "id": 8, "name": "Reseller" },
            "usages": 2,
            "revenue": 500000,
            "contribution_percentage": 23.81,
            "detail": { "platform_id": 2, "code": "RESELLER01" }
        },
        {
            "code": "UNREG26",
            "raw_codes": [" unreg26 ", "UNREG26"],
            "is_registered": false,
            "promo_code_id": null,
            "platform": { "id": 2, "slug": "jadiasn", "name": "JADIASN" },
            "funnel": null,
            "source": null,
            "usages": 2,
            "revenue": 400000,
            "contribution_percentage": 19.05,
            "detail": { "platform_id": 2, "code": "UNREG26" }
        }
    ],
    "links": { "...": "..." },
    "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 4, "...": "..." },
    "filter": { "...": "sama dengan /summary" },
    "totals": { "transactions": 6, "revenue": 2100000, "usages": 7, "code_revenue": 1500000 },
    "is_empty": false,
    "message": null
}
```

## `GET /api/promo-analysis/codes/usages`

Detail penggunaan satu kode, dibuka saat baris rekap diklik. Kirim `platform_id` dan `code` dari `detail` di baris rekap, beserta **filter yang sama** dengan rekap, supaya periodenya sama.

| Parameter | Wajib | Keterangan |
|---|---|---|
| `platform_id` | Ya | Platform kode |
| `code` | Ya | Kode promo; dinormalisasi, jadi ` cpns26 ` = `CPNS26` |
| `per_page` | Tidak | 1-100 transaksi per halaman, default 15 |
| `page` | Tidak | Default 1 |
| filter bersama | Tidak | `platforms[]`, `payment_methods[]`, periode, `search` |

- Satu baris per **transaksi** yang memakai kode itu, **dikelompokkan per tanggal**, terbaru lebih dulu (tanggal, lalu jam bayar).
- Setiap tanggal membawa total **sehari penuh** (`transactions_count`, `usages`, `revenue`), juga jika transaksi tanggal itu berlanjut ke halaman berikutnya.
- Pagination menghitung **transaksi** (`meta.total` = jumlah transaksi).
- Transaksi yang memakai kode yang sama di dua posisi adalah satu baris dengan `usages: 2`.
- Revenue memakai atribusi yang sama dengan rekap, jadi `totals` detail selalu sama dengan jumlah baris kode itu di rekap.
- Seperti rekap, saat `search` aktif hanya kode yang cocok yang dihitung.
- Tidak ada fitur import di detail.

### 200 OK

`GET /api/promo-analysis/codes/usages?year=2026&month=9&platform_id=2&code=RESELLER01`:

```json
{
    "data": [
        {
            "date": "2026-09-10",
            "transactions_count": 2,
            "usages": 2,
            "revenue": 500000,
            "transactions": [
                {
                    "trx_id": "jadiasn-476770",
                    "paid_date": "2026-09-10",
                    "paid_time": "11:00:00",
                    "platform": { "id": 2, "slug": "jadiasn", "name": "JADIASN" },
                    "code_positions": [2],
                    "raw_codes": ["RESELLER01"],
                    "funnel": { "id": 3, "name": "Sales" },
                    "source": { "id": 8, "name": "Reseller" },
                    "usages": 1,
                    "revenue": 300000,
                    "transaction_nominal": 300000
                }
            ]
        }
    ],
    "links": { "...": "..." },
    "meta": { "current_page": 1, "per_page": 15, "total": 2, "...": "..." },
    "filter": { "...": "sama dengan /summary" },
    "code": { "code": "RESELLER01", "is_registered": true, "platform": { "id": 2, "slug": "jadiasn", "name": "JADIASN" } },
    "totals": { "transactions": 2, "usages": 2, "revenue": 500000 },
    "is_empty": false,
    "message": null
}
```

| Field transaksi | Keterangan |
|---|---|
| `code_positions` | Posisi kode ini di transaksi (`1` = kode utama, `2` = kode kedua) |
| `raw_codes` | Penulisan asli kode ini di transaksi |
| `revenue` | Revenue atribusi kode ini dari transaksi itu (Rp0 jika kode utama di transaksi dua-kode) |
| `transaction_nominal` | Nominal transaksi seluruhnya |

## 422 Validasi gagal

| Kondisi | Key | Pesan |
|---|---|---|
| Rentang tanggal dan bulan dikirim bersamaan | `filter` | `Pilih salah satu filter periode saja: rentang tanggal, atau bulan & tahun.` |
| Mode tahun dikirim | `years` / `year_from` / `year_to` | `Filter tahun tidak tersedia di Analisis Promo. Pilih rentang tanggal, atau bulan & tahun.` |
| `payment_methods` bukan daftar | `payment_methods` | `Metode pembayaran harus berupa daftar.` |
| Pencarian lebih dari 100 karakter | `search` | `Pencarian kode promo maksimal 100 karakter.` |
| Slug platform tidak ada | `platform` / `platforms.0` | `Platform tidak ditemukan.` |
| `sort` / `direction` tidak dikenal (`/codes`) | `sort` / `direction` | `Urutan tidak valid.` / `Arah urutan tidak valid.` |
| `per_page` di luar 1-100 (`/codes`, `/codes/usages`) | `per_page` | `Jumlah per halaman harus di antara 1 dan 100.` |
| `platform_id` / `code` kosong (`/codes/usages`) | `platform_id` / `code` | `Platform wajib diisi.` / `Kode promo wajib diisi.` |
| `platform_id` tidak ada (`/codes/usages`) | `platform_id` | `Platform tidak ditemukan.` |

Validasi tanggal, bulan, dan tahun lainnya sama dengan [filter Ringkasan Performa](performance-filter.md#422-validasi-gagal).
