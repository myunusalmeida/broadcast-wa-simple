Berikut isi **`README.md`** dalam format Markdown. Tinggal salin-tempel ke file `README.md` di repo kamu.

````markdown
# WA Broadcast dari CSV (Batch + Resume)

Tool PHP sederhana untuk broadcast WhatsApp dari file CSV **dengan aman**:

- ✅ **Batching** (mis. 100 kontak/batch) → aman dari timeout
- 🔁 **Auto-continue** antar batch → lanjut otomatis sampai selesai
- ♻️ **Resume job** bila tab tertutup/koneksi putus (state tersimpan)
- 📈 **Progress bar + ETA** (estimasi durasi & jam selesai)
- 🧾 **Log CSV** lintas batch (bisa **Download**)
- ⚙️ **Rate control** (pesan/menit) + delay otomatis per pesan
- 🧪 **Dry-run** (preview tanpa kirim) & **Sandbox** (jika didukung provider)

> Implementasi contoh menggunakan endpoint **WAPanels**:
> ```
> POST https://app.wapanels.com/api/create-message
> fields: appkey, authkey, to, message, sandbox
> ```
> Untuk provider lain, cukup sesuaikan fungsi `post_message()`.

---

## Konten

- [Fitur Utama](#fitur-utama)
- [Prasyarat](#prasyarat)
- [Cara Mendapatkan `appkey` & `authkey` (WAPanels)](#cara-mendapatkan-appkey--authkey-wapanels)
- [Instalasi & Menjalankan](#instalasi--menjalankan)
- [Konfigurasi Kredensial](#konfigurasi-kredensial)
- [Format CSV](#format-csv)
- [Cara Pakai](#cara-pakai)
- [Estimasi Waktu vs Kecepatan](#estimasi-waktu-vs-kecepatan)
- [Best Practice](#best-practice)
- [Struktur Folder](#struktur-folder)
- [Keamanan](#keamanan)
- [Troubleshooting](#troubleshooting)
- [FAQ](#faq)
- [Ganti Provider](#ganti-provider)
- [Lisensi](#lisensi)
- [Kredit](#kredit)

---

## Fitur Utama

- Upload **CSV** (kolom 1: `Nama`, kolom 2: `Nomor`)
- **Normalisasi nomor**: `08xxxx` → `62xxxx`, hapus non-digit otomatis
- **Rate** (10–30 pesan/menit) & **Batch size** (disarankan 100–150)
- **Auto-continue** antar batch, **resume** bila terputus
- **Log CSV** per job di `storage/logs/wa_{job}.csv` + tombol **Download**
- **ETA** & **progress bar**
- **Dry-run** untuk uji template, **Sandbox** untuk uji kirim

---

## Prasyarat

- **PHP 8.0+** (disarankan 8.1/8.2) dengan ekstensi **cURL** aktif
- Web server (Apache/Nginx) atau PHP built-in server
- Folder `storage/` dapat ditulis (writeable)

---

## Cara Mendapatkan `appkey` & `authkey` (WAPanels)

> Nama menu bisa berbeda, intinya cari **API keys** & **hubungkan perangkat**.

1. **Daftar / Login** ke dashboard **WAPanels**.
2. **Hubungkan perangkat WhatsApp** (scan QR ala WhatsApp Web). Pastikan status **Connected**.
3. Buka menu **API / Integrations / Developer** (atau sejenisnya).
4. **Generate** API keys, salin:
   - `appkey`
   - `authkey`
5. Coba **kirim pesan** ke 1 nomor dari dashboard WAPanels (sanity check).
6. Masukkan `appkey` & `authkey` ke aplikasi ini (lihat **Konfigurasi**).

> Pakai provider lain? Cukup sesuaikan URL & field di `post_message()`.

---

## Instalasi & Menjalankan

1. Simpan file `broadcast.php` di folder pilihan, mis. `wa-broadcast/`.
2. Jalankan lokal:
   ```bash
   php -S 127.0.0.1:8000
````

Buka: **[http://127.0.0.1:8000/broadcast.php](http://127.0.0.1:8000/broadcast.php)**

> Script akan otomatis membuat:
>
> * `storage/uploads/` — CSV yang diunggah
> * `storage/jobs/` — state job (JSON)
> * `storage/logs/` — log lintas batch (CSV)

---

## Konfigurasi Kredensial

Di bagian atas `broadcast.php`:

```php
$WAPANELS_ENDPOINT = 'https://app.wapanels.com/api/create-message';
$APPKEY  = 'ISI_APPKEY_KAMU';
$AUTHKEY = 'ISI_AUTHKEY_KAMU';
```

> **Produksi**: simpan di environment, jangan commit ke repo publik.
>
> Contoh sederhana:
>
> ```php
> $APPKEY  = getenv('WAPANELS_APPKEY') ?: '';
> $AUTHKEY = getenv('WAPANELS_AUTHKEY') ?: '';
> ```

---

## Format CSV

Dua kolom pertama wajib: **Nama**, **Nomor** (baris kosong diabaikan).

```csv
Nama,Nomor
Budi,6281234567890
Sari,081234567890
```

* Centang **“CSV punya header?”** jika baris pertama adalah header.
* Delimiter bisa **koma (,)**, **titik koma (;)**, atau **TAB**.

---

## Cara Pakai

1. Buka `broadcast.php`.
2. **Upload CSV** dan setel **header** + **delimiter**.
3. Pilih **Rate (pesan/menit)** dan **Batch size** (disarankan **100–150**).
4. Tulis **Template Pesan** dengan placeholder:

   * `{{name}}` → akan diganti nama penerima
   * `{{phone}}` → akan diganti nomor penerima
     Contoh:

   ```
   Halo {{name}}, kami ingin menginformasikan promo terbaru...
   ```
5. (Opsional) **Dry-run** untuk preview tanpa kirim.
6. (Opsional) **Sandbox** bila provider mendukung mode uji.
7. Klik **Mulai Job** → batch pertama diproses.
8. Centang **Auto-continue** agar otomatis lanjut ke batch berikutnya.
9. Jika koneksi/tab terputus, buka lagi halaman yang sama → job **resume** otomatis.
10. Setelah selesai, klik **Download log** untuk unduh rekap CSV lintas batch.

---

## Estimasi Waktu vs Kecepatan

Perkiraan waktu untuk **650** kontak:

| Rate (pesan/menit) | Jeda/Pesan | Total Waktu |
| ------------------ | ---------- | ----------- |
| 10                 | \~6 dtk    | \~65 menit  |
| 12                 | \~5 dtk    | \~54 menit  |
| 15                 | \~4 dtk    | \~43 menit  |
| 20                 | \~3 dtk    | \~32 menit  |
| 25                 | \~2.4 dtk  | \~26 menit  |
| 30                 | \~2 dtk    | \~22 menit  |

> Tambahkan **buffer 10–20%** untuk antisipasi retry/latensi.

---

## Best Practice

* Mulai dari **10–15 pesan/menit**; jika stabil, naik ke **20–25**.
* Pakai **batch 100–150** agar aman dari timeout.
* **Personalisasi** pesan (`{{name}}`) untuk kurangi flag spam.
* Pastikan penerima **opt-in** / pernah interaksi (hindari spam).
* Data nomor rapi → tingkatkan **deliverability**.

---

## Struktur Folder

```
wa-broadcast/
├─ broadcast.php
└─ storage/
   ├─ uploads/   # CSV yang diupload (disimpan untuk resume)
   ├─ jobs/      # State job {job_id}.json (offset, rate, dst)
   └─ logs/      # Log lintas batch wa_{job_id}.csv (Download)
```

---

## Keamanan

* **Jangan** commit `appkey`/`authkey` ke repo publik.
* Batasi akses ke `broadcast.php` (IP allowlist/basic auth/admin area).
* Pastikan permission `storage/` **writeable** hanya oleh web user.
* Patuhi kebijakan & hukum anti-spam: kirim ke **kontak opt-in**.

---

## Troubleshooting

**1) “CSV gagal di-upload”**

* Cek `upload_max_filesize` & `post_max_size` (mis. `16M`) di `php.ini`.
* Cek permission `storage/` (contoh `chmod -R 775 storage`).

**2) “CSV tidak bisa dibuka / log tidak muncul”**

* Pastikan file CSV (bukan `.xlsx`).
* Cek delimiter (`,`, `;`, atau `TAB`).

**3) Banyak status `FAILED`**

* Periksa nomor: valid & aktif (tool normalisasi `0` → `62`).
* Turunkan **rate** (mis. 10–15/menit), atau naikan **batch** bertahap.
* Lihat `info` & **HTTP code**:

  * `401/403` → cek `appkey/authkey`.
  * `429` → rate limit; turunkan kecepatan.
  * `5xx` → gangguan provider; ulang di batch berikutnya.

**4) cURL error / timeout**

* Jaringan/host lambat → turunkan rate & batch.
* Boleh naikan `CURLOPT_TIMEOUT` jika perlu.

**5) Putus koneksi saat proses**

* Job ada di `storage/jobs/{job}.json`. Buka lagi halaman → **resume**.
* Mau mulai job baru? Upload CSV baru & mulai lagi.

---

## FAQ

**Q: Bisa kirim 600–1000 nomor?**
A: Bisa. Gunakan **batching** & **rate wajar**. Mis. 650 @ 20/min ≈ 32–40 menit (plus buffer).

**Q: Ini WhatsApp Business API resmi?**
A: Contoh ini pakai gateway pihak ketiga (**WAPanels**). Untuk volume besar & kepatuhan ketat, pertimbangkan **WhatsApp Business Platform (Cloud API)**.

**Q: Bisa di-deploy di shared hosting/cPanel?**
A: Umumnya bisa (PHP + cURL). Pastikan `storage/` writeable.

**Q: Ganti provider gimana?**
A: Ubah `post_message()` (URL & field payload) sesuai dokumentasi provider.

---

## Ganti Provider

Edit fungsi `post_message()` di `broadcast.php`:

```php
function post_message($endpoint, $appkey, $authkey, $to, $message, $sandbox = 'false') {
    $payload = [
        'appkey'  => $appkey,
        'authkey' => $authkey,
        'to'      => $to,
        'message' => $message,
        'sandbox' => $sandbox,
    ];
    // Untuk provider lain, sesuaikan: URL, field, header (Authorization), dsb.
    // Misal perlu JSON:
    // CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ...'],
    // CURLOPT_POSTFIELDS => json_encode($payload),
    ...
}
```

---

## Lisensi

Bebas dipakai untuk keperluan pribadi/komersial. Tetap patuhi aturan WhatsApp & hukum anti-spam yang berlaku.

---

## Kredit

Didesain agar **aman**, **tahan timeout**, dan **mudah dioperasikan**—mulai dari CSV sederhana, rate control, hingga resume job & log rapi. Selamat berkarya! 🚀
