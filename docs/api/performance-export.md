# API Export Ringkasan Performa

Mengunduh data Ringkasan Performa sesuai filter yang sedang aktif, dalam bentuk Excel atau CSV. Angkanya diambil dari service yang sama dengan dashboard, jadi selalu sama dengan yang tampil di layar.

Wajib login. Semua role (Manager dan Employee) boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md).

## `GET /api/performance/export`

### Query parameter

| Parameter | Wajib | Nilai |
|---|---|---|
| `format` | Ya | `xlsx` (Excel) atau `csv` |
| Filter platform dan periode | Tidak | Sama persis dengan [filter Ringkasan Performa](performance-filter.md) |

Contoh: `GET /api/performance/export?format=xlsx&years[]=2025&years[]=2026&platforms[]=jadiasn`

Di frontend, cukup arahkan browser ke URL tersebut (misalnya `window.location.href = url` atau `<a href="...">`), karena cookie session ikut terkirim. Tidak perlu `fetch()`.

### 200 OK

File langsung diunduh dengan nama `performa_{date_from}_{date_to}.xlsx` atau `.csv`, contohnya `performa_2026-09-01_2026-09-30.xlsx`.

| Format | Isi |
|---|---|
| `xlsx` | Satu file, 5 sheet, atau 6 sheet dengan `preset` (lihat tabel di bawah) |
| `csv` | Satu file berisi kelima bagian secara berurutan. Setiap bagian diawali judulnya dan dipisah satu baris kosong. Memakai UTF-8 dengan BOM, supaya langsung terbaca benar di Excel. |

| Sheet / bagian | Kolom | Sumber |
|---|---|---|
| Ringkasan KPI | Periode, Periode Sebelumnya, dan Platform; lalu tabel KPI, Nilai, Nilai Periode Sebelumnya, Perubahan (%), Tren | `/summary` |
| Tren | Periode, Tanggal Mulai, Tanggal Akhir, Revenue, Transactions, Downloader, Conversion (%), User Baru, Repeat Order | `/trend` untuk semua metric |
| Downloader | Periode Aktif, Downloader, Periode Pembanding, Downloader Pembanding, Perubahan (%); lalu Total dan tabel Platform, Downloader, Persentase (%). Hanya untuk request dengan `preset` | `/trend/downloaders` dengan `granularity` default |
| Metode Pembayaran | Metode Pembayaran, Jumlah Transaksi, Revenue, Persentase Transaksi (%), Persentase Kontribusi (%) | `/payment-methods` |
| Kontribusi Platform | Peringkat, Platform, Revenue, Transaksi, Pembeli Unik, Persentase Kontribusi (%) | `/platform-contribution` |
| Waktu Pembelian | Rentang Waktu, Jam Mulai, Jam Akhir, Jumlah Transaksi, Persentase (%) | `/purchase-times` |

- Rincian sheet **Tren** mengikuti rincian default filter: harian untuk default, rentang tanggal, dan bulan; bulanan untuk tahun dan rentang tahun. Rentang tanggal yang lebih dari 366 hari diekspor per bulan.
- Angka disimpan sebagai angka (bukan teks), jadi bisa langsung dijumlahkan di Excel. Nilai kosong (misalnya konversi tanpa downloader, atau periode setelah hari ini) dibiarkan kosong.
- Pada sheet **Downloader**, nilai yang belum tersedia ditulis `Belum tersedia`, sedangkan `0` dari sumber tetap `0`.
- Persentase kontribusi platform yang tidak bisa dihitung (total revenue 0) ditulis `N/A`.
- Jika filter tidak punya data, file tetap diunduh dengan semua sheet dan nilai `0`.

### 422 Validasi gagal

Respons JSON seperti endpoint lain:

| Kondisi | Pesan |
|---|---|
| `format` kosong | `Format wajib diisi.` |
| `format` selain `xlsx` / `csv` | `Format harus xlsx atau csv.` |
| Filter tidak valid | Sama dengan [filter Ringkasan Performa](performance-filter.md#422-validasi-gagal) |

## `GET /api/performance/export/dashboard` (Ekspor Laporan Dashboard, FIX-14)

Satu file Excel (`laporan_dashboard_{date_from}_{date_to}.xlsx`) berisi sembilan sheet, diurutkan sesuai tiket. Angkanya dibaca dari dataset komponen yang dipakai layar, dengan filter, cutoff, pengelompokan, dan pilihan hari yang sama (`App\Services\DashboardReportService`). Endpoint `/api/performance/export` lama tidak berubah.

| Parameter | Wajib | Nilai |
|---|---|---|
| `preset` dan parameter periode, `platforms[]` | Ya | Sama dengan [filter Ringkasan Performa](performance-filter.md). Tanpa `preset`: `422` |
| `granularity` | Tidak | Pengelompokan sheet Tren dan Downloader. Aturannya sama dengan `/trend/sales` (default per preset, `hourly` maks. 31 hari) |
| `day_of_week` | Tidak | Pilihan hari Jam Ramai (1 = Senin … 7 = Minggu). Aturannya sama dengan `/purchase-times` |

| # | Sheet | Dataset |
|---|---|---|
| 1 | Ringkasan Sales | Metadata laporan, lalu tujuh metrik `sales_summary` (`/summary`) |
| 2 | Tren dan Performa | `/trend/sales` dengan `granularity`: Omzet, Transaksi Berhasil, Total Transaksi, aktif dan pembanding |
| 3 | Kontribusi Platform | `PlatformContributionService::transactionMetrics()` (STEAM-299): mode transaksi dan omzet, rincian status, kontribusi, omzet pembanding, Downloader |
| 4 | Downloader | `/trend/downloaders` dengan `granularity`, total, dan platform |
| 5 | Status Transaksi | `/transaction-statuses`: lima status, pembelian/klaim, porsi, nominal, subtotal Tidak Berhasil, Status belum tersedia |
| 6 | Metode Pembayaran | `method_comparison` (`/payment-methods`): tujuh kolom dengan pembanding, perubahan, unit perubahan, klaim, Status belum tersedia, rincian Tidak Berhasil |
| 7 | Performa Produk | `ProductPerformanceService::all()`: delapan kolom tabel, lalu Expired, Canceled, Failed, Klaim Kupon, Status belum tersedia |
| 8 | Jam Ramai Pembelian | `hourly` (`/purchase-times`) dengan `day_of_week`: 24 jam WIB, aktif dan pembanding |
| 9 | Perdana vs Perpanjangan | `/product-types` dibaca dengan kedua metrik: Transaksi Berhasil, Omzet, porsi masing-masing, Konversi |

- **Metadata (Ringkasan Sales):** periode aktif dan pembanding beserta ketersediaan, batas data (cutoff), keadaan data (`Keadaan terbaru` atau `Keadaan pada cutoff (catatan …)`), dasar perubahan saat dipotong (misalnya 1–28 Maret vs 1–28 Februari), pengelompokan tren, pilihan hari, dan dasar tanggal.
- **Nilai:** aktif, pembanding, dan perubahan ditulis di kolom terpisah, dengan unit perubahan (`Persen (%)` atau `Poin persen`). Saat dasar perubahan dipotong, kolom Nilai Aktif tetap total penuh, sedangkan Nilai Aktif untuk Perubahan memakai hari yang punya padanan.
- **Belum tersedia:** sel nilai dibiarkan kosong, dan kolom Ketersediaan di sebelahnya berisi `Belum tersedia`. Nilai 0 tetap 0.
- **Seri tersembunyi:** semua seri dan sisi pembanding tetap ditulis walaupun disembunyikan di layar.
