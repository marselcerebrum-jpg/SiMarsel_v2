# Daftar Transaksi (FIX-13)

Daftar transaksi Analisis Performa dengan filter lokal, opsi Filter Lainnya, dan detail per transaksi. Semua endpoint membutuhkan sesi login (`401` tanpa sesi) dan akun aktif (`403` untuk akun nonaktif, lihat `TransactionPolicy`).

Filter lokal hanya memilih baris daftar. Filter ini tidak mengubah angka ringkasan Dashboard, dan angka agregat tidak mengubah filter daftar.

| Method | Path | Isi |
|---|---|---|
| GET | `/api/performance/transactions` | Daftar berpaginasi |
| GET | `/api/performance/transactions/options` | Nilai yang bisa dipilih di Filter Lainnya |
| GET | `/api/performance/transactions/export` | Ekspor Daftar Transaksi Excel/CSV (FIX-14), lihat di bawah |
| GET | `/api/performance/transactions/{id}` | Detail satu transaksi (id lokal `transactions.id`) |

## Filter utama

Parameter periode dan platform sama dengan filter global Dashboard (`docs/api/performance-filter.md`): `preset` beserta parameternya, atau parameter periode lama (`date_from`/`date_to`, `year`/`month`, dan seterusnya), serta `platforms[]`. Daftar memakai periode aktif. Periode pembanding tidak dipakai.

## Filter lokal

| Parameter | Nilai | Keterangan |
|---|---|---|
| `date_basis` | `created` (default), `paid` | Dasar Tanggal. `created` memakai `markaz_created_at`. `paid` memakai Tanggal Dibayar valid terakhir (`paid_date`), jadi transaksi yang belum pernah punya tanggal bayar valid tidak ikut. Dasar ini juga menentukan urutan. |
| `search` | teks, maks. 100 | Mengandung, tanpa membedakan huruf besar/kecil, pada ID transaksi (`trx_id`), nama, email, atau telepon customer. |
| `statuses[]` | `pending`, `success`, `expired`, `failed`, `canceled`, `unavailable` | Status laporan: `effective_status` jika ada, lalu `source_status`. `unavailable` memilih baris tanpa status (Status belum tersedia). **Tidak Berhasil** = `expired` + `canceled` + `failed`. |
| `products[]` | nama produk persis | |
| `payment_methods[]` | metode pembayaran | Dicocokkan tanpa membedakan huruf besar/kecil dan spasi di ujung. |
| `product_types[]` | `Perdana`, `Perpanjangan` | |
| `promo_codes[]` | kode promo | Dicocokkan tanpa membedakan huruf besar/kecil pada `promo_code` yang tersimpan. Berlaku untuk semua status, termasuk Pending yang belum punya usage promo. |
| `promo` | `with`, `without` | Dengan Promo / Tanpa Promo. `promo_codes[]` tidak boleh digabung dengan `without` (`422`). |
| `page`, `per_page` | `per_page` 1–100, default 25 | |

Semua filter bisa digabung (AND antarfilter, OR di dalam satu daftar nilai). Reset Filter Daftar di FE cukup mengirim ulang filter utama tanpa parameter lokal: `date_basis` kembali ke `created`.

## Baris yang tampil dan urutan

- Satu baris utama per checkout. Pada paket grup, hanya baris pembeli (`markaz_ref_id` kosong) yang masuk daftar. Anggota grup tampil di detail. Klaim kupon tetap menjadi baris sendiri dengan `is_claim_coupon: true`.
- Baris lama tanpa `markaz_transaction_id` atau `markaz_created_at` tidak tampil, karena kontrak tanggal transaksi tidak bisa membacanya.
- Urutan terbaru menurut dasar tanggal aktif:
  - `created`: `markaz_created_at desc, id desc`.
  - `paid`: `paid_date desc, paid_time desc, id desc`.
- Ekspor (FIX-14) memakai query yang sama, `TransactionListRepository::query()`, tanpa pembatas halaman, sehingga baris dan urutannya sama dengan gabungan semua halaman daftar.

## `GET /api/performance/transactions`

```json
{
  "data": [
    {
      "id": 812,
      "trx_id": "jadiasn-476770",
      "created_at": "2026-11-12T10:00:00+07:00",
      "paid_date": "2026-11-12",
      "paid_time": "10:05:00",
      "platform": { "slug": "jadiasn", "name": "JadiASN" },
      "product": "Bimbel Persiapan Tes SKD CPNS 2026 - Masa Aktif 1 Tahun",
      "customer_name": "Rina",
      "payment_method": "QRIS",
      "nominal": 183000,
      "status": { "code": 1, "label": "Success" },
      "is_claim_coupon": false,
      "group_member_count": 2
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 1,
    "last_page": 1,
    "date_basis": "created",
    "filter": { "mode": "date_range", "date_from": "2026-11-01", "date_to": "2026-11-30", "platforms": [] }
  }
}
```

Field sesuai urutan kolom tabel: ID Transaksi (`trx_id`), Tanggal Dibuat (`created_at`, WIB), Tanggal Dibayar (`paid_date` + `paid_time`), Platform, Produk, Customer (`customer_name`), Metode Pembayaran, Nominal (`nominal`, nominal tagihan), dan Status.

- `paid_date` `null` berarti belum pernah ada tanggal bayar valid, ditampilkan sebagai **Belum tersedia**. Jika ada, ini tanggal bayar valid terakhir, walaupun payload Markaz terbaru sudah tidak mengirim tanggal bayar. `paid_time` bisa `null` walaupun `paid_date` ada.
- `status.code` `null` berarti `status.label` = "Status belum tersedia".
- `id` dipakai untuk membuka detail. Daftar tidak memuat email atau telepon.

## `GET /api/performance/transactions/options`

Menerima filter utama dan `date_basis`. Nilainya diambil dari baris daftar dalam periode dan platform itu.

```json
{
  "data": {
    "products": ["Paket A", "Paket B"],
    "payment_methods": ["BCA", "QRIS"],
    "product_types": ["Perdana", "Perpanjangan"],
    "promo_codes": ["CPNS10", "RES1"]
  },
  "meta": { "date_basis": "created", "filter": { "...": "..." } }
}
```

## `GET /api/performance/transactions/{id}`

```json
{
  "data": {
    "id": 812,
    "trx_id": "jadiasn-476770",
    "markaz_transaction_id": 476770,
    "created_at": "2026-11-12T10:00:00+07:00",
    "paid_date": "2026-11-12",
    "paid_time": "10:05:00",
    "platform": { "slug": "jadiasn", "name": "JadiASN" },
    "product": "…",
    "product_type": "Perdana",
    "payment_method": "QRIS",
    "promo_codes": ["CPNS10"],
    "status": { "code": 1, "label": "Success" },
    "is_claim_coupon": false,
    "customer": { "name": "Rina", "markaz_customer_id": 501, "email": "rina@example.test", "phone": null },
    "financials": {
      "product_price": 200000,
      "voucher_discount": 20000,
      "admin_fee": 3000,
      "nominal": 183000,
      "revenue_contribution": 180000
    },
    "group": {
      "role": "buyer",
      "buyer": null,
      "members": [
        { "id": 813, "trx_id": "jadiasn-476771", "customer": { "name": "…", "markaz_customer_id": 502, "email": "…", "phone": "…" }, "status": { "code": 1, "label": "Success" }, "is_claim_coupon": false }
      ]
    }
  }
}
```

- **Rincian keuangan:**
  - Harga Paket (`product_price`): harga setelah diskon paket, sebelum voucher dan biaya admin.
  - Potongan Voucher (`voucher_discount`).
  - Biaya Admin (`admin_fee`).
  - Nominal (`nominal`).
  - Kontribusi Omzet (`revenue_contribution`).
- **`revenue_contribution`** = `net_nominal` hanya untuk baris pembeli dengan status Success, Tanggal Dibayar valid, dan bukan klaim, mengikuti aturan agregasi bersama FIX-02-BE-02. Nilai ini masuk Omzet pada Tanggal Dibayar. Selain itu nilainya `null`: tampilkan **—** di detail dan kosongkan sel ekspor. Contohnya Pending, yang tetap punya `nominal` tagihan.
- **`customer.email`/`customer.phone`** hanya ada bila akun boleh melihat kontak (`TransactionPolicy::viewContact`). Saat ini itu berlaku untuk semua akun aktif, sesuai aturan Analisis Performa. Nilai `null` berarti kontak tidak tersedia.
- **`group.role`:**
  - `buyer`: baris pembeli yang punya anggota; `members` berisi anggotanya.
  - `member`: baris anggota; `buyer` berisi pembelinya.
  - `null`: bukan paket grup.
  - Anggota dicari dengan `platform_id` yang sama dan `markaz_ref_id` = `markaz_transaction_id` pembeli.

### Error

| Status | Kapan |
|---|---|
| `401` | Tanpa sesi login. Tidak ada data transaksi di response. |
| `403` | Akun nonaktif. |
| `404` | ID tidak ada, atau baris lama tanpa identitas Markaz / `markaz_created_at`. |
| `422` | Parameter daftar tidak valid; pesan ada di `errors.<field>`. |

## `GET /api/performance/transactions/export` (FIX-14-BE-02)

Parameter sama dengan daftar, ditambah `format` (`xlsx` atau `csv`, wajib). `page` dan `per_page` tidak mengubah file. File `daftar_transaksi_{date_from}_{date_to}.{format}` berisi seluruh baris yang lolos filter, dibaca bertahap dari `TransactionListRepository::query()`, sehingga baris dan urutannya sama dengan gabungan semua halaman daftar.

13 kolom berurutan: ID Transaksi, Tanggal Dibuat, Tanggal Dibayar, Platform, Produk, Customer, Metode Pembayaran, Harga Paket, Potongan Voucher, Biaya Admin, Nominal, Kontribusi Omzet, Status.

- **Tanggal:** WIB, `YYYY-MM-DD HH:MM:SS`. Tanggal Dibayar yang belum pernah valid ditulis `Belum tersedia`.
- **Kontribusi Omzet:** aturan yang sama dengan detail. Sel kosong untuk Pending, klaim, dan pembelian tanpa tanggal bayar valid.
- **Teks:** di CSV, teks yang diawali `=`, `+`, `-`, `@`, tab, atau CR diberi awalan `'` agar tidak dijalankan sebagai formula. Di XLSX, teks tetap teks tanpa diubah.
- **CSV:** UTF-8 dengan BOM. Baris pertama adalah header.
