# Kontrak Tanggal Transaksi dan Kelengkapan Data

Kontrak baca internal untuk Analisis Performa: arti setiap waktu transaksi, status laporan, nilai uang, dan metadata kelengkapan data per platform dan dataset. Kartu, grafik, tabel, dan file ekspor memakai kontrak yang sama agar angkanya konsisten.

Contoh nilai lengkap (input dan nilai harapan) ada di [`tests/Fixtures/contracts/transaction-dates-coverage.json`](../tests/Fixtures/contracts/transaction-dates-coverage.json) dan diperiksa oleh `tests/Unit/TransactionDatesCoverageContractTest.php`.

Angka waktu, tanggal, jumlah, dan nominal di dokumen ini adalah contoh data test. Jam 14.00 dan 13.00 hanya contoh; batas sebenarnya selalu mengikuti metadata kelengkapan platform yang dipilih.

## Pembagian tanggung jawab

| Bagian | Pemilik |
|---|---|
| Kontrak baca, arti status, dan arti cakupan (dokumen ini) | FIX-02-BE-01 |
| Rumus dan query agregasi | FIX-02-BE-02 |
| Resolver batas cakupan dan indikator refresh di header | FIX-04 (FIX-04-BE-01) |
| DBML/DDL dan migrasi | FIX-15 |
| Penulisan dari sumber, pemetaan field API | Epic Pengambilan Data Markaz |

Nama kolom di dokumen ini adalah nama kontrak. Nama penyimpanan final ditetapkan di FIX-15. Kontrak baca ini mendahului perubahan DDL.

## Aturan umum

- Semua waktu ditulis ISO 8601 dengan offset WIB, contoh `2026-11-10T23:50:00+07:00`. Tanggal `Y-m-d`, jam `H:i:s`.
- Pengelompokan memakai waktu sumber (Markaz). Batas laporan (awal/akhir hari, jam potong) memakai WIB.
- Nilai uang dalam Rupiah (IDR), tanpa pembagi (bukan ribuan), maksimal 2 desimal. Contoh: `182000` berarti Rp182.000.
- Jumlah (checkout, pembeli, downloader) adalah bilangan bulat.
- Persentase dibulatkan 2 desimal. Bernilai `null` jika pembaginya 0.

## Transaksi

Key transaksi adalah `(platform_id, markaz_transaction_id)`.

| Field | Tipe | Null | Arti |
|---|---|---|---|
| `platform_id` | integer | Tidak | Platform pemilik transaksi |
| `markaz_transaction_id` | string | Tidak | ID transaksi dari Markaz |
| `markaz_ref_id` | string | Ya | `ref_id` dari API. Terisi berarti baris **anggota grup**; nilainya `markaz_transaction_id` baris pembeli pada `platform_id` yang sama |
| `markaz_customer_id` | integer | Tidak | ID akun pembayar di Markaz. Identitas Pembeli Unik adalah `(platform_id, markaz_customer_id)`, bukan nama atau email |
| `markaz_created_at` | datetime | Tidak | Waktu transaksi dibuat di Markaz |
| `markaz_paid_at` | datetime | Ya | Nilai `paid_date` **terbaru** dari API apa adanya. Kosong jika Markaz belum mengirim tanggal bayar |
| `paid_date` | date | Ya | Tanggal bayar **valid terakhir** untuk laporan |
| `paid_time` | time | Ya | Jam bayar valid terakhir untuk laporan, pasangan `paid_date` |
| `synced_at` | datetime | Tidak | Waktu SiMarsel terakhir mengambil transaksi ini |
| `source_status` | status | Ya | Field `status` dari API Markaz |
| `effective_status` | status | Ya | Status pengganti `source_status` untuk laporan |
| `is_claim_coupon` | boolean | Tidak | Checkout klaim kupon |
| `product_price` | uang | Tidak | Harga paket setelah diskon paket |
| `voucher_discount` | uang | Tidak | Potongan voucher |
| `admin_fee` | uang | Tidak | Biaya admin |
| `nominal` | uang | Tidak | Tagihan |
| `net_nominal` | uang | Tidak | Dasar kontribusi omzet |

### Dasar tanggal

| Waktu | Dipakai untuk | Tidak dipakai untuk |
|---|---|---|
| `markaz_created_at` | Total checkout, Konversi, rincian status | Metrik pembayaran |
| `paid_date` + `paid_time` | Metrik pembayaran: omzet, pembayaran berhasil, AOV, kontribusi, Pembeli Unik | Total dan Konversi |
| `markaz_paid_at` | Sumber pembaruan `paid_date`/`paid_time` | Pengelompokan langsung |
| `synced_at` | Catatan kapan data diambil | Tanggal kejadian apa pun |

Contoh lintas hari: transaksi dibuat 10 November 23.50 dan dibayar 11 November 00.10 WIB masuk **Total tanggal 10** dan **Omzet tanggal 11**.

### Tanggal bayar valid terakhir

- Jika status laporan Success dan `markaz_paid_at` valid, `paid_date`/`paid_time` mengikuti `markaz_paid_at`.
- Jika `markaz_paid_at` terbaru kosong, `paid_date`/`paid_time` tetap memakai nilai valid terakhir.
- Jika belum pernah ada tanggal bayar valid, keduanya tetap kosong. Transaksi Success itu tetap masuk daftar dan Konversi, tetapi belum masuk metrik pembayaran.
- Pending dengan tanggal bayar kosong tetap dapat dibaca.

### Status laporan

Status laporan = `effective_status` jika terisi (**termasuk nilai 0**), lalu `source_status`. Diimplementasikan di `App\Enums\MarkazTransactionStatus::forReport()`.

| Kode | Label | Kelompok |
|---|---|---|
| `0` | Pending | Pending |
| `1` | Success | Success |
| `2` | Expired | Tidak Berhasil |
| `3` | Failed | Tidak Berhasil |
| `4` | Canceled | Tidak Berhasil |
| `null` | Status belum tersedia | Tanpa status |

Status kosong **berbeda** dari Pending. Kedaluwarsa ditentukan dari status yang diberikan Markaz, tidak dihitung sendiri.

| `effective_status` | `source_status` | Status laporan |
|---|---|---|
| `2` | `0` | Expired |
| `0` | `1` | Pending |
| `null` | `0` | Pending |
| `null` | `3` | Failed |
| `null` | `4` | Canceled |
| `null` | `null` | `null` (Status belum tersedia) |

### Klaim dan grup

- Satu pembelian = satu checkout, termasuk klaim kupon (`is_claim_coupon = true`).
- Klaim masuk Total, tetapi keluar dari pembilang dan penyebut Konversi.
- Pembelian grup memakai tanggal, status, nominal, dan akun pada **baris pembeli** (`markaz_ref_id` kosong). Anggota ditemukan dengan `platform_id` sama dan `markaz_ref_id` = `markaz_transaction_id` pembeli. Anggota hanya tampil di detail pembeli dan tidak menambah checkout, pembeli, maupun omzet.

### Nilai uang

| Dipakai untuk | Field |
|---|---|
| Omzet, kontribusi, AOV | `net_nominal` dari Success dengan tanggal bayar valid |
| Kolom Nominal daftar, rincian nominal status | `nominal` |

Contoh: dua Success dengan `nominal` 182.000 dan 185.000, `admin_fee` 2.000 dan 5.000, `net_nominal` masing-masing 180.000 menghasilkan omzet 360.000, rincian nominal 367.000, dan AOV 180.000.

### Konversi

```
Konversi = Success (bukan klaim) ÷ seluruh percobaan pembelian (bukan klaim) yang dibuat dalam periode × 100
```

- Pembelian tanpa status tetap masuk Total dan penyebut.
- Jika ada pembelian tanpa status, keluarkan `unknown_status_count` > 0 dan `is_provisional = true` (label **Sementara**).
- Penyebut 0 (misalnya hanya klaim) menghasilkan `null`, bukan 0.

| Kasus | Total | Percobaan | Klaim | Success | Tanpa status | Konversi | Sementara |
|---|---|---|---|---|---|---|---|
| 6 Success, 3 Pending, 1 tanpa status | 10 | 10 | 0 | 6 | 1 | 60.0 | Ya |
| Yang tanpa status menjadi Success | 10 | 10 | 0 | 7 | 0 | 70.0 | Tidak |
| 1 Success, 1 Pending, 1 klaim Success | 3 | 2 | 1 | 1 | 0 | 50.0 | Tidak |
| Hanya klaim | 1 | 0 | 1 | 0 | 0 | `null` | Tidak |

## Total Downloader

```json
{
    "platform_id": 1,
    "dataset": "downloaders",
    "date": "2026-11-15",
    "total": 120,
    "unit": "downloader",
    "coverage": { "complete_until": "2026-11-16T00:00:00+07:00", "availability": "complete" }
}
```

## Metadata kelengkapan

Satu baris per `(platform_id, dataset)` untuk periode yang dihitung. Dataset: `transactions` atau `downloaders`.

| Field | Tipe | Null | Arti |
|---|---|---|---|
| `platform_id` | integer | Tidak | Platform yang dicakup |
| `dataset` | string | Tidak | `transactions` atau `downloaders` |
| `date_from`, `date_to` | date | Tidak | Periode yang dihitung (inklusif) |
| `complete_until` | datetime | Ya | Batas atas **eksklusif** data lengkap: semua kejadian sebelum jam ini sudah lengkap. Kosong berarti kelengkapan belum diketahui |
| `availability` | string | Tidak | `complete`, `partial`, atau `unavailable` |
| `sync_finished_at` | datetime | Ya | Jam job penarikan selesai. Informasi saja |

`sync_finished_at` **bukan** bukti kelengkapan. Contoh: job platform B selesai 14.20, tetapi data B baru lengkap sampai 13.00.

### Status ketersediaan

| `availability` | Syarat | Nilai yang dikeluarkan |
|---|---|---|
| `complete` | `complete_until` ≥ akhir `date_to` (00.00 hari berikutnya) | Angka penuh. Periode tanpa transaksi menghasilkan **0** |
| `partial` | `complete_until` di dalam periode | Angka sampai `complete_until`, beserta jamnya |
| `unavailable` | `complete_until` kosong atau ≤ awal `date_from` | `null` (belum tersedia), **bukan** 0 |

### Gabungan platform

Batas bersama = `complete_until` paling awal di antara semua platform terpilih, untuk dataset dan periode yang sama, dibatasi akhir periode. Jika satu platform `unavailable`, gabungannya `unavailable`.

| Kasus | Platform | Batas bersama |
|---|---|---|
| Hari ini | A sampai 14.00, B sampai 13.00 | 13.00 WIB (`partial`) |
| Hari ini, B diganti | A sampai 14.00, B sampai 15.00 | 14.00 WIB (`partial`) |
| Kemarin | A dan B sudah melewati 00.00 hari ini | 00.00 hari ini (`complete`) |
| Downloader kemarin | A lengkap, B tanpa metadata | `null` (`unavailable`) |

Implementasi resolver dimiliki FIX-04-BE-01. Tabel ini adalah nilai harapan.

## Catatan pembanding (`dashboard_snapshots`)

| Field | Tipe | Null | Arti |
|---|---|---|---|
| `platform_id` | integer | Tidak | Platform pemilik catatan |
| `dataset` | string | Tidak | Dataset yang direkap |
| `date_from`, `date_to` | date | Tidak | Periode rekap (inklusif) |
| `cutoff_at` | datetime | Tidak | Jam rekap: kejadian sebelum jam ini |
| `is_complete` | boolean | Tidak | `true` jika data platform dan dataset ini lengkap sampai `cutoff_at` saat direkam. Catatan `false` tetap tersimpan, tetapi tidak dipakai sebagai pembanding |
| `payload` | object | Tidak | Angka hasil rekap pada `cutoff_at` |
| `recorded_at` | datetime | Tidak | Waktu catatan disimpan, bukan jam rekap |

```json
{
    "platform_id": 1,
    "dataset": "transactions",
    "date_from": "2026-11-15",
    "date_to": "2026-11-15",
    "cutoff_at": "2026-11-15T14:00:00+07:00",
    "is_complete": true,
    "payload": {
        "total_checkouts": 10,
        "purchase_attempts": 10,
        "success_purchases": 6,
        "unknown_status_count": 0,
        "conversion_percentage": 60.0,
        "is_provisional": false
    },
    "recorded_at": "2026-11-15T14:07:00+07:00"
}
```

- Pembanding jam setara (misalnya Hari Ini sampai 14.00) membaca `payload` catatan **lengkap** (`is_complete = true`) dengan `cutoff_at` yang setara dan mengeluarkan `availability = complete` dan `as_of` = `cutoff_at`. Perubahan status setelah jam itu tidak mengubah angka catatan.
- Periode hari penuh (Kemarin, 7/30/90 Hari Terakhir) memakai status dan tanggal laporan terbaru, bukan catatan.
- Jika catatan yang diperlukan tidak ada, atau ada tetapi `is_complete = false`, pembanding belum tersedia: `availability = unavailable`, `as_of = null`, `values = null`. Catatan parsial tetap tersimpan dan dikenali sebagai belum lengkap, tetapi angkanya tidak boleh dipakai sebagai pembanding.
- Untuk gabungan platform, setiap platform terpilih harus punya catatan lengkap. Satu catatan yang hilang atau belum lengkap membuat seluruh pembanding `unavailable`.

| Catatan jam setara | `availability` | `as_of` | `values` |
|---|---|---|---|
| Lengkap pada semua platform terpilih | `complete` | `cutoff_at` | Angka catatan (dijumlahkan antarplatform) |
| Ada yang `is_complete = false` | `unavailable` | `null` | `null` |
| Ada yang tidak tersimpan | `unavailable` | `null` | `null` |

## Agregasi bersama (FIX-02-BE-02)

Diimplementasikan di `App\Services\TransactionAggregationService` (`aggregate()`, `aggregateDaily()`, `buyerRows()`, `membersOf()`) dan `App\Services\ComparisonSnapshotService` (`compare()`, `read()`), dan diperiksa oleh `tests/Unit/TransactionAggregationTest.php` dan `tests/Unit/ComparisonSnapshotTest.php`.

Input: baris transaksi berbentuk kontrak di atas (`App\Support\TransactionAggregation\TransactionRecord::fromArray()`) dan baris `dashboard_snapshots`. Agregasi hanya membaca keadaan tersimpan dan tidak memanggil Markaz. Query database disambungkan setelah migrasi FIX-15 tersedia.

Filter (`App\Support\TransactionAggregation\AggregationFilter`): `platform_ids` (wajib, tidak kosong), `date_from`/`date_to` WIB inklusif, `basis` (`created` atau `paid`), dan `complete_until` dari resolver FIX-04-BE-01. Ketersediaan dan cutoff diturunkan dari `complete_until` sesuai tabel status ketersediaan. Kejadian dihitung jika `periode awal ≤ waktu < cutoff`.

| `basis` | Waktu kelompok | Baris yang dihitung |
|---|---|---|
| `created` | `markaz_created_at` | Baris pembeli dengan semua status |
| `paid` | `paid_date` + `paid_time` (jam kosong = 00.00) | Baris pembeli Success dengan tanggal bayar valid, bukan klaim |

Output `aggregate()`: `basis`, `platform_ids`, `date_from`, `date_to`, `availability`, `as_of` (cutoff, `null` jika `unavailable`), dan `metrics`. `aggregateDaily()` mengeluarkan satu output per hari dengan ketersediaannya masing-masing.

| `metrics` dasar `created` | Arti |
|---|---|
| `total_checkouts` | Pembelian semua status + tanpa status + klaim |
| `purchase_attempts`, `claim_checkouts` | Penyebut Konversi, dan klaim di luar Konversi |
| `success_purchases`, `unsuccessful_purchases` | Success, dan Expired + Failed + Canceled |
| `unknown_status_count`, `unknown_status_nominal` | Pembelian tanpa status |
| `conversion_percentage`, `is_provisional` | Konversi dan label Sementara |
| `status_breakdown.{pending,success,expired,failed,canceled}` | `count` dan `nominal` (memakai `nominal`) per status, tanpa klaim |

| `metrics` dasar `paid` | Arti |
|---|---|
| `success_payments` | Pembayaran Success |
| `revenue` | Jumlah `net_nominal` |
| `nominal_total` | Jumlah `nominal` |
| `average_order_value` | `revenue ÷ success_payments` |
| `unique_buyers` | `(platform_id, markaz_customer_id)` unik sepanjang rentang |

Jika `availability = unavailable`, semua nilai `metrics` bernilai `null`.

### Pembanding

`ComparisonSnapshotService::compare()` memakai filter periode pembanding:

| Ketersediaan filter | Sumber (`source`) | Nilai |
|---|---|---|
| `partial` (jam setara, misalnya Kemarin sampai 14.00) | `snapshot` | `payload` catatan lengkap dengan `cutoff_at` = cutoff. `unavailable` dan `null` jika catatan tidak ada atau `is_complete = false` |
| `complete` (Kemarin, 7/30/90 Hari Terakhir) | `latest` | Agregasi dari status dan tanggal laporan terbaru |
| `unavailable` | `null` | `null` |

Pembacaan catatan memerlukan satu catatan untuk setiap platform terpilih dengan `dataset`, `date_from`, `date_to`, dan `cutoff_at` yang sama persis. Jika ada beberapa catatan, yang dipakai adalah `recorded_at` terbaru. Jumlah dan nominal antarplatform dijumlahkan, sedangkan `conversion_percentage`, `is_provisional`, dan `average_order_value` dihitung ulang. Hanya catatan lengkap yang boleh dipakai sebagai pembanding jam setara. Jika satu platform terpilih tidak punya catatan, atau catatan terbarunya `is_complete = false`, hasilnya `availability = unavailable`, `as_of = null`, dan `values = null` tanpa angka, termasuk saat platform lain lengkap. Catatan parsial tetap dikenali sebagai catatan yang belum lengkap, tetapi angkanya tidak dipakai. Aturan ini khusus catatan pembanding; agregasi periode berjalan dengan cutoff parsial tetap `partial` beserta angkanya. Catatan yang hilang tidak dibentuk ulang dari status terbaru.

## Nol, kosong, dan belum tersedia

| Keadaan | Jumlah | Omzet | Persentase |
|---|---|---|---|
| Periode lengkap tanpa transaksi | `0` | `0` | `null` (pembagi 0) |
| Cakupan belum diketahui | `null` | `null` | `null` |
| Catatan pembanding tidak ada | Pembanding `null`, `availability = unavailable` | | |
| Catatan pembanding belum lengkap (`is_complete = false`) | Pembanding `null`, `availability = unavailable` | | |

## Asumsi yang perlu diverifikasi

- Waktu dari API Markaz (`created_at`, `paid_date`) dikirim dalam WIB. Jika tidak, epic Pengambilan Data Markaz mengonversinya ke WIB sebelum disimpan.
- Tipe `markaz_customer_id` (integer) mengikuti kolom `markaz_user_id` yang ada sekarang dan dikonfirmasi di FIX-15.
- Contoh nilai uang memenuhi `nominal = product_price − voucher_discount + admin_fee` dan `net_nominal = nominal − admin_fee`. Kontrak tidak menghitung ulang nilai ini; nilainya dibaca dari sumber.
- FIX-15-BE-02 menjadikan `paid_date` nullable dan menambah kolom kontrak di `transactions` (lihat [database.md](database.md#kolom-transaksi-dashboard-fix-15-be-02)). Kolom yang wajib di kontrak tetap nullable di database karena histori belum punya nilai sumbernya. Penyimpanan seluruh status dari Markaz tetap milik epic Pengambilan Data Markaz.
