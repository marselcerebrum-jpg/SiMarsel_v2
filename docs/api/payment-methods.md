# API Distribusi Metode Pembayaran

Distribusi transaksi dan revenue per metode pembayaran, untuk Pie Chart dan tabel Metode Pembayaran. Filter dan rumusnya sama dengan [Ringkasan Performa](performance.md), sehingga angkanya selalu konsisten dengan kartu KPI dan grafik.

Wajib login. Semua role (Manager dan Employee) boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md).

## `GET /api/performance/payment-methods`

### Query parameter

Sama persis dengan `/api/performance/summary`, lihat [filter Ringkasan Performa](performance-filter.md): beberapa platform (`platforms[]`) dan satu mode periode (default bulan berjalan, rentang tanggal, bulan, beberapa tahun, atau rentang tahun).

Contoh:

- `GET /api/performance/payment-methods?date_from=2026-09-01&date_to=2026-09-30`
- `GET /api/performance/payment-methods?year=2026&month=9&platforms[]=jadiasn&platforms[]=cerebrum`
- `GET /api/performance/payment-methods?years[]=2025&years[]=2026` (gabungan kedua tahun)

### Rumus per metode pembayaran

Hanya transaksi dengan status laporan `Success` yang dihitung. Status laporan memakai `effective_status`, kemudian `source_status` sebagai fallback.

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
        "filter": { "mode": "date_range", "date_from": "2026-09-01", "date_to": "2026-09-30", "platform": null, "platforms": [], "...": "lihat performance-filter.md" },
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

Sama dengan [filter Ringkasan Performa](performance-filter.md#422-validasi-gagal), misalnya lebih dari satu mode periode, format tanggal salah, atau slug platform tidak ditemukan.

## Tabel pembanding per metode (`method_comparison`)

Hanya diisi jika request memakai `preset` (resolver periode bersama, lihat [performance-filter.md](performance-filter.md)). Tanpa `preset`, nilainya `null` dan bagian respons lain tidak berubah.

Contoh: `GET /api/performance/payment-methods?platforms[]=jadiasn&preset=last_7_days`

### Aturan

Semua nilai berasal dari agregasi transaksi bersama (FIX-02-BE-02). Agregasi tanggal dibuat dan tanggal bayar dihitung terpisah, lalu digabung per metode.

| Kolom (`columns[].key`) | Label | Dasar tanggal | Rumus | Unit perubahan |
|---|---|---|---|---|
| `total_transactions` | Total Transaksi | dibuat (`markaz_created_at`) | Pembelian semua status (termasuk tanpa status) + klaim kupon. Paket grup dihitung sekali, baris anggota grup tidak dihitung. | `percent` |
| `success_purchases` | Transaksi Berhasil | dibuat | Pembelian Success, tanpa klaim | `percent` |
| `pending_purchases` | Pending | dibuat | Pembelian Pending | `percent` |
| `unsuccessful_purchases` | Tidak Berhasil | dibuat | Pembelian Expired + Canceled + Failed | `percent` |
| `conversion` | Konversi Transaksi | dibuat | Pembelian Success ÷ semua percobaan pembelian × 100. Klaim kupon tidak masuk kedua sisi. `null` jika tidak ada percobaan pembelian, misalnya metode yang hanya berisi klaim. | `percentage_point` |
| `revenue` | Omzet | bayar (tanggal bayar valid dari Success) | Jumlah `net_nominal`, tanpa klaim dan anggota grup | `percent` |

- **Normalisasi metode:** huruf besar/kecil dan spasi diabaikan (`QRIS`, ` qris `, `Qris` jadi satu baris, `key: "qris"`). Label yang ditampilkan adalah penulisan yang paling sering dipakai. Jika jumlahnya sama, dipilih urutan pertama, sehingga `QRIS` menang atas `qris`.
- **Metode kosong:** `null` atau berisi spasi saja masuk ke baris **`Belum Ada Metode Pembayaran`** (`key: null`, `is_unassigned: true`). Baris ini selalu berada paling bawah.
- **Urutan baris:** Omzet aktif terbesar, Total Transaksi aktif terbesar, lalu nama A-Z.
- **Perubahan (`changes.<kolom>`):** `value` dalam persen relatif, kecuali Konversi dalam poin persentase (60% dibanding 50% menghasilkan `10`, unit `percentage_point`). `value` bernilai `null` jika pembanding 0 atau tidak diketahui. `trend` (`up`/`down`/`same`) adalah arah perubahan nilai. Untuk Pending dan Tidak Berhasil, `trend` menunjukkan arah perubahan jumlah. Saat `change_basis.is_trimmed` bernilai `true`, perubahan dihitung dari tanggal yang punya padanan saja, sementara nilai `active` tetap mencakup seluruh periode aktif.
- **Konversi Sementara:** jika `unknown_status_count > 0`, `conversion_is_provisional: true` dan `conversion_label: "Sementara"`. Pembelian tanpa status tetap masuk penyebut.

### Bentuk respons

```json
"method_comparison": {
    "period": { "...": "ResolvedPeriod, lihat performance-filter.md" },
    "is_empty": false,
    "message": null,
    "active": { "availability": "complete", "as_of": "2026-11-11T00:00:00+07:00" },
    "comparison": { "availability": "complete", "as_of": "2026-11-04T00:00:00+07:00", "source": "latest", "is_available": true, "message": null },
    "change_basis": { "is_trimmed": false },
    "columns": [
        { "key": "payment_method", "label": "Metode Pembayaran", "unit": null, "date_basis": null, "change_unit": null },
        { "key": "total_transactions", "label": "Total Transaksi", "unit": "count", "date_basis": "created", "change_unit": "percent" },
        "... urutan sesuai tabel di atas"
    ],
    "rows": [
        {
            "key": "qris",
            "name": "QRIS",
            "is_unassigned": false,
            "active": {
                "total_transactions": 3, "success_purchases": 1, "pending_purchases": 1, "unsuccessful_purchases": 0,
                "conversion": 50, "revenue": 180000,
                "purchase_attempts": 2, "claim_checkouts": 1, "unknown_status_count": 0,
                "conversion_is_provisional": false, "conversion_label": null,
                "unsuccessful_breakdown": { "expired": 0, "canceled": 0, "failed": 0 },
                "success_payments": 1
            },
            "comparison": { "...": "bentuk sama dengan active, atau null" },
            "changes": {
                "total_transactions": { "value": 50, "unit": "percent", "trend": "up" },
                "conversion": { "value": 10, "unit": "percentage_point", "trend": "up" },
                "...": "success_purchases, pending_purchases, unsuccessful_purchases, revenue"
            }
        }
    ]
}
```

- Rincian Total Transaksi memakai `purchase_attempts` dan `claim_checkouts`. Rincian Tidak Berhasil memakai `unsuccessful_breakdown`, yang jumlahnya selalu sama dengan `unsuccessful_purchases`.

### Keadaan khusus

| Keadaan | Respons |
|---|---|
| Periode aktif tidak berisi transaksi | `is_empty: true`, `message: "Tidak ada data metode pembayaran untuk filter yang dipilih."`, `rows: []` |
| Data periode aktif belum tersedia (tidak ada coverage) | `is_empty: false`, `message: "Data periode aktif belum tersedia."`, `rows: []` |
| Pembanding kosong saja | Nilai `active` tetap ada. `rows[].comparison` bernilai `null`, `changes.*.value` dan `trend` bernilai `null`, dan `comparison.message` berisi keterangan untuk ditampilkan di tempat pembanding: `Periode ini tidak memiliki tanggal pembanding.`, `Data periode pembanding belum tersedia.`, atau `Pembanding jam setara per metode pembayaran belum tercatat.` |
| Metode tidak ada di periode pembanding, tetapi data pembanding lengkap | `comparison` berisi angka 0 (bukan `null`). Perubahan persennya `null` dan `trend` tetap terisi. |
| Data aktif parsial (cutoff) | `active.availability: "partial"` dan `active.as_of` berisi batas data |

Pembanding jam setara (`comparison.source: "snapshot"`) belum punya nilai per metode karena snapshot hanya mencatat total per platform. Nilai ini tidak dibangun ulang dari state transaksi terbaru.
