# Filter Ringkasan Performa

Satu filter utama yang dipakai **semua** endpoint performa:

- `GET /api/performance/summary` (Card Overview)
- `GET /api/performance/trend` (Grafik Data)
- `GET /api/performance/payment-methods` (Metode Pembayaran)
- `GET /api/performance/platform-contribution` (Kontribusi Platform)
- `GET /api/performance/purchase-times` (Waktu Pembelian)
- `GET /api/performance/export` (Export Excel / CSV)

Kirim query parameter yang **sama** ke setiap endpoint, supaya semua card menampilkan data dari filter yang sama.

## Platform

| Parameter | Format | Keterangan |
|---|---|---|
| `platforms[]` | slug platform, boleh lebih dari satu | Contoh `platforms[]=jadiasn&platforms[]=cerebrum`. Kosongkan untuk semua platform. |
| `platform` | satu slug platform | Parameter lama, tetap diterima. Digabung ke `platforms`. |

## Periode

Pilih **salah satu** mode di bawah. Jika lebih dari satu mode dikirim, respons **422** dengan error di key `filter`.

| Mode (`filter.mode`) | Parameter | Data yang dihitung | Dibandingkan dengan | Rincian grafik default |
|---|---|---|---|---|
| `current_month` | (tidak ada parameter periode) | Tanggal 1 bulan ini sampai hari ini | Jumlah hari yang sama tepat sebelumnya | Harian |
| `date_range` | `date_from`, `date_to` (`YYYY-MM-DD`) | Rentang tanggal tersebut | Jumlah hari yang sama tepat sebelumnya | Harian |
| `month` | `year`, `month` (1-12) | Satu bulan penuh | Bulan sebelumnya | Harian |
| `years` | `years[]`, 1 sampai 5 tahun | Setiap tahun yang dipilih (tahun di antaranya tidak ikut) | Masing-masing tahun − 1 | Bulanan |
| `year_range` | `year_from`, `year_to` | Semua tahun dari `year_from` sampai `year_to` | Jumlah tahun yang sama tepat sebelumnya | Bulanan |

Contoh:

| Pilihan user | Query |
|---|---|
| Default | `/api/performance/summary` |
| September 2026, JADIASN + CEREBRUM | `/api/performance/summary?year=2026&month=9&platforms[]=jadiasn&platforms[]=cerebrum` |
| Bandingkan 2025 dan 2026 | `/api/performance/summary?years[]=2025&years[]=2026` |
| 2024 sampai 2026 | `/api/performance/summary?year_from=2024&year_to=2026` |

### Perbandingan periode (naik / turun / sama)

| Filter | Periode sekarang | Periode pembanding |
|---|---|---|
| Default, hari ini 16 Okt 2026 | 1 - 16 Okt 2026 | 15 - 30 Sep 2026 |
| `date_from=2026-09-01&date_to=2026-09-30` | 1 - 30 Sep 2026 | 2 - 31 Agu 2026 |
| `year=2026&month=1` | 1 - 31 Jan 2026 | 1 - 31 Des 2025 |
| `year=2026&month=10` (bulan berjalan, hari ini 16 Okt) | 1 - 31 Okt 2026 | 1 - 30 Sep 2026 |
| `years[]=2025&years[]=2026` | 2025 + 2026 | 2024 + 2025 |
| `years[]=2024&years[]=2026` | 2024 + 2026 | 2023 + 2025 |
| `year_from=2024&year_to=2026` | 2024 - 2026 | 2021 - 2023 |

Untuk mode `years`, Card Overview menampilkan **gabungan** semua tahun yang dipilih. Perbandingan antar tahun dilihat di Grafik Data.

Bulan dan tahun **selalu dibandingkan utuh**, walaupun bulan atau tahun berjalan belum selesai. Contohnya, Oktober 2026 yang baru berjalan 16 hari tetap dibandingkan dengan seluruh September, jadi wajar jika terlihat turun. Jika ingin perbandingan dengan jumlah hari yang sama, pakai mode default atau `date_from`/`date_to`.

## Blok `filter` di setiap respons

Semua endpoint performa mengembalikan filter yang aktif, supaya frontend tahu data mana yang sedang ditampilkan.

```json
"filter": {
    "mode": "years",
    "date_from": "2024-01-01",
    "date_to": "2026-12-31",
    "platform": null,
    "platforms": ["jadiasn", "cerebrum"],
    "years": [2024, 2026],
    "month": null,
    "ranges": [
        { "date_from": "2024-01-01", "date_to": "2024-12-31" },
        { "date_from": "2026-01-01", "date_to": "2026-12-31" }
    ]
}
```

| Field | Keterangan |
|---|---|
| `mode` | `current_month`, `date_range`, `month`, `years`, atau `year_range` |
| `date_from`, `date_to` | Tanggal paling awal dan paling akhir yang dihitung |
| `platform` | Slug jika tepat satu platform dipilih, selain itu `null` (untuk kompatibilitas) |
| `platforms` | Semua slug yang dipilih; `[]` berarti semua platform |
| `years` | Tahun yang dipilih (mode `month`, `years`, `year_range`); `[]` untuk mode lain |
| `month` | Bulan yang dipilih (mode `month`), selain itu `null` |
| `ranges` | Rentang tanggal yang benar-benar dihitung. Bisa lebih dari satu jika tahun yang dipilih tidak berurutan. |

`/summary` juga mengembalikan `previous_period` dengan bentuk `{ date_from, date_to, ranges }`.

## Data kosong

Jika filter tidak punya data, setiap endpoint tetap mengembalikan **200** dengan `is_empty: true` dan `message` yang bisa langsung ditampilkan.

## 422 Validasi gagal

| Kondisi | Key | Pesan |
|---|---|---|
| Lebih dari satu mode periode | `filter` | `Pilih salah satu filter periode saja: rentang tanggal, bulan, tahun, atau rentang tahun.` |
| Hanya salah satu dari `date_from` / `date_to` | `date_from` / `date_to` | `Tanggal mulai wajib diisi.` / `Tanggal akhir wajib diisi.` |
| Format tanggal salah | `date_from` | `Tanggal mulai harus berformat YYYY-MM-DD.` |
| `date_to` sebelum `date_from` | `date_to` | `Tanggal akhir tidak boleh sebelum tanggal mulai.` |
| `month` tanpa `year` (atau sebaliknya) | `year` / `month` | `Tahun wajib diisi.` / `Bulan wajib diisi.` |
| `month` di luar 1-12 | `month` | `Bulan harus di antara 1 dan 12.` |
| Tahun di luar 2000 sampai tahun ini | `year`, `years.0`, `year_from`, ... | `Tahun harus di antara 2000 dan 2026.` |
| Lebih dari 5 tahun | `years` | `Tahun maksimal 5 pilihan.` |
| Tahun yang sama dipilih dua kali | `years.1` | `Tahun tidak boleh dipilih lebih dari sekali.` |
| `year_to` sebelum `year_from` | `year_to` | `Tahun akhir tidak boleh sebelum tahun mulai.` |
| Slug platform tidak ada | `platform` / `platforms.0` | `Platform tidak ditemukan.` |
