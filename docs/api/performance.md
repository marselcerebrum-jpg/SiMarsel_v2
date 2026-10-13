# API Ringkasan Performa (kontrak untuk BE)

> **Status: belum diimplementasikan di BE.** Halaman `/performance` (FE) sudah memakai kontrak ini. Sebelum endpoint tersedia, halaman menampilkan pesan gagal memuat dengan tombol "Coba lagi".

Semua endpoint **wajib login**. Aturan header, CSRF, dan kode error umum ada di [authentication.md](authentication.md).

**Semua rumus KPI dihitung di BE.** FE hanya menampilkan `value`, `previous_value`, `change_percent`, dan `trend` apa adanya, tanpa menghitung ulang.

## `GET /api/performance/filters`

Pilihan filter platform. Jika endpoint ini gagal, FE tetap menampilkan opsi "Semua platform".

**200 OK**

```json
{
    "data": {
        "platforms": [
            { "value": "android", "label": "Android" },
            { "value": "ios", "label": "iOS" },
            { "value": "web", "label": "Web" }
        ]
    }
}
```

## `GET /api/performance/summary`

**Query**

| Field | Tipe | Aturan |
|---|---|---|
| `start_date` | date `Y-m-d` | Wajib |
| `end_date` | date `Y-m-d` | Wajib, `>= start_date`, tidak boleh melewati hari ini |
| `platform` | string | Opsional. Tidak dikirim berarti semua platform. Harus salah satu `value` dari `/filters` |

Contoh: `/api/performance/summary?start_date=2026-10-01&end_date=2026-10-09&platform=android`

**200 OK**

```json
{
    "data": {
        "period": { "start_date": "2026-10-01", "end_date": "2026-10-09" },
        "previous_period": { "start_date": "2026-09-22", "end_date": "2026-09-30" },
        "metrics": {
            "total_revenue":       { "value": 125000000, "previous_value": 100000000, "change_percent": 25.0,  "trend": "up" },
            "transactions":        { "value": 250,       "previous_value": 260,       "change_percent": -3.85, "trend": "down" },
            "registered_users":    { "value": 1200,      "previous_value": 1100,      "change_percent": 9.09,  "trend": "up" },
            "unique_buyers":       { "value": 210,       "previous_value": 210,       "change_percent": 0,     "trend": "same" },
            "average_order_value": { "value": 500000,    "previous_value": 384615,    "change_percent": 30.0,  "trend": "up" },
            "repeat_order_users":  { "value": 30,        "previous_value": 25,        "change_percent": 20.0,  "trend": "up" },
            "conversion_rate":     { "value": 17.5,      "previous_value": 19.09,     "change_percent": -8.33, "trend": "down" }
        }
    }
}
```

**422**: error validasi Laravel. FE menampilkan pesan `errors.start_date`, `errors.end_date`, dan `errors.platform` di bawah field filternya.

## `GET /api/performance/trend`

Data tren untuk bagian **Analisa Tren & Performa**. Memakai filter yang sama dengan `/summary`, ditambah `metric` dan `granularity`. FE memanggil endpoint ini lagi setiap kali filter, metric, atau periode berubah. Perubahan visualisasi (Bar, Line, Area, Pie, Table) hanya menggambar ulang di FE, tanpa request baru.

**Query**

| Field | Tipe | Aturan |
|---|---|---|
| `start_date`, `end_date`, `platform` | | Sama dengan `/summary` |
| `metric` | string | Wajib: `revenue`, `transactions`, `downloader`, `conversion`, `new_users`, `repeat_order` |
| `granularity` | string | Wajib: `daily`, `weekly`, `monthly` |

Contoh: `/api/performance/trend?start_date=2026-10-01&end_date=2026-10-09&metric=revenue&granularity=daily`

**200 OK**

```json
{
    "data": {
        "metric": "revenue",
        "granularity": "daily",
        "points": [
            {
                "period_start": "2026-10-01", "period_end": "2026-10-01",
                "value": 12500000, "change_percent": null, "trend": null
            },
            {
                "period_start": "2026-10-02", "period_end": "2026-10-02",
                "value": 15000000, "change_percent": 20.0, "trend": "up"
            }
        ]
    }
}
```

| Field | Keterangan |
|---|---|
| `points[].period_start`, `period_end` | Rentang satu titik data (`Y-m-d`). Daily: tanggal yang sama. Weekly: Senin–Minggu, dipotong sesuai `start_date`/`end_date`. Monthly: awal–akhir bulan, dipotong sesuai filter |
| `points[].value` | Nilai metric pada rentang itu, dengan rumus yang sama dengan `/summary`. `conversion` dalam persen |
| `points[].change_percent`, `trend` | Perbandingan nilai dengan titik sebelumnya (`up` / `down` / `same`). `null` untuk titik pertama. Dipakai di kolom Perubahan pada Table |

Urutkan `points` dari periode terlama. Kembalikan titik bernilai `0` untuk periode tanpa data, agar grafik tidak bolong. FE memakai `period_start`/`period_end` untuk label sumbu dan tooltip (nilai + periode saat hover).

`downloader` adalah total user/downloader yang registrasi, sedangkan `new_users` adalah user yang pertama kali tercatat pada periode itu. Definisi pasti `new_users` perlu disepakati BE.

## Objek metrik

| Field | Tipe | Keterangan |
|---|---|---|
| `value` | number / null | Nilai pada periode terpilih. `null` berarti data tidak tersedia (FE menampilkan "Data tidak tersedia") |
| `previous_value` | number / null | Nilai pada `previous_period` |
| `change_percent` | number / null | Persentase perubahan dibanding periode sebelumnya. `null` jika tidak bisa dihitung (misalnya `previous_value` 0) |
| `trend` | `up` / `down` / `same` / null | Status perkembangan. `null` berarti tidak ada data pembanding |

Uang dalam Rupiah (angka bulat, bukan string). `conversion_rate` dalam persen (`17.5` berarti 17,5%).

Jika semua `value` bernilai `0` atau `null`, FE menampilkan kondisi data kosong.

## Definisi KPI yang perlu ditetapkan BE

| Key | Indikator | Sumber | Usulan aturan (perlu disepakati) |
|---|---|---|---|
| `total_revenue` | Total Revenue | Tabel transaksi | Jumlah nominal transaksi berstatus berhasil |
| `transactions` | Jumlah Transaksi | Tabel transaksi | Jumlah transaksi berstatus berhasil |
| `registered_users` | User Terdaftar | Tabel downloader | Jumlah user/downloader yang berhasil registrasi |
| `unique_buyers` | User Unik | Tabel transaksi | Jumlah email **distinct** dari transaksi berhasil |
| `average_order_value` | AOV | Tabel transaksi | `total_revenue ÷ transactions` (`null` jika transaksi 0) |
| `repeat_order_users` | Repeat Order | Tabel transaksi | Jumlah email dengan transaksi berhasil lebih dari 1 kali |
| `conversion_rate` | Konversi | Transaksi + downloader | `unique_buyers ÷ registered_users × 100` |

`previous_period` adalah rentang dengan panjang yang sama, tepat sebelum `start_date`. Hal yang masih perlu disepakati: status transaksi yang dihitung berhasil, dan apakah repeat order dihitung di dalam periode atau sepanjang waktu.
