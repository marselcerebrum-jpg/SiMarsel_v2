# Kontrak Periode Analisis Performa

Satu resolver (`App\Services\PeriodResolverService`) menentukan periode aktif, periode pembanding, bagian yang dipakai untuk menghitung perubahan, dan batas data. Ringkasan, Tren, komponen lain, dan Ekspor membaca hasil yang sama lewat `PerformanceFilterRequest::filter()`.

Semua waktu memakai WIB. Tanggal dan jam pada dokumen ini adalah contoh.

## Parameter

| Parameter | Nilai | Keterangan |
|---|---|---|
| `preset` | `today`, `yesterday`, `last_7_days`, `last_30_days`, `last_90_days`, `this_month`, `last_month`, `date_range`, `last_year` | Mengaktifkan resolver |
| `comparison` | `auto` (bawaan), `previous_period`, `same_period_last_month`, `same_period_last_year`, `custom` | Hanya mengubah periode pembanding |
| `date_from`, `date_to` | `Y-m-d` | Wajib untuk `preset=date_range`, dilarang untuk preset lain |
| `comparison_from`, `comparison_to` | `Y-m-d` | Wajib untuk `comparison=custom`, dilarang untuk pilihan lain |
| `platforms[]`, `platform` | slug | Kosong berarti semua platform |
| `dataset` | `transactions` (bawaan), `downloaders` | Cakupan metadata kelengkapan |

Permintaan tanpa `preset` tetap memakai parameter lama (`date_from`/`date_to`, `year`/`month`, `years[]`, `year_from`/`year_to`) dan tidak mengeluarkan objek `period`. `preset` tidak dapat digabung dengan parameter periode lama.

Tanggal akhir sebelum tanggal mulai menghasilkan `422` pada field akhir: `date_to` berisi `Tanggal akhir tidak boleh sebelum tanggal mulai.` dan `comparison_to` berisi `Tanggal akhir pembanding tidak boleh sebelum tanggal mulai.`

## Aturan periode

Contoh memakai waktu 11 November 2026 pukul 14.00 WIB.

| Preset | Aktif | Pembanding `auto` | Keadaan pembanding |
|---|---|---|---|
| `today` | 11 Nov sampai batas lengkap | 10 Nov sampai jam yang sama | `at_cutoff` |
| `yesterday` | 10 Nov penuh | 9 Nov penuh | `latest` |
| `last_7_days` | 4–10 Nov | 28 Okt–3 Nov | `latest` |
| `last_30_days` | 12 Okt–10 Nov | 12 Sep–11 Okt | `latest` |
| `last_90_days` | 13 Agu–10 Nov | 15 Mei–12 Agu | `latest` |
| `this_month` | 1 Nov sampai batas lengkap | 1–11 Okt sampai jam yang sama | `at_cutoff` |
| `last_month` | 1–31 Okt | 1–30 Sep penuh | `latest` |
| `date_range` | Mulai sampai akhir, inklusif | Durasi sama tepat sebelum mulai. Jika akhir hari ini, jamnya disamakan | Mengikuti batas jam |
| `last_year` | 1 Jan 2026 sampai batas lengkap | 1 Jan 2025 sampai 11 Nov 2025 jam yang sama | `at_cutoff` |

| `comparison` manual | Pembanding |
|---|---|
| `previous_period` | Durasi setara tepat sebelum periode aktif |
| `same_period_last_month` | Tanggal padanan pada bulan sebelumnya |
| `same_period_last_year` | Tanggal padanan pada tahun sebelumnya |
| `custom` | `comparison_from` sampai `comparison_to`. `durations_differ` bernilai `true` jika durasinya berbeda |

### Tanggal padanan yang hilang

Total aktif tetap memakai seluruh periode aktif, sedangkan perubahan memakai bagian yang ada di kedua sisi (`change_basis`).

| Kasus | Aktif | Pembanding | `change_basis` |
|---|---|---|---|
| `this_month` pada 31 Maret 2026 | 1–31 Mar | 1–28 Feb | 1–28 Mar dibanding 1–28 Feb, `is_trimmed = true` |
| `last_year` pada 29 Februari 2028 | 1 Jan–29 Feb 2028 | 1 Jan–28 Feb 2027 | Aktif sampai 28 Feb 2028, `is_trimmed = true` |
| 31 Maret saja dengan `same_period_last_month` | 31 Mar | `null` | `null` |
| 20 Mar–10 Apr dengan `same_period_last_month` | 20 Mar–10 Apr (22 hari) | 20 Feb–10 Mar (19 hari) | 20–28 Mar dan 1–10 Apr (19 hari) dibanding 20 Feb–10 Mar (19 hari) |

Setiap hari aktif diperiksa satu per satu, sehingga tanggal tanpa padanan di tengah rentang (29–31 Maret pada contoh di atas) juga dikeluarkan dari dasar perubahan. Karena itu `change_basis.active` dan `change_basis.comparison` berupa daftar rentang. Hari pembanding yang tidak menjadi padanan hari aktif mana pun juga dikeluarkan, misalnya 29–31 Januari untuk aktif 28 Februari–1 April.

`last_month` dengan `auto` selalu membandingkan dua bulan kalender penuh, sehingga tidak dipotong walaupun panjang bulannya berbeda.

## Batas data

Resolver membaca `dashboard_snapshots.cutoff_at` dari catatan `is_complete = true` dengan `platform_id`, `dataset`, `date_from`, dan `date_to` yang sama persis dengan rentang.

- Batas tiap platform adalah `cutoff_at` terbaru miliknya.
- Batas gabungan adalah batas paling awal di antara platform terpilih. Contoh: A 14.00 dan B 13.00 menghasilkan 13.00.
- Platform tanpa catatan lengkap membuat periode `unavailable`.

| `state_basis` | Arti untuk agregasi |
|---|---|
| `at_cutoff` | Baca catatan pada `cutoff_at`. Jika catatan tepat di jam itu tidak ada, `availability = unavailable` dan tidak dibentuk ulang dari status terbaru |
| `latest` | Baca status dan tanggal laporan terbaru |

Resolver tidak menghitung ulang status dan tidak menghitung metrik.

## Response

Objek `period` ada di dalam `filter` pada response yang memakai `preset`.

```json
{
    "timezone": "Asia/Jakarta",
    "preset": "this_month",
    "comparison_mode": "auto",
    "platforms": ["app-a"],
    "dataset": "transactions",
    "active": {
        "date_from": "2026-03-01",
        "date_to": "2026-03-31",
        "start_at": "2026-03-01T00:00:00+07:00",
        "end_at": "2026-03-31T14:00:00+07:00",
        "days": 31,
        "availability": "partial",
        "cutoff_at": "2026-03-31T14:00:00+07:00",
        "state_basis": "latest"
    },
    "comparison": {
        "date_from": "2026-02-01",
        "date_to": "2026-02-28",
        "start_at": "2026-02-01T00:00:00+07:00",
        "end_at": "2026-03-01T00:00:00+07:00",
        "days": 28,
        "availability": "complete",
        "cutoff_at": "2026-03-01T00:00:00+07:00",
        "state_basis": "latest"
    },
    "change_basis": {
        "is_trimmed": true,
        "active_days": 28,
        "comparison_days": 28,
        "active": [
            {
                "date_from": "2026-03-01",
                "date_to": "2026-03-28",
                "start_at": "2026-03-01T00:00:00+07:00",
                "end_at": "2026-03-29T00:00:00+07:00"
            }
        ],
        "comparison": [
            {
                "date_from": "2026-02-01",
                "date_to": "2026-02-28",
                "start_at": "2026-02-01T00:00:00+07:00",
                "end_at": "2026-03-01T00:00:00+07:00"
            }
        ]
    },
    "durations_differ": true
}
```

`end_at` bersifat eksklusif: kejadian dihitung jika `start_at ≤ waktu < end_at`. `change_basis` bernilai `null` jika salah satu periode `unavailable` atau tidak ada tanggal padanan.

## Ringkasan KPI

| Keadaan pembanding | `value` | `previous_value` | `change_percentage`, `trend` |
|---|---|---|---|
| `availability = unavailable`, atau tidak ada tanggal padanan | Seluruh periode aktif | `null` | `null` |
| `state_basis = at_cutoff` | Seluruh periode aktif | `null` | `null` |
| `state_basis = latest` dan tersedia | Seluruh periode aktif | Seluruh periode pembanding | Dihitung dari `change_basis.active` dibanding `change_basis.comparison` |

Pembanding jam setara dikosongkan karena query Ringkasan membaca data transaksi terbaru, bukan catatan `dashboard_snapshots` pada jam tersebut. Angka dari data terbaru tidak mewakili kondisi pada jam setara, sehingga tidak ditampilkan sebagai pembanding. Frontend menampilkan `trend = null` sebagai "Tanpa Pembanding".

## Batasan saat ini

- Tanpa catatan `dashboard_snapshots` lengkap untuk rentang yang diminta, resolver melaporkan `availability = unavailable`.
- Query komponen lain masih menghitung per hari penuh. Tren Omzet dan Transaksi (`/api/performance/trend/sales`) membaca catatan untuk pembanding jam setara, lihat [API Performa](api/performance.md#get-apiperformancetrendsales).
