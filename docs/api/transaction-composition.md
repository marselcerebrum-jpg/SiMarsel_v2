# API Status Transaksi dan Perdana vs Perpanjangan

Dataset dua grafik batang horizontal Analisis Performa (FIX-09). Keduanya memakai aturan baca transaksi bersama (FIX-02-BE-02) dan resolver periode bersama (FIX-04). Layar dan ekspor memakai dataset yang sama.

Wajib login. Semua role boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md).

## Query parameter

Sama dengan [filter Ringkasan Performa](performance-filter.md), tetapi `preset` **wajib** karena kedua dataset membaca periode aktif dan pembanding dari resolver periode bersama (`comparison`, `comparison_from`, `comparison_to`, `date_from`, `date_to`, `platforms[]` tetap berlaku).

Kedua sisi (`active` dan `comparison`) selalu dikirim jika periode punya pembanding. Tombol **Tampilkan Pembanding** cukup menampilkan atau menyembunyikan sisi `comparison` di FE, sehingga pembanding satu grafik tidak memengaruhi grafik lain.

## `GET /api/performance/transaction-statuses`

Kelompok **tanggal transaksi dibuat** (`markaz_created_at`, WIB). Semua checkout baris pembeli masuk, termasuk klaim kupon. Baris anggota grup (`markaz_ref_id` terisi) tidak dihitung. Status laporan = `effective_status`, jika kosong `source_status`. Klaim kupon masuk ke batang sesuai status laporannya.

| Field | Isi |
|---|---|
| `statuses[]` | Lima batang berurutan `success`, `pending`, `expired`, `canceled`, `failed` |
| `statuses[].active.count` | Pembelian + klaim pada status itu |
| `statuses[].active.purchase_count` / `claim_count` | Rincian pembelian dan klaim, untuk ditampilkan di dekat batang |
| `statuses[].active.share_percentage` | `count` ÷ `active.totals.total_checkouts` × 100, 2 desimal. `null` jika total 0 |
| `statuses[].active.nominal` | **Total Nominal Transaksi** (`nominal_label`): jumlah `nominal` pada status itu |
| `statuses[].share_change_percentage_point` | Porsi aktif − porsi pembanding (poin persen). `null` jika salah satu tidak diketahui |
| `unsuccessful` | Subtotal **Tidak Berhasil** = Expired + Canceled + Failed, dengan bentuk yang sama |
| `unknown_status` | Checkout tanpa status (`Status belum tersedia`), untuk keterangan di bawah grafik |
| `active.totals` | `total_checkouts`, `purchase_attempts`, `claim_checkouts` |

Penyebut porsi adalah seluruh checkout kelompok, termasuk checkout tanpa status. Jumlah kelima batang + `unknown_status.count` = `total_checkouts`.

## `GET /api/performance/product-types`

Query tambahan `metric`: `success_payments` (default, **Transaksi Berhasil**) atau `revenue` (**Omzet**).

Klaim kupon dan baris anggota grup dikeluarkan dari ketiga metrik. Transaksi tanpa promo tetap dihitung.

| Field | Isi |
|---|---|
| `product_types[]` | `Perdana` (`first_purchase`) dan `Perpanjangan` (`renewal`) selalu ada. Tipe lain (`other`, A-Z per nama) dan tipe kosong (`unknown`, `Tidak diketahui`) muncul sebagai kelompok tersendiri jika ada pembelian atau pembayaran |
| `active.success_payments` | Pembayaran Success dengan tanggal bayar valid di periode (kelompok **tanggal bayar**) |
| `active.revenue` | Jumlah `net_nominal` pembayaran tersebut |
| `active.value` | Nilai metrik terpilih (`metric`) |
| `active.share_percentage` | `value` ÷ total metrik terpilih semua tipe × 100. `null` jika total 0 |
| `active.conversion.value` | Pembelian Success ÷ seluruh percobaan pembelian (termasuk status kosong) pada kelompok **tanggal dibuat**. Tidak bergantung pada `metric` |
| `active.conversion.is_provisional` / `provisional_label` / `unknown_status_count` | Jika ada pembelian tanpa status: `true`, `Sementara`, dan jumlahnya |
| `share_change_percentage_point` | Porsi aktif − porsi pembanding untuk metrik terpilih |
| `active.totals` | Total `value`, `success_payments`, `revenue`, `success_purchases`, `purchase_attempts`, `unknown_status_count` |

Kecocokan Perdana dan Perpanjangan mengabaikan spasi di awal/akhir dan huruf besar/kecil, sama seperti perbandingan di database.

## Ketersediaan data

Setiap sisi punya `availability` (`complete`, `partial`, `unavailable`), `as_of`, dan `source`.

- Periode lengkap tanpa transaksi: nilai `0`, porsi dan konversi `null` (tidak ada pembagi), `is_empty: true`.
- Cakupan belum diketahui: semua nilai sisi itu `null`, bukan `0`.
- Pembanding jam setara (`state_basis: at_cutoff`, misalnya preset `today`): sisi pembanding `unavailable` dengan `source: snapshot`. Snapshot transaksi yang tercatat belum menyimpan rincian klaim per status maupun tipe produk, dan nilai pembanding tidak dibangun ulang dari status terbaru.

## 422 Validasi gagal

`preset` kosong atau tidak valid, `metric` di luar `success_payments`/`revenue`, dan semua aturan [filter Ringkasan Performa](performance-filter.md#422-validasi-gagal).
