# WhatsApp Gateway — Add-on untuk Laravel

[English](README.md) | [Bahasa Indonesia](README.id.md)

Add-on WhatsApp Login & multi-sender OTP yang langsung ditambahkan ke website Laravel **yang sudah ada**.  
Website Laravel Anda tidak diubah — Anda hanya **menambah** file baru, tidak menimpa file yang sudah ada.

---

## Apa yang Bisa Dilakukan

| Fitur | Keterangan |
|-------|------------|
| **Login WhatsApp OTP** | Pelanggan login dengan memasukkan nomor HP, lalu menerima kode OTP 4 digit via WhatsApp |
| **Multi-Sender Pool** | Banyak nomor WhatsApp pengirim dengan rotasi otomatis dan failover |
| **9 Menu Admin Panel** | Konsol manajemen Back-Office lengkap ditambahkan ke dashboard admin Anda |
| **Gateway Zero-Config** | Engine WhatsApp berjalan sebagai container Docker — tanpa perlu API key atau token |

---

## Cara Kerjanya (Sederhana)

```
Pelanggan ketik nomor HP
        |
        v
Laravel kirim OTP via WhatsApp
(otomatis bergantian pakai banyak nomor pengirim)
        |
        v
Pelanggan masukkan kode 4 digit
        |
        v
Terverifikasi → Login otomatis
```

---

## Mulai Cepat

### 1. Jalankan Engine WhatsApp

```bash
docker compose up -d
```

### 2. Salin File Add-on ke Proyek Laravel Anda

```bash
# Salin file backend (hanya file baru — tidak ada yang ditimpa)
cp -r laravel/app/Http/Controllers/WhatsApp/    proyek-laravel/app/Http/Controllers/WhatsApp/
cp -r laravel/app/Models/Otp*                   proyek-laravel/app/Models/
cp -r laravel/app/Models/SenderPool.php         proyek-laravel/app/Models/
cp -r laravel/app/Services/WhatsAppOtp/         proyek-laravel/app/Services/WhatsAppOtp/
cp -r laravel/app/Providers/WhatsAppOtpServiceProvider.php proyek-laravel/app/Providers/
cp    laravel/config/whatsapp_otp.php           proyek-laravel/config/
cp    laravel/database/migrations/*             proyek-laravel/database/migrations/
cp    laravel/routes/whatsapp.php               proyek-laravel/routes/
```

### 3. Daftarkan & Migrasi

```php
// bootstrap/providers.php (Laravel 11) atau config/app.php
App\Providers\WhatsAppOtpServiceProvider::class,
```

```php
// routes/api.php — tambahkan baris ini
require __DIR__ . '/whatsapp.php';
```

```bash
php artisan migrate
```

**Selesai.** Website Laravel Anda sekarang punya fitur login WhatsApp.

---

## Struktur Repositori

```
wa-gateway/
├── docker-compose.yml          # Engine WhatsApp (jalankan: docker compose up -d)
├── laravel/                    # File add-on untuk disalin ke proyek Laravel Anda
│   ├── app/                    # Controller, Model, Service, Provider
│   ├── config/                 # whatsapp_otp.php
│   ├── database/migrations/    # 3 file migrasi pendek
│   │   ├── 0001_wa_users.php   # Tambah kolom phone ke tabel users
│   │   ├── 0002_wa_otp.php     # Buat tabel otp_challenges & otp_deliveries
│   │   └── 0003_wa_senders.php # Buat tabel sender_pool
│   ├── nginx/                  # Konfigurasi reverse proxy (untuk konsol gateway)
│   ├── routes/whatsapp.php     # Semua route API — tinggal require() di api.php
│   └── ui/                     # Panel admin React (opsional)
├── README.md                   # File ini (English)
└── README.id.md                # File ini (Bahasa Indonesia)
```

---

## File Apa Saja yang Ditambahkan ke Proyek Laravel Anda

> **Penting**: Tidak ada file yang sudah ada yang ditimpa. Semua di bawah ini adalah file BARU.

| Lokasi di Laravel Anda | Yang Ditambahkan | Fungsi |
|------------------------|-----------------|--------|
| `app/Http/Controllers/WhatsApp/` | Folder baru berisi 4 controller | OTP, SenderPool, VerifiedMembers, Gateway |
| `app/Models/` | 3 file model baru | OtpChallenge, OtpDelivery, SenderPool |
| `app/Services/WhatsAppOtp/` | Folder baru berisi service class | Logika OTP, rotasi pool sender, normalisasi nomor |
| `app/Providers/` | 1 file provider baru | WhatsAppOtpServiceProvider |
| `config/` | 1 file config baru | whatsapp_otp.php |
| `database/migrations/` | 3 file migrasi baru | Tabel baru + kolom baru di tabel users |
| `routes/` | 1 file route baru | whatsapp.php |

---

## Panduan Detail

Untuk panduan langkah demi langkah termasuk pengaturan 9 menu admin, konfigurasi Nginx reverse proxy, dan integrasi panel UI React, baca [**Panduan Add-on Laravel**](laravel/README.id.md).

---

## Lisensi

[MIT License](LICENSE)
