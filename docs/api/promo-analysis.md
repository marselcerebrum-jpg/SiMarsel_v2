# API Analisis Promo

Filter analisis, kartu KPI ringkasan, rekap per funnel, per source, dan per kode promo, detail penggunaan kode, serta grafik tren dan Top 5 kode untuk halaman Analisis Promo. Semua komponen analisis promo memakai **filter yang sama**, jadi kirim query parameter yang sama ke setiap endpoint.

Wajib login. **Semua user yang login** boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md). Cara penggunaan kode promo disimpan ada di [promo-codes.md](../promo-codes.md).

| Endpoint | Untuk |
|---|---|
| `GET /api/promo-analysis/filters` | Pilihan dropdown filter |
| `GET /api/promo-analysis/summary` | Kartu KPI |
| `GET /api/promo-analysis/product-types` | Transaksi dan revenue promo per tipe produk (Perdana / Perpanjangan) |
| `GET /api/promo-analysis/funnels` | Tabel transaksi dan revenue per funnel |
| `GET /api/promo-analysis/sources` | Tabel penggunaan dan revenue per source |
| `GET /api/promo-analysis/codes` | Tabel rekap per kode promo |
| `GET /api/promo-analysis/codes/usages` | Detail penggunaan satu kode (saat baris rekap diklik) |
| `GET /api/promo-analysis/trend` | Grafik tren transaksi dan revenue promo, dengan overlay total penjualan |
| `GET /api/promo-analysis/top-codes` | Top 5 kode berdasarkan jumlah penggunaan |
| `GET /api/promo-analysis/export` | Export Excel seluruh analisis sesuai filter |

## Sumber data

- Tabel `transactions`, hanya transaksi dengan `status_message` = `Success`, sama seperti Ringkasan Performa.
- Khusus analisis promo, transaksi `promo_code_anomaly = true` dikecualikan dari seluruh perhitungan dan denominator. Fakta transaksi tersebut tetap tersedia pada Ringkasan Performa umum.
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

Transaksi dengan **lebih dari 2 kode** atau format invalid adalah anomali. Raw terbaru serta penggunaan valid sebelumnya tetap disimpan, tetapi transaksi dikecualikan dari seluruh KPI promo, bukan dihitung sebagai tanpa promo. Detail dicatat di `storage/logs/promo-code-anomalies.log`; filter query memakai penanda pada transaksi. Payload valid berikutnya dapat memulihkan atribusi melalui normalisasi.

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

## `GET /api/promo-analysis/product-types`

Transaksi dan revenue **promo** menurut tipe produk (`transactions.product_type`), untuk membandingkan segmen Perdana dan Perpanjangan. Endpoint ini menerima semua filter di atas.

- Hanya **transaksi unik yang punya minimal satu kode promo**. Transaksi dua-kode dihitung **satu kali**, dan nominalnya dijumlahkan satu kali.
- Yang ditampilkan adalah jumlah **transaksi** dan **revenue**, bukan jumlah penggunaan kode.
- Dasar transaksinya sama dengan KPI: hanya `Success`, dan transaksi anomali dikecualikan. `totals` selalu sama dengan **Transaksi dengan Promo** dan **Revenue dengan Promo** di `/summary`.
- Pencarian kode (`search`) mempersempit transaksi ke yang memakai kode yang cocok, sama seperti KPI.

### Kelompok tipe produk

| `key` | Kondisi | Nama |
|---|---|---|
| `first_purchase` | `product_type` = `Perdana` | Perdana |
| `renewal` | `product_type` = `Perpanjangan` | Perpanjangan |
| `other` | Nilai lain | Nilai `product_type` apa adanya, satu baris per nilai |
| `unknown` | Kosong atau `null` | Tidak diketahui |

- Perdana dan Perpanjangan dicocokkan dengan cara yang sama seperti **Pengguna Baru** dan **Repeat Order** di Ringkasan Performa, jadi angkanya konsisten.
- Nilai lain atau kosong **tidak pernah** dimasukkan diam-diam ke Perdana atau Perpanjangan.
- Urutan: Perdana dan Perpanjangan (selalu tampil, walaupun 0), lalu tipe lain A-Z, lalu Tidak diketahui (hanya jika ada).

### Rumus

| Field | Rumus |
|---|---|
| `transactions` | Transaksi promo unik di kelompok itu |
| `revenue` | Jumlah `nominal` transaksi promo di kelompok itu, satu kali per transaksi |
| `transaction_percentage` | `transactions` ÷ total transaksi promo hasil filter × 100, 2 desimal; `0` jika total 0 |
| `revenue_percentage` | `revenue` ÷ total revenue promo hasil filter × 100, 2 desimal; `0` jika total 0 |

Export tabel ini, jika nanti dibuat, wajib memakai `PromoProductTypeService::breakdown()` yang sama, supaya angkanya tidak berbeda dengan halaman.

### 200 OK

`GET /api/promo-analysis/product-types?year=2026&month=9`:

```json
{
    "data": {
        "filter": { "mode": "month", "date_from": "2026-09-01", "date_to": "2026-09-30", "...": "sama dengan /summary" },
        "is_empty": false,
        "message": null,
        "totals": { "transactions": 6, "revenue": 1950000 },
        "product_types": [
            { "key": "first_purchase", "name": "Perdana", "transactions": 2, "revenue": 300000, "transaction_percentage": 33.33, "revenue_percentage": 15.38 },
            { "key": "renewal", "name": "Perpanjangan", "transactions": 1, "revenue": 300000, "transaction_percentage": 16.67, "revenue_percentage": 15.38 },
            { "key": "other", "name": "Bundling", "transactions": 1, "revenue": 400000, "transaction_percentage": 16.67, "revenue_percentage": 20.51 },
            { "key": "unknown", "name": "Tidak diketahui", "transactions": 2, "revenue": 950000, "transaction_percentage": 33.33, "revenue_percentage": 48.72 }
        ]
    }
}
```

Jika tidak ada transaksi promo, respons tetap **200** dengan `is_empty: true`, `message`, dan Perdana/Perpanjangan bernilai 0.

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
- Kode reseller yang berada di posisi 1 (`["RESELLER01"]`) menentukan Funnel. Jika posisi 1 kosong (`["", "RESELLER01"]`), transaksi masuk **Tanpa Funnel**, tetap dihitung sebagai menggunakan promo, dan bukan Tanpa Kode.
- ID Funnel diambil dari **snapshot** master saat transaksi pertama kali diproses SIMarsel, tanpa pencarian versi berdasarkan tanggal bayar. Hasil kosong yang sudah terhubung juga tetap dipertahankan.
- Nama funnel memakai nama master **sekarang**, jadi funnel yang di-rename tetap satu baris.
- Transaksi anomali dikecualikan, termasuk penggunaan lama yang tetap disimpan; tidak masuk Tanpa Kode maupun kelompok Funnel lain.

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

- ID Source diambil dari **snapshot** master saat penggunaan kode pertama kali diproses, bukan dari versi pada waktu bayar. Kode utama dan kode kedua masing-masing memakai Source-nya sendiri.
- Kode tanpa source, termasuk kode belum terdaftar, masuk **Tanpa Sumber** (`no_source`).
- Source yang sudah dihapus dari master tampil sebagai baris tersendiri dengan nama saat transaksi (`deleted_source`).
- Nama source memakai nama master **sekarang**, jadi source yang di-rename tetap satu baris.
- Saat `search` aktif, hanya kode yang **cocok** dengan pencarian yang dihitung. Misalnya `search=reseller` pada transaksi `CPNS26` + `RESELLER01` hanya menghitung `RESELLER01` (1 penggunaan, seluruh nominal).
- Transaksi anomali tidak masuk tabel Source atau denominator-nya, termasuk jika masih memiliki penggunaan valid sebelumnya.

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
- Funnel dan source diambil dari **snapshot** setiap penggunaan, yaitu master saat penggunaan itu pertama kali diproses (bukan versi pada waktu bayar).
- **Label historis:** nama funnel/source yang ditampilkan adalah **nama yang tersimpan di penggunaan** (`funnel_name_snapshot`, `source_name_snapshot`), walaupun master-nya sudah di-rename. Nama master sekarang hanya dipakai jika data lama belum punya nama snapshot. Contoh: penggunaan dengan snapshot `Marketing` tetap tampil `Marketing` setelah master diubah menjadi `Digital Marketing`.
- Satu baris per platform + kode + **grup snapshot** (ID dan label funnel, ID dan label source). Jika klasifikasi kode berubah, misalnya mapping diganti atau master di-rename lalu dipakai transaksi baru, barisnya dipecah, misalnya `CPNS26 · Aplikasi` dan `CPNS26 · Marketing`.
- Berbeda dengan tabel Funnel dan Source, yang memakai nama master **sekarang** sesuai aturan `docs/promo-codes.md`. Jumlah penggunaan dan revenue tetap sama, tetapi labelnya bisa berbeda setelah master di-rename.
- Transaksi anomali dikecualikan dari rekap, detail, dan denominator kontribusi, termasuk penggunaan valid lama yang masih disimpan.
- **Penggunaan** = setiap kemunculan kode per transaksi. Transaksi dua-kode menambah satu penggunaan ke masing-masing kode.
- **Revenue atribusi**, sama dengan tabel Source: satu kode mendapat seluruh nominal. Pada dua kode, kode posisi 1 mendapat **Rp0** dan kode posisi 2 mendapat **seluruh** nominal. Ini atribusi khusus rekap kode, bukan revenue funnel.
- **Kode belum terdaftar** tetap muncul dengan `is_registered: false`, tanpa funnel/source, dan semua penulisan aslinya ada di `raw_codes`.
- Tidak ada kolom tipe kode promo.

| Field | Keterangan |
|---|---|
| `code` | Kode yang dinormalisasi |
| `raw_codes` | Semua penulisan asli dari Markaz di hasil filter |
| `is_registered`, `promo_code_id` | Apakah kode sudah ada di master, dan id master-nya (`null` jika belum terdaftar) |
| `funnel`, `source` | `{ id, name }`: `id` snapshot dan nama yang tersimpan di penggunaan (label historis). `id: null` untuk funnel/source yang sudah dihapus dari master; `null` jika tidak ada. |
| `usages` | Jumlah penggunaan kode |
| `revenue` | Revenue atribusi kode |
| `contribution_percentage` | `revenue` ÷ total revenue **seluruh** transaksi hasil filter × 100, 2 desimal; `0` jika total 0 |
| `detail` | Parameter untuk membuka detail penggunaan baris ini: `platform_id`, `code`, dan `group` (identitas grup snapshot baris ini). Kirim ketiganya apa adanya ke `/codes/usages`. |
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
            "detail": { "platform_id": 2, "code": "RESELLER01", "group": "WzMsIlNhbGVzIiw4LCJSZXNlbGxlciJd" }
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
            "detail": { "platform_id": 2, "code": "UNREG26", "group": "W251bGwsbnVsbCxudWxsLG51bGxd" }
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

Detail penggunaan satu baris rekap, dibuka saat baris itu diklik. Kirim `platform_id`, `code`, dan `group` dari `detail` di baris rekap, beserta **filter yang sama** dengan rekap. Dengan `group`, detail hanya berisi penggunaan dengan klasifikasi snapshot baris itu, jadi totalnya **selalu sama** dengan penggunaan dan revenue baris yang diklik.

| Parameter | Wajib | Keterangan |
|---|---|---|
| `platform_id` | Ya | Platform kode |
| `code` | Ya | Kode promo; dinormalisasi, jadi ` cpns26 ` = `CPNS26` |
| `group` | Tidak | Identitas grup snapshot dari `detail.group` baris rekap. Tanpa `group`, detail berisi **semua** penggunaan kode itu dari semua klasifikasi (dipakai Top 5, yang menggabungkan klasifikasi). Nilai yang tidak valid → 422. |
| `per_page` | Tidak | 1-100 transaksi per halaman, default 15 |
| `page` | Tidak | Default 1 |
| filter bersama | Tidak | `platforms[]`, `payment_methods[]`, periode, `search` |

- Satu baris per **transaksi** yang memakai kode itu, **dikelompokkan per tanggal**, terbaru lebih dulu (tanggal, lalu jam bayar).
- Setiap tanggal membawa total **sehari penuh** (`transactions_count`, `usages`, `revenue`), juga jika transaksi tanggal itu berlanjut ke halaman berikutnya.
- Pagination menghitung **transaksi** (`meta.total` = jumlah transaksi).
- Transaksi yang memakai kode yang sama di dua posisi adalah satu baris dengan `usages: 2`.
- Revenue memakai atribusi yang sama dengan rekap, jadi `totals` detail selalu sama dengan baris rekap yang diklik (dengan `group`), atau dengan jumlah semua baris kode itu (tanpa `group`).
- `code.funnel` dan `code.source` menampilkan klasifikasi grup yang dibuka (`null` tanpa `group`). Funnel/source tiap transaksi memakai label historis yang sama dengan rekap.
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
    "code": {
        "code": "RESELLER01",
        "is_registered": true,
        "platform": { "id": 2, "slug": "jadiasn", "name": "JADIASN" },
        "funnel": { "id": 3, "name": "Sales" },
        "source": { "id": 8, "name": "Reseller" },
        "group": "WzMsIlNhbGVzIiw4LCJSZXNlbGxlciJd"
    },
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

## `GET /api/promo-analysis/trend`

Grafik transaksi dan revenue promo dari waktu ke waktu, dengan **total penjualan** pada periode yang sama sebagai pembanding. Endpoint ini menerima semua filter di atas, ditambah:

| Parameter | Format | Keterangan |
|---|---|---|
| `period` | `daily`, `weekly`, `monthly` | Ukuran bucket grafik. Default `daily`. |

- Bucket dihitung dari `paid_date`, sama dengan grafik Ringkasan Performa: **mingguan** = Senin-Minggu, **bulanan** = bulan kalender. Bucket pertama dan terakhir dipotong mengikuti rentang filter.
- `daily` dan `weekly` maksimal **366 hari**; `monthly` tidak dibatasi.
- Bucket yang dimulai **setelah hari ini** bernilai `null` (belum terjadi), bukan `0`.

### Dataset

| `key` | Label | Rumus |
|---|---|---|
| `promo_transactions` | Transaksi dengan Promo | Transaksi unik yang punya minimal satu penggunaan kode. Transaksi dua-kode dihitung **satu kali**. |
| `promo_revenue` | Revenue dengan Promo | Nominal transaksi promo, **satu kali per transaksi** (bukan atribusi per kode) |
| `total_transactions` | Total Transaksi | Seluruh transaksi di bucket itu, dengan dan tanpa promo |
| `total_sales` | Total Penjualan | Nominal seluruh transaksi di bucket itu (overlay) |

- Semua dataset memakai dasar transaksi yang sama dengan KPI: hanya transaksi `Success`, dan transaksi **anomali dikecualikan**, termasuk dari overlay total penjualan.
- Semua filter aktif berlaku untuk **semua dataset**: platform, metode pembayaran, periode, dan **pencarian kode** (`search`). Jadi `totals` grafik selalu sama dengan KPI `/summary` untuk filter yang sama.
- Saat `search` aktif, overlay Total Transaksi dan Total Penjualan juga hanya menghitung transaksi yang memakai kode yang cocok, sama seperti KPI. Akibatnya, overlay sama dengan garis promo.

### 200 OK

`GET /api/promo-analysis/trend?year=2026&month=9&period=weekly`:

```json
{
    "data": {
        "filter": { "mode": "month", "date_from": "2026-09-01", "date_to": "2026-09-30", "...": "sama dengan /summary" },
        "period": "weekly",
        "is_empty": false,
        "message": null,
        "totals": { "promo_transactions": 4, "promo_revenue": 1150000, "total_transactions": 6, "total_sales": 2150000 },
        "labels": ["01 - 06 Sep 2026", "07 - 13 Sep 2026", "14 - 20 Sep 2026", "21 - 27 Sep 2026", "28 - 30 Sep 2026"],
        "datasets": [
            { "key": "promo_transactions", "label": "Transaksi dengan Promo", "data": [1, 2, 1, 0, 0] },
            { "key": "promo_revenue", "label": "Revenue dengan Promo", "data": [100000, 500000, 550000, 0, 0] },
            { "key": "total_transactions", "label": "Total Transaksi", "data": [2, 2, 2, 0, 0] },
            { "key": "total_sales", "label": "Total Penjualan", "data": [500000, 500000, 1150000, 0, 0] }
        ],
        "rows": [
            { "label": "01 - 06 Sep 2026", "period_start": "2026-09-01", "period_end": "2026-09-06", "promo_transactions": 1, "promo_revenue": 100000, "total_transactions": 2, "total_sales": 500000 }
        ]
    }
}
```

(`rows` dipersingkat; satu baris per bucket.)

Jika tidak ada transaksi, respons tetap **200** dengan `is_empty: true`, `message`, dan semua bucket bernilai 0 (atau `null` untuk bucket setelah hari ini).

## `GET /api/promo-analysis/top-codes`

Lima kode promo dengan **jumlah penggunaan** terbanyak. Endpoint ini menerima semua filter di atas.

- **Penggunaan** dan **revenue** memakai definisi yang sama dengan rekap per kode (`/codes`): setiap kemunculan kode per transaksi = 1 penggunaan; satu kode mendapat seluruh nominal, dan pada dua kode posisi 1 mendapat Rp0 sedangkan posisi 2 mendapat seluruh nominal.
- Urutan: **penggunaan** terbanyak (bukan transaksi unik dan bukan revenue). Jika sama, revenue terbesar, lalu kode A-Z, lalu nama platform.
- Revenue dan kontribusi ditampilkan sebagai metrik pendamping. Kontribusi = revenue kode ÷ total revenue seluruh transaksi hasil filter × 100.
- Satu baris per platform + kode yang dinormalisasi. Berbeda dengan `/codes`, kode yang punya beberapa snapshot funnel/source **tetap satu baris**, karena Top 5 mengurutkan kode.
- Kode belum terdaftar ikut dihitung (`is_registered: false`). Transaksi anomali dikecualikan.
- Saat `search` aktif, hanya kode yang cocok yang masuk Top 5.
- Bisa berisi kurang dari 5 kode jika kode yang dipakai lebih sedikit.
- Kondisi kosong ditentukan oleh **penggunaan kode promo**, bukan oleh ada atau tidaknya transaksi. Jika tidak ada kode yang dipakai, respons tetap **200** dengan `is_empty: true` dan `codes: []`:
  - ada transaksi, tetapi semuanya tanpa promo → `message: "Tidak ada penggunaan kode promo pada filter yang dipilih."`;
  - tidak ada transaksi sama sekali → `message: "Data tidak tersedia untuk filter yang dipilih."`.

### 200 OK

`GET /api/promo-analysis/top-codes?year=2026&month=9`:

```json
{
    "data": {
        "filter": { "...": "sama dengan /summary" },
        "is_empty": false,
        "message": null,
        "codes": [
            {
                "rank": 1,
                "code": "RESELLER01",
                "raw_codes": ["RESELLER01"],
                "is_registered": true,
                "promo_code_id": 7,
                "platform": { "id": 2, "slug": "jadiasn", "name": "JADIASN" },
                "usages": 2,
                "revenue": 500000,
                "contribution_percentage": 23.26,
                "detail": { "platform_id": 2, "code": "RESELLER01" }
            }
        ]
    }
}
```

`detail` bisa dipakai untuk membuka detail penggunaan kode (`/codes/usages`). Karena Top 5 menggabungkan klasifikasi, `detail` di sini **tanpa** `group`, sehingga detail berisi semua penggunaan kode itu dan totalnya sama dengan baris Top 5.

## `GET /api/promo-analysis/export`

Mengunduh **seluruh** hasil Analisis Promo sesuai filter yang sedang aktif, dalam satu file Excel. Setiap sheet dibuat oleh service yang sama dengan endpoint komponennya, jadi angkanya selalu sama dengan halaman. Export hanya membaca data: tidak ada import dan tidak mengubah data master.

| Parameter | Keterangan |
|---|---|
| Filter bersama | `platforms[]`, `payment_methods[]`, periode, `search`, sama dengan endpoint lain |
| `period` | Rincian sheet Tren: `daily` (default), `weekly`, `monthly`, sama dengan `/trend` |
| `sort`, `direction` | Urutan sheet Rekap Kode Promo, sama dengan `/codes` (default `revenue`, `desc`) |

Contoh: `GET /api/promo-analysis/export?year=2026&month=9&platforms[]=jadiasn&period=weekly`

Di frontend, cukup arahkan browser ke URL tersebut (`window.location.href = url` atau `<a href="...">`), karena cookie session ikut terkirim. Jika memakai `fetch()` untuk menampilkan status proses, baca respons sebagai blob saat sukses, dan sebagai JSON (`message`) saat gagal.

### 200 OK

File `promo-analisis_{date_from}_{date_to}.xlsx`, misalnya `promo-analisis_2026-09-01_2026-09-30.xlsx`, dengan 7 sheet:

| Sheet | Kolom | Sumber |
|---|---|---|
| Ringkasan | Periode, Periode Pembanding, Platform, Metode Pembayaran, Pencarian Kode Promo; lalu tabel KPI, Nilai, Nilai Periode Pembanding, Perubahan (%), Tren | `/summary` |
| Funnel | Funnel, Jumlah Transaksi, Jumlah Revenue, Kontribusi Funnel (%) | `/funnels` |
| Source | Source, Jumlah Penggunaan, Jumlah Revenue, Kontribusi Source (%) | `/sources` |
| Rekap Kode Promo | Kode Promo, Kode Asli Markaz, Status, Platform, Funnel, Source, Jumlah Penggunaan, Jumlah Revenue, Kontribusi (%) | `/codes`, **semua baris** tanpa pagination |
| Detail Penggunaan | Tanggal, Jam, ID Transaksi, Platform, Kode Promo, Kode Asli Markaz, Posisi Kode, Status, Funnel, Source, Jumlah Penggunaan, Revenue, Nominal Transaksi | `/codes/usages` untuk **semua kode**: satu baris per penggunaan kode, transaksi terbaru lebih dulu |
| Tren | Periode, Tanggal Mulai, Tanggal Akhir, Transaksi dengan Promo, Revenue dengan Promo, Total Transaksi, Total Penjualan | `/trend` |
| Perdana vs Perpanjangan | Tipe Produk, Jumlah Transaksi Promo, Revenue Promo, Persentase Transaksi (%), Persentase Revenue (%) | `/product-types` |

- Kode yang **belum terdaftar** tetap ikut di Rekap dan Detail, dengan Status `Belum terdaftar` dan penulisan asli dari Markaz.
- Transaksi anomali dikecualikan dari semua sheet, sama seperti halaman.
- Revenue di Detail adalah revenue atribusi kode (Rp0 untuk kode utama di transaksi dua-kode). Jumlah kolom Revenue Detail sama dengan jumlah Revenue di Rekap.
- Angka disimpan sebagai angka, bukan teks. Nilai kosong (misalnya perubahan tanpa pembanding, atau bucket setelah hari ini) dibiarkan kosong.
- Jika filter tidak punya data, file tetap diunduh dengan semua sheet: tabel kosong hanya berisi judul kolom.
- Sheet Rekap dan Detail dibaca bertahap dari database, sehingga export tidak terbatas pagination.

### Gagal

Respons JSON dengan `message` yang bisa langsung ditampilkan:

| Status | Kondisi | Pesan |
|---|---|---|
| 422 | Filter, `period`, atau `sort` tidak valid | Sama dengan endpoint lain (lihat di bawah) |
| 422 | Penggunaan kode lebih dari batas satu sheet Excel (1.048.575 baris) | `Data terlalu besar untuk diekspor: sheet Detail Penggunaan berisi N baris, maksimal 1048575 baris. Persempit periode atau filter, lalu coba lagi.` |
| 500 | Kesalahan tak terduga | Pesan umum Laravel, tanpa detail teknis. Tampilkan pesan gagal umum di FE. |

### Tombol Salin

Tombol **Salin** menyalin data komponen yang sedang dipilih dari respons endpoint komponen itu, dengan filter yang sama. Prosesnya sepenuhnya di frontend, tanpa endpoint khusus, dan status berhasil/gagal ditampilkan di frontend.

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
| `group` tidak valid (`/codes/usages`) | `group` | `Grup klasifikasi tidak valid. Buka detail dari baris rekap kode promo.` |
| `period` tidak dikenal (`/trend`) | `period` | `Periode tidak valid.` |
| Rentang lebih dari 366 hari untuk `daily`/`weekly` (`/trend`) | `date_to` | `Rentang tanggal untuk periode daily maksimal 366 hari.` |

Validasi tanggal, bulan, dan tahun lainnya sama dengan [filter Ringkasan Performa](performance-filter.md#422-validasi-gagal).
