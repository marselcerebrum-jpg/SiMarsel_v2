# API Analisis Promo: Filter dan KPI

Filter analisis dan kartu KPI ringkasan untuk halaman Analisis Promo. Semua komponen analisis promo memakai **filter yang sama**, jadi kirim query parameter yang sama ke setiap endpoint.

Wajib login. **Semua user yang login** boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md). Cara penggunaan kode promo disimpan ada di [promo-codes.md](../promo-codes.md).

| Endpoint | Untuk |
|---|---|
| `GET /api/promo-analysis/filters` | Pilihan dropdown filter |
| `GET /api/promo-analysis/summary` | Kartu KPI |

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

## 422 Validasi gagal

| Kondisi | Key | Pesan |
|---|---|---|
| Rentang tanggal dan bulan dikirim bersamaan | `filter` | `Pilih salah satu filter periode saja: rentang tanggal, atau bulan & tahun.` |
| Mode tahun dikirim | `years` / `year_from` / `year_to` | `Filter tahun tidak tersedia di Analisis Promo. Pilih rentang tanggal, atau bulan & tahun.` |
| `payment_methods` bukan daftar | `payment_methods` | `Metode pembayaran harus berupa daftar.` |
| Pencarian lebih dari 100 karakter | `search` | `Pencarian kode promo maksimal 100 karakter.` |
| Slug platform tidak ada | `platform` / `platforms.0` | `Platform tidak ditemukan.` |

Validasi tanggal, bulan, dan tahun lainnya sama dengan [filter Ringkasan Performa](performance-filter.md#422-validasi-gagal).
