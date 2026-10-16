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
| `xlsx` | Satu file, 5 sheet (lihat tabel di bawah) |
| `csv` | Satu file berisi kelima bagian secara berurutan. Setiap bagian diawali judulnya dan dipisah satu baris kosong. Memakai UTF-8 dengan BOM, supaya langsung terbaca benar di Excel. |

| Sheet / bagian | Kolom | Sumber |
|---|---|---|
| Ringkasan KPI | Periode, Periode Sebelumnya, dan Platform; lalu tabel KPI, Nilai, Nilai Periode Sebelumnya, Perubahan (%), Tren | `/summary` |
| Tren | Periode, Tanggal Mulai, Tanggal Akhir, Revenue, Transactions, Downloader, Conversion (%), User Baru, Repeat Order | `/trend` untuk semua metric |
| Metode Pembayaran | Metode Pembayaran, Jumlah Transaksi, Revenue, Persentase Transaksi (%), Persentase Kontribusi (%) | `/payment-methods` |
| Kontribusi Platform | Peringkat, Platform, Revenue, Transaksi, User, Persentase Kontribusi (%) | `/platform-contribution` |
| Waktu Pembelian | Rentang Waktu, Jam Mulai, Jam Akhir, Jumlah Transaksi, Persentase (%) | `/purchase-times` |

- Rincian sheet **Tren** mengikuti rincian default filter: harian untuk default, rentang tanggal, dan bulan; bulanan untuk tahun dan rentang tahun. Rentang tanggal yang lebih dari 366 hari diekspor per bulan.
- Angka disimpan sebagai angka (bukan teks), jadi bisa langsung dijumlahkan di Excel. Nilai kosong (misalnya konversi tanpa downloader) dibiarkan kosong.
- Persentase kontribusi platform yang tidak bisa dihitung (total revenue 0) ditulis `N/A`.
- Jika filter tidak punya data, file tetap diunduh dengan semua sheet dan nilai `0`.

### 422 Validasi gagal

Respons JSON seperti endpoint lain:

| Kondisi | Pesan |
|---|---|
| `format` kosong | `Format wajib diisi.` |
| `format` selain `xlsx` / `csv` | `Format harus xlsx atau csv.` |
| Filter tidak valid | Sama dengan [filter Ringkasan Performa](performance-filter.md#422-validasi-gagal) |
