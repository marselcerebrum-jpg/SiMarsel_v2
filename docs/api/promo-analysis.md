# API Analisis Promo

Filter analisis, kartu KPI ringkasan, serta rekap per funnel dan per source untuk halaman Analisis Promo. Semua komponen analisis promo memakai **filter yang sama**, jadi kirim query parameter yang sama ke setiap endpoint.

Wajib login. **Semua user yang login** boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md). Cara penggunaan kode promo disimpan ada di [promo-codes.md](../promo-codes.md).

| Endpoint | Untuk |
|---|---|
| `GET /api/promo-analysis/filters` | Pilihan dropdown filter |
| `GET /api/promo-analysis/summary` | Kartu KPI |
| `GET /api/promo-analysis/funnels` | Tabel transaksi dan revenue per funnel |
| `GET /api/promo-analysis/sources` | Tabel penggunaan dan revenue per source |

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

## 422 Validasi gagal

| Kondisi | Key | Pesan |
|---|---|---|
| Rentang tanggal dan bulan dikirim bersamaan | `filter` | `Pilih salah satu filter periode saja: rentang tanggal, atau bulan & tahun.` |
| Mode tahun dikirim | `years` / `year_from` / `year_to` | `Filter tahun tidak tersedia di Analisis Promo. Pilih rentang tanggal, atau bulan & tahun.` |
| `payment_methods` bukan daftar | `payment_methods` | `Metode pembayaran harus berupa daftar.` |
| Pencarian lebih dari 100 karakter | `search` | `Pencarian kode promo maksimal 100 karakter.` |
| Slug platform tidak ada | `platform` / `platforms.0` | `Platform tidak ditemukan.` |

Validasi tanggal, bulan, dan tahun lainnya sama dengan [filter Ringkasan Performa](performance-filter.md#422-validasi-gagal).
