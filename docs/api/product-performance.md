# Performa Produk (Dashboard)

`GET /api/performance/products` (`api.performance.products`)

Dataset tabel Performa Produk di Dashboard (FIX-11-BE-01). Endpoint ini memakai jalur Performance Dashboard dan tidak bergantung pada endpoint `api.products.*` lama.

## Parameter

Filter utama sama dengan [filter Ringkasan Performa](performance-filter.md). `preset` **wajib** karena dataset ini hanya membaca resolver periode bersama. Hanya periode aktif yang dihitung.

Kontrol lokal tabel:

| Parameter | Nilai | Default | Keterangan |
|---|---|---|---|
| `search` | teks, maks. 100 karakter | kosong | Cocok sebagian pada nama produk, tidak peka huruf besar/kecil dan spasi ganda. |
| `sort` | `product`, `total_transactions`, `success_purchases`, `pending_purchases`, `unsuccessful_purchases`, `revenue`, `transaction_contribution`, `revenue_contribution` | `revenue` | Nilai seri diurutkan nama produk lalu nama platform. Kontribusi `null` berada di bawah 0. |
| `direction` | `asc`, `desc` | `asc` untuk `product`, selain itu `desc` | |
| `page` | bilangan ≥ 1 | `1` | Halaman melebihi `last_page` mengembalikan `rows: []`. |
| `per_page` | 1 - 100 | `10` | |

Nilai tidak valid menghasilkan **422** dengan error per field.

`search`, `sort`, `page`, dan `per_page` hanya memilih dan mengurutkan baris. `totals`, `contribution`, dan nilai kontribusi setiap baris tetap dihitung dari seluruh produk dalam filter utama.

## Aturan

Semua nilai berasal dari agregasi transaksi bersama (FIX-02-BE-02). Agregasi tanggal dibuat dan tanggal bayar dihitung terpisah, lalu digabung per produk.

| Kolom (`columns[].key`) | Label | Dasar tanggal | Rumus |
|---|---|---|---|
| `product` | Produk | - | Nama produk, dengan `platform` sebagai konteks. |
| `total_transactions` | Total Transaksi | dibuat (`markaz_created_at`) | Pembelian semua status (termasuk tanpa status) + klaim kupon. Paket grup dihitung sekali, baris anggota grup tidak dihitung. |
| `success_purchases` | Transaksi Berhasil | dibuat | Pembelian Success, tanpa klaim. |
| `pending_purchases` | Pending | dibuat | Pembelian Pending. |
| `unsuccessful_purchases` | Tidak Berhasil | dibuat | Expired + Canceled + Failed. |
| `revenue` | Omzet | bayar (tanggal bayar valid dari Success) | Jumlah `net_nominal`, tanpa klaim dan anggota grup. |
| `transaction_contribution` | Kontribusi Transaksi (%) | dibuat | `success_purchases` produk ÷ `totals.success_purchases` × 100. |
| `revenue_contribution` | Kontribusi Omzet (%) | bayar | `revenue` produk ÷ `totals.revenue` × 100. |

- **Identitas produk:** satu baris per platform + nama produk. `key` berbentuk `<slug platform>:<nama ternormalisasi>`, misalnya `jadiasn:paket skd`. Produk bernama sama di platform berbeda tetap dua baris.
- **Normalisasi nama:** huruf besar/kecil dan spasi ganda diabaikan. Label `product` adalah penulisan yang paling sering dipakai. Nama kosong menjadi `Produk Tanpa Nama` dengan `is_unnamed: true`.
- **Baris yang tampil:** produk dengan Total Transaksi > 0 atau pembayaran Success pada periode aktif. Produk yang dibuat sebelum periode tetapi dibayar di dalam periode tampil dengan Total 0 dan Omzet terisi.
- **Rincian:** `total_breakdown` berisi `purchase_attempts` dan `claim_checkouts` (jumlahnya sama dengan Total). `unsuccessful_breakdown` berisi `expired`, `canceled`, `failed` (jumlahnya sama dengan Tidak Berhasil).

## Bentuk respons

```json
{
    "data": {
        "period": { "...": "ResolvedPeriod, lihat performance-filter.md" },
        "active": { "availability": "complete", "as_of": "2026-11-11T00:00:00+07:00" },
        "is_empty": false,
        "message": null,
        "columns": [
            { "key": "product", "label": "Produk", "unit": null, "date_basis": null },
            { "key": "total_transactions", "label": "Total Transaksi", "unit": "count", "date_basis": "created" },
            "... urutan sesuai tabel aturan"
        ],
        "totals": { "products": 2, "total_transactions": 4, "success_purchases": 2, "revenue": 400000 },
        "contribution": { "transaction": "available", "revenue": "available" },
        "query": { "search": null, "sort": "revenue", "direction": "desc" },
        "rows": [
            {
                "key": "jadiasn:produk a",
                "product": "Produk A",
                "is_unnamed": false,
                "platform": { "id": 1, "slug": "jadiasn", "name": "JADIASN" },
                "total_transactions": 3,
                "success_purchases": 1,
                "pending_purchases": 1,
                "unsuccessful_purchases": 0,
                "revenue": 300000,
                "transaction_contribution": 50,
                "revenue_contribution": 75,
                "total_breakdown": { "purchase_attempts": 2, "claim_checkouts": 1 },
                "unsuccessful_breakdown": { "expired": 0, "canceled": 0, "failed": 0 },
                "unknown_status_count": 0,
                "success_payments": 1
            }
        ],
        "pagination": { "current_page": 1, "per_page": 10, "total": 2, "last_page": 1, "from": 1, "to": 2 }
    }
}
```

`pagination.total` adalah jumlah baris setelah `search`. `totals.products` adalah jumlah produk dalam filter utama.

## Pembagi nol dan data belum tersedia

| Keadaan | `contribution.*` | Nilai |
|---|---|---|
| Pembagi > 0 | `available` | Persentase per baris. |
| Tidak ada pembelian Success (atau Omzet 0) dalam filter utama | `zero_divisor` | Kontribusi per baris `null`, `totals.*` berisi 0. |
| Data periode aktif belum tersedia | `unavailable` | `rows: []`, `totals.*` `null`, `message: "Data periode aktif belum tersedia."` |

Periode aktif tanpa transaksi: `is_empty: true`, `message: "Tidak ada data produk untuk filter yang dipilih."`.

## Dataset ekspor

`App\Services\ProductPerformanceService::all(ResolvedPeriod)` mengembalikan dataset yang sama tanpa `search`, `query`, dan `pagination`, berisi seluruh produk dalam filter utama dengan urutan default (Omzet terbesar). Sheet Performa Produk pada laporan Dashboard (FIX-14) memakai method ini.
