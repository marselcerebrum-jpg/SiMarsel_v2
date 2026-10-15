# API Distribusi Metode Pembayaran

Distribusi transaksi dan revenue per metode pembayaran, untuk Pie Chart dan tabel Metode Pembayaran. Filter dan rumusnya sama dengan [Ringkasan Performa](performance.md), sehingga angkanya selalu konsisten dengan kartu KPI dan grafik.

Wajib login. Semua role (Manager dan Employee) boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md).

## `GET /api/performance/payment-methods`

### Query parameter

Sama persis dengan `/api/performance/summary`.

| Parameter | Wajib | Format | Keterangan |
|---|---|---|---|
| `date_from` | Ya | `YYYY-MM-DD` | Tanggal mulai (inklusif) |
| `date_to` | Ya | `YYYY-MM-DD` | Tanggal akhir (inklusif), tidak boleh sebelum `date_from` |
| `platform` | Tidak | slug platform | Kosongkan untuk menghitung semua platform |

Contoh: `GET /api/performance/payment-methods?date_from=2026-09-01&date_to=2026-09-30`

### Rumus per metode pembayaran

Hanya transaksi dengan `status_message` = `Success` yang dihitung.

| Field | Rumus |
|---|---|
| `name` | Nama metode pembayaran seperti tersimpan di transaksi (tanpa spasi di awal atau akhir) |
| `transactions` | Jumlah transaksi |
| `revenue` | Jumlah `nominal` |
| `transaction_percentage` | `transactions` ÷ total transaksi × 100, dibulatkan 2 desimal. `0` jika total = 0. |
| `revenue_percentage` | `revenue` ÷ total revenue × 100, dibulatkan 2 desimal. `0` jika total = 0. Ini **Persentase Kontribusi** di pie chart. |

- Metode yang hanya beda huruf besar/kecil atau spasi (misalnya `e_wallet` dan ` E_WALLET `) digabung jadi satu.
- Transaksi tanpa metode pembayaran (kosong atau `null`) digabung sebagai **`Tidak diketahui`**, supaya total tetap sama dengan Ringkasan Performa.
- Hanya metode yang punya transaksi di periode itu yang muncul.
- Urutan: revenue terbesar lebih dulu, lalu nama A-Z.
- Karena pembulatan, jumlah semua persentase bisa 99.99 atau 100.01.

`totals.transactions` dan `totals.revenue` selalu sama dengan `total_transactions` dan `total_revenue` di Ringkasan Performa.

### 200 OK

```json
{
    "data": {
        "filter": { "date_from": "2026-09-01", "date_to": "2026-09-30", "platform": null },
        "is_empty": false,
        "message": null,
        "totals": { "transactions": 6, "revenue": 1000000 },
        "payment_methods": [
            { "name": "bank_transfer", "transactions": 2, "revenue": 550000, "transaction_percentage": 33.33, "revenue_percentage": 55 },
            { "name": "e_wallet", "transactions": 2, "revenue": 300000, "transaction_percentage": 33.33, "revenue_percentage": 30 },
            { "name": "Tidak diketahui", "transactions": 2, "revenue": 150000, "transaction_percentage": 33.33, "revenue_percentage": 15 }
        ],
        "labels": ["bank_transfer", "e_wallet", "Tidak diketahui"],
        "datasets": [
            { "label": "Revenue", "data": [550000, 300000, 150000] }
        ]
    }
}
```

| Field | Dipakai untuk |
|---|---|
| `labels`, `datasets` | Langsung ke Chart.js: `new Chart(canvas, { type: 'pie', data: { labels, datasets } })`. Urutan `labels` sama dengan `payment_methods`. |
| `payment_methods` | Tabel (Metode Pembayaran → Jumlah Transaksi → Revenue → Persentase Kontribusi) dan tooltip pie chart: ambil `payment_methods[index].revenue_percentage` untuk persentase kontribusi |
| `totals` | Baris total di tabel |

### Data kosong

Jika tidak ada transaksi `Success` di periode yang dipilih, respons tetap **200**, dengan `is_empty: true`, `message: "Data tidak tersedia untuk filter yang dipilih."`, totals `0`, dan `payment_methods`, `labels`, serta `datasets[0].data` berupa array kosong. Tampilkan `message` sebagai kondisi kosong, bukan pie chart.

### 422 Validasi gagal

Sama dengan `/api/performance/summary`: tanggal kosong atau salah format, `date_to` sebelum `date_from`, atau slug platform tidak ditemukan.
