# Kode Promo: Penggunaan dan Riwayat Metadata

Menyimpan penggunaan kode promo dari transaksi sukses Markaz beserta Funnel dan Source dari master saat transaksi pertama kali diproses SIMarsel. Hasil awal tetap disimpan, termasuk jika metadata kosong. Tanggal pembayaran tidak digunakan untuk mencari versi metadata klasifikasi awal.

Struktur tabel dan aturan hapus relasi ada di [database.md](database.md).

## Alur

```
transactions.promo_code (data mentah Markaz, tidak pernah diubah)
        │  disimpan / diperbarui  →  TransactionObserver
        │  backfill               →  php artisan promo-codes:normalize
        ▼
PromoCodeUsageService::normalize()
        │  cocokkan master: platform_id + kode yang dinormalisasi
        │  ambil versi metadata yang berlaku pada waktu bayar transaksi
        ▼
transaction_promo_codes (1 baris per posisi kode, dengan snapshot funnel/source)
```

## Master kode promo (`promo_codes`)

- Unik per `platform_id` + `code`. Kode yang sama di platform lain adalah kode promo yang **berbeda**.
- `funnel_id` dan `source_id` boleh kosong.
- Tidak ada kolom `type` maupun status aktif/nonaktif.
- `code` disimpan dalam bentuk ternormalisasi: tanpa spasi di awal/akhir dan huruf besar, jadi ` cpns26 ` dan `CPNS26` dianggap kode yang sama.
- Buat dan ubah kode promo **selalu lewat `PromoCodeService`**, supaya riwayat versinya ikut tercatat. Factory `PromoCode` hanya membuat baris master, tanpa versi.

## Normalisasi penggunaan kode (`transaction_promo_codes`)

| Aturan | Perilaku |
|---|---|
| Kapan dijalankan | Setiap transaksi disimpan atau `promo_code`, `platform_id`, `paid_date`, `paid_time`-nya berubah (observer), dan lewat command backfill |
| Bentuk `promo_code` | Array teks dari Markaz, misalnya `["CPNS26", "RESELLER01"]`. Satu teks (`"CPNS26"`) dianggap posisi 1. |
| Posisi | Mengikuti urutan array Markaz: `1` = kode utama, `2` = kode kedua/reseller. Entri kosong tidak dibuatkan baris, tapi posisinya tetap dihitung (`["", "X"]` → `X` di posisi 2). |
| Transaksi tanpa kode | Tidak dibuatkan baris |
| `raw_code` | Nilai asli dari Markaz, apa adanya |
| `code_snapshot` | Kode yang sudah dinormalisasi, dipakai untuk pencocokan dan pengelompokan |
| Kode belum ada di master | Tetap disimpan dengan `promo_code_id = NULL`, transaksi tidak gagal. Bisa dihitung lewat scope `TransactionPromoCode::unmapped()` untuk analisis dan alert. |
| Proses ulang / sinkronisasi ulang | Upsert per `transaction_id` + `code_position`, sehingga tidak pernah ada baris ganda. Posisi yang sudah tidak ada di Markaz dihapus. |
| Lebih dari 2 kode | **Anomali**: simpan raw terbaru dan pertahankan penggunaan terakhir yang valid. Jangan membuat/mengubah atribusi dari payload invalid. Tandai `transactions.promo_code_anomaly = true`; catat detail di `storage/logs/promo-code-anomalies.log`. |
| Format tidak dikenali | Misalnya objek, bukan array teks. Diperlakukan sama seperti anomali di atas. |

Transaksi anomali dikecualikan dari seluruh query analisis promo (termasuk denominator persentase), alert, dan backfill pendaftaran master. Transaksi tersebut tidak dihitung sebagai promo valid maupun Tanpa Promo/Tanpa Kode. Data transaksi tetap tersedia untuk Ringkasan Performa umum. Tidak ada tabel anomali atau UI baru.

Jika Markaz mengirim payload valid kembali, normalisasi menghapus penanda anomali dan memproses penggunaan terbaru. Kode dan posisi yang sama mempertahankan snapshot terakhir; kode/platform yang benar-benar berubah mendapat klasifikasi awal baru. Payload valid tanpa kode menghapus penggunaan yang sudah tidak berlaku. Proses ini idempotent.

## Riwayat metadata (`promo_code_versions`)

- Membuat kode promo membuka versi pertama dengan `effective_from` = saat disimpan.
- Mengubah funnel/source menutup versi aktif (`effective_until` = saat disimpan) dan membuka versi baru dari detik yang sama. User **tidak pernah** mengisi tanggal efektif.
- Menyimpan funnel/source yang sama tidak membuat versi baru. Dua perubahan di detik yang sama memperbarui versi detik itu.
- Setiap versi menyimpan nama funnel/source saat itu (`*_name_snapshot`), jadi tetap terbaca walaupun master funnel/source diganti namanya.

## Snapshot di setiap penggunaan

- Waktu transaksi = `paid_date` + `paid_time` (WIB; `paid_time` kosong dianggap 00:00:00).
- Snapshot diambil dari versi yang berlaku pada waktu itu: `effective_from` ≤ waktu transaksi < `effective_until`.
- **Tidak ada versi** untuk waktu itu (misalnya transaksi sebelum kode didaftarkan): snapshot dibiarkan kosong. Sistem tidak mengarang funnel/source. Nilai kosong dikelompokkan sebagai **Tanpa Funnel** / **Tanpa Sumber** di analisis (scope `TransactionPromoCode::unclassified()`).
- Penggunaan yang **sudah punya klasifikasi tidak pernah ditimpa**, baik saat master diedit maupun saat transaksi disinkronkan ulang. Hanya transaksi setelah waktu efektif versi baru yang memakai metadata baru.

## Pendaftaran kode (Single Entry dan alert)

`PromoCodeService::register(platformId, code, funnelId, sourceId)`, dipakai oleh `POST /api/promo-codes` dan `registerFromAlert()`:

1. Membuat kode promo beserta versi pertamanya.
2. Menghubungkan **semua** penggunaan lama dengan platform + kode yang sama yang **belum terpetakan** (`promo_code_id` kosong) ke kode baru ini.
3. Penggunaan yang belum terklasifikasi dilengkapi dengan funnel/source yang didaftarkan. Penggunaan yang **sudah punya klasifikasi** hanya dihubungkan; snapshot funnel/source-nya **tidak ditimpa**.
4. `raw_code` dan `code_position` tidak pernah diubah.

## Alert kode belum terdaftar

`GET /api/promo-codes/unregistered` (lihat [api/promo-codes.md](api/promo-codes.md)) merekap kode yang muncul di transaksi tetapi **belum punya master** untuk platformnya:

- Dikelompokkan per platform + kode yang dinormalisasi; semua penulisan asli dari Markaz ditampilkan di `raw_codes`.
- Jumlah penggunaan dihitung dari baris `transaction_promo_codes` (satu transaksi bisa memakai kode yang sama di dua posisi).
- Kode yang **sudah terdaftar tapi belum pernah dipakai tidak muncul** di alert; kode itu ada di daftar master dengan `usages_count = 0`. Kode yang sudah punya master juga tidak muncul, walaupun ada penggunaan yang belum terhubung.
- Transaksi dengan kode belum terdaftar tetap tersimpan dan tetap dihitung di analisis (scope `TransactionPromoCode::unmapped()`).
- Mendaftarkan kode dari alert memakai `POST /api/promo-codes` (Single Entry) dengan data `register` dari alert. Setelah berhasil, kode itu hilang dari alert; kode lain yang belum terpetakan tetap tampil.
- Alert tidak memakai status aktif/nonaktif, karena field itu tidak ada.

## Edit dan hapus kode

`PromoCodeService::update()` dan `delete()`, dipakai oleh API [api/promo-codes.md](api/promo-codes.md):

- **Funnel/source** selalu bisa diubah dan membuka versi baru.
- **Kode/platform** hanya bisa diubah selama kode belum pernah dipakai transaksi, karena penggunaan transaksi dicocokkan dari platform + kode. Setelah diubah, penggunaan lama yang belum terpetakan untuk kode baru ikut dihubungkan.
- **Hapus** hanya untuk kode yang belum pernah dipakai. Riwayat versinya dihapus secara eksplisit di service, karena hanya menjelaskan kode itu sendiri. Penggunaan transaksi tidak pernah ikut terhapus: relasinya `restrict` di database.

### Request bersamaan (concurrency)

Identitas kode (kode + platform) tidak boleh berubah setelah dipakai transaksi, juga ketika edit dan normalisasi transaksi berjalan bersamaan:

- `update()` dan `delete()` mengunci baris kode promo (`SELECT ... FOR UPDATE`) **di dalam transaksi yang sama** dengan perubahannya, lalu menghitung ulang jumlah penggunaan tepat sebelum mengubah atau menghapus.
- Normalisasi mencari master kode dengan shared lock, dan penyimpanan penggunaan memegang lock baris kode lewat foreign key.
- Hasilnya: jika penggunaan sedang disimpan, edit/hapus menunggu lalu ditolak (409); jika identitas sedang diganti, normalisasi menunggu lalu tidak lagi menemukan kode lama (penggunaan disimpan sebagai kode belum terdaftar).
- Validasi unik platform + kode dicek sebelum menyimpan, sehingga dua request bersamaan bisa sama-sama lolos. Index unik di database menolak yang kedua, dan service mengubahnya menjadi **422** dengan pesan validasi yang sama (`Kode promo CPNS26 sudah terdaftar di platform JADIASN.`), bukan 500.

Dibuktikan oleh `tests/Feature/Promo/PromoCodeConcurrencyTest.php` (dua koneksi MySQL sungguhan; dilewati di SQLite karena tidak punya row lock).

## Command

```bash
php artisan promo-codes:normalize            # backfill semua transaksi yang punya kode promo
php artisan promo-codes:normalize --chunk=1000
```

Aman dijalankan berkali-kali. Jalankan setelah import massal atau seeder, karena insert massal tidak memicu observer.
