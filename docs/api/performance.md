# API Ringkasan Performa

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
