# API Distribusi Waktu Pembelian

Jumlah transaksi per rentang waktu pembelian, untuk Pie Chart di card Waktu Pembelian. Filter dan aturan transaksinya sama dengan [Ringkasan Performa](performance.md), sehingga totalnya selalu sama dengan KPI Total Transaksi.

Wajib login. Semua role (Manager dan Employee) boleh mengakses. Aturan header dan kode error umum ada di [authentication.md](authentication.md).

## `GET /api/performance/purchase-times`

### Query parameter

Sama persis dengan `/api/performance/summary`, lihat [filter Ringkasan Performa](performance-filter.md): beberapa platform (`platforms[]`) dan satu mode periode (default bulan berjalan, rentang tanggal, bulan, beberapa tahun, atau rentang tahun).

Contoh:

- `GET /api/performance/purchase-times?date_from=2026-09-01&date_to=2026-09-30`
- `GET /api/performance/purchase-times?year=2026&month=9&platforms[]=jadiasn&platforms[]=cerebrum`
- `GET /api/performance/purchase-times?years[]=2025&years[]=2026` (gabungan kedua tahun)

### Rentang waktu

Hanya transaksi dengan status laporan `Success` yang dihitung. Status laporan memakai `effective_status`, kemudian `source_status` sebagai fallback. Jam diambil dari `paid_time` transaksi (WIB, sesuai data Markaz).

| `key` | `label` | Jam |
|---|---|---|
| `dawn` | Dini Hari | 00:00 - 05:59 |
| `morning` | Pagi | 06:00 - 10:59 |
| `midday` | Siang | 11:00 - 14:59 |
| `afternoon` | Sore | 15:00 - 17:59 |
| `night` | Malam | 18:00 - 23:59 |
| `unknown` | Tidak diketahui | Transaksi tanpa `paid_time` |

- Kelima rentang jam **selalu dikirim** dengan urutan di atas, termasuk yang jumlahnya `0`, supaya warna pie selalu konsisten.
- `unknown` hanya muncul jika ada transaksi tanpa jam pembelian. Rentang ini tetap dihitung supaya total sama dengan KPI.
- `percentage` = `transactions` ÷ total transaksi × 100, dibulatkan 1 desimal. `0` jika total = 0. Karena pembulatan, jumlahnya bisa 99.9 atau 100.1.

### 200 OK

```json
{
    "data": {
        "filter": { "mode": "date_range", "date_from": "2026-09-01", "date_to": "2026-09-30", "platform": null, "platforms": [], "...": "lihat performance-filter.md" },
        "is_empty": false,
        "message": null,
        "totals": { "transactions": 9 },
        "time_ranges": [
            { "key": "dawn", "label": "Dini Hari", "start": "00:00", "end": "05:59", "transactions": 2, "percentage": 22.2 },
            { "key": "morning", "label": "Pagi", "start": "06:00", "end": "10:59", "transactions": 2, "percentage": 22.2 },
            { "key": "midday", "label": "Siang", "start": "11:00", "end": "14:59", "transactions": 1, "percentage": 11.1 },
            { "key": "afternoon", "label": "Sore", "start": "15:00", "end": "17:59", "transactions": 1, "percentage": 11.1 },
            { "key": "night", "label": "Malam", "start": "18:00", "end": "23:59", "transactions": 2, "percentage": 22.2 },
            { "key": "unknown", "label": "Tidak diketahui", "start": null, "end": null, "transactions": 1, "percentage": 11.1 }
        ],
        "labels": [
            "Dini Hari (00:00 - 05:59)",
            "Pagi (06:00 - 10:59)",
            "Siang (11:00 - 14:59)",
            "Sore (15:00 - 17:59)",
            "Malam (18:00 - 23:59)",
            "Tidak diketahui"
        ],
        "datasets": [
            { "label": "Transaksi", "data": [2, 2, 1, 1, 2, 1] }
        ]
    }
}
```

| Field | Dipakai untuk |
|---|---|
| `labels`, `datasets` | Langsung ke Chart.js: `new Chart(canvas, { type: 'pie', data: { labels, datasets } })`. Label sudah berisi rentang jam, jadi tooltip bawaan sudah menampilkan rentang jam dan jumlah transaksi. |
| `time_ranges` | Isi tooltip tambahan (misalnya `percentage`) atau legenda; urutannya sama dengan `labels` |
| `totals.transactions` | Total transaksi di card, sama dengan KPI Total Transaksi |

### Data kosong

Jika tidak ada transaksi `Success` di periode yang dipilih, respons tetap **200**, dengan `is_empty: true`, `message: "Data tidak tersedia untuk filter yang dipilih."`, dan kelima rentang bernilai `0`. Tampilkan `message` sebagai kondisi kosong, bukan pie chart.

### 422 Validasi gagal

Sama dengan [filter Ringkasan Performa](performance-filter.md#422-validasi-gagal), misalnya lebih dari satu mode periode, format tanggal salah, atau slug platform tidak ditemukan.

## Jam Ramai Pembelian (`hourly`, FIX-12)

Pembayaran berhasil dan omzet per jam bayar WIB (24 slot), untuk grafik batang Jam Ramai Pembelian. `hourly` hanya terisi jika `preset` dikirim (lihat [Resolver periode](performance.md)); tanpa `preset`, `hourly` = `null` dan field pie chart di atas tetap seperti sebelumnya.

### Query parameter tambahan

| Parameter | Keterangan |
|---|---|
| `day_of_week` | Opsional. Hari ISO: `1` Senin … `7` Minggu. Diterapkan ke periode aktif **dan** pembanding. Tanpa parameter ini = Semua Hari. Diabaikan jika periode aktif hanya satu hari. Untuk periode lebih dari satu hari, harus salah satu dari `day_options`; jika tidak, `422` di `errors.day_of_week`. |

Contoh: `GET /api/performance/purchase-times?preset=last_7_days&platforms[]=jadiasn&day_of_week=5`

### Aturan perhitungan

Memakai aturan agregasi transaksi bersama (FIX-02), sama dengan Omzet dan Transaksi Berhasil di Ringkasan Sales:

- Slot `19` = pembayaran pukul 19.00.00–19.59.59 WIB, berdasarkan `paid_date` + `paid_time` terakhir yang valid, bukan waktu transaksi dibuat.
- Hanya pembelian berstatus laporan `Success`. Paket grup dihitung sekali lewat baris pembeli; baris anggota grup (`markaz_ref_id` terisi) dan klaim kupon tidak dihitung.
- Omzet = jumlah `net_nominal`.
- Pembayaran tanpa `paid_date` atau tanpa `paid_time` yang valid tidak masuk slot mana pun. Pembayaran dengan tanggal bayar tetapi tanpa jam bayar tetap dihitung di laporan berbasis tanggal (Ringkasan Sales), jadi total Jam Ramai bisa lebih kecil dari Transaksi Berhasil.
- Kedua sisi membaca data transaksi terbaru sampai batas data lengkap (cutoff) masing-masing, termasuk pembanding jam setara.

### Kelengkapan slot

Setiap slot mencakup jam yang sama di semua hari yang dihitung.

| `availability` | Arti | Nilai |
|---|---|---|
| `complete` | Jam itu sudah tercakup di semua hari | Angka; `0` jika tidak ada pembayaran |
| `partial` | Tercakup di sebagian hari, atau cutoff jatuh di tengah jam | Angka dari data sebelum cutoff |
| `unavailable` | Belum tercakup di hari mana pun (tampilkan **Belum Tersedia**) | `null`, bukan `0` |

`as_of` = batas akhir data yang dihitung di slot itu (`null` jika `unavailable`).

### Bentuk `hourly`

```json
{
    "timezone": "Asia/Jakarta",
    "day_selectable": true,
    "day_of_week": 5,
    "day_label": "Jumat",
    "day_options": [{ "value": 1, "label": "Senin" }, "...", { "value": 7, "label": "Minggu" }],
    "active": {
        "date_from": "2026-11-04", "date_to": "2026-11-10", "days": 1,
        "availability": "complete", "as_of": "2026-11-07T00:00:00+07:00",
        "totals": { "success_payments": 1, "revenue": 100000 }
    },
    "comparison": {
        "date_from": "2026-10-28", "date_to": "2026-11-03", "days": 1,
        "availability": "complete", "as_of": "2026-10-31T00:00:00+07:00",
        "totals": { "success_payments": 1, "revenue": 200000 }
    },
    "comparison_message": null,
    "slots": [
        {
            "hour": 19, "label": "19.00–19.59", "start": "19:00", "end": "19:59",
            "active": { "success_payments": 1, "revenue": 100000, "availability": "complete", "as_of": "2026-11-06T20:00:00+07:00" },
            "comparison": { "success_payments": 0, "revenue": 0, "availability": "complete", "as_of": "2026-10-30T20:00:00+07:00" }
        }
    ]
}
```

| Field | Keterangan |
|---|---|
| `day_selectable`, `day_options` | Pemilih hari hanya ditampilkan jika `day_selectable` = `true` (periode aktif lebih dari satu hari). `day_options` = hari yang ada di periode aktif. |
| `day_of_week`, `day_label` | Hari yang diterapkan; `null` / `Semua Hari` jika tidak ada. |
| `active.days`, `comparison.days` | Jumlah tanggal yang dihitung di sisi itu setelah filter hari. |
| `slots` | Selalu 24 item, `hour` 0–23. Tinggi batang = `success_payments`; tooltip = `label`, `success_payments`, `revenue`. |
| `comparison` | `null` jika periode tidak punya pembanding, atau jika periode pembanding tidak memuat hari yang dipilih. Untuk kasus kedua, `comparison_message` = `Tidak ada hari yang sama di periode pembanding.` dan setiap `slots[].comparison` = `null`. |
