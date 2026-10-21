# Add-on Login WhatsApp — Panduan Integrasi Laravel

[English](README.md) | [Bahasa Indonesia](README.id.md)

Folder ini berisi semua file yang diperlukan untuk menambahkan login WhatsApp OTP dan manajemen multi-sender ke website Laravel **yang sudah ada**. Tidak ada file di sini yang menggantikan atau menimpa file Anda yang sudah ada.

---

## Sebelum Mulai

**Kebutuhan:**
- Laravel 10 atau 11
- PHP 8.1+
- Docker (untuk engine WhatsApp)
- Website Laravel Anda sudah berjalan

**Yang Anda dapatkan setelah instalasi:**
- Pelanggan bisa login menggunakan nomor HP WhatsApp
- Panel admin untuk mengelola banyak nomor pengirim WhatsApp
- Rotasi otomatis dan failover antar nomor pengirim
- 9 menu baru di dashboard admin Anda

---

## Langkah 1: Jalankan Engine WhatsApp

Di folder root repository ini (tempat `docker-compose.yml` berada):

```bash
docker compose up -d
```

Engine WhatsApp akan berjalan di port `18091` (internal: `2785`).  
Tidak perlu konfigurasi apa pun — langsung jalan.

---

## Langkah 2: Salin File ke Proyek Laravel Anda

Salin folder dan file berikut ke proyek Laravel Anda.  
**Semua file adalah file baru — tidak ada yang menimpa kode Anda yang sudah ada.**

```bash
# Controller (file baru)
cp app/Http/Controllers/WhatsAppOtpController.php         LARAVEL_ANDA/app/Http/Controllers/
cp -r app/Http/Controllers/Admin/                          LARAVEL_ANDA/app/Http/Controllers/Admin/
cp -r app/Http/Controllers/BackOffice/                     LARAVEL_ANDA/app/Http/Controllers/BackOffice/

# Model (3 file baru)
cp app/Models/OtpChallenge.php                             LARAVEL_ANDA/app/Models/
cp app/Models/OtpDelivery.php                              LARAVEL_ANDA/app/Models/
cp app/Models/SenderPool.php                               LARAVEL_ANDA/app/Models/

# Service (folder baru)
cp -r app/Services/WhatsAppOtp/                            LARAVEL_ANDA/app/Services/WhatsAppOtp/

# Provider (1 file baru)
cp app/Providers/WhatsAppOtpServiceProvider.php            LARAVEL_ANDA/app/Providers/

# Config (1 file baru)
cp config/whatsapp_otp.php                                 LARAVEL_ANDA/config/

# Migrasi (3 file pendek baru)
cp database/migrations/0001_wa_users.php                   LARAVEL_ANDA/database/migrations/
cp database/migrations/0002_wa_otp.php                     LARAVEL_ANDA/database/migrations/
cp database/migrations/0003_wa_senders.php                 LARAVEL_ANDA/database/migrations/

# Route (1 file baru)
cp routes/whatsapp.php                                     LARAVEL_ANDA/routes/
```

---

## Langkah 3: Daftarkan Service Provider

**Laravel 11** — buka `bootstrap/providers.php`:
```php
return [
    // ... provider Anda yang sudah ada ...
    App\Providers\WhatsAppOtpServiceProvider::class,  // ← tambah baris ini
];
```

**Laravel 10** — buka `config/app.php`, cari array `providers`:
```php
'providers' => [
    // ... provider Anda yang sudah ada ...
    App\Providers\WhatsAppOtpServiceProvider::class,  // ← tambah baris ini
],
```

---

## Langkah 4: Tambahkan Route

Buka `routes/api.php` Anda dan tambahkan satu baris di bawah:

```php
require __DIR__ . '/whatsapp.php';
```

---

## Langkah 5: Jalankan Migrasi

```bash
php artisan migrate
```

Ini akan:
- Menambah kolom `phone_e164`, `phone_verified_at`, `tenant_key` ke tabel `users` Anda
- Membuat tabel baru: `otp_challenges`
- Membuat tabel baru: `otp_deliveries`
- Membuat tabel baru: `sender_pool`

---

## Langkah 6: Tambahkan ke User Model (Opsional)

Jika model `User` Anda belum punya kolom phone di `$fillable`, tambahkan:

```php
// app/Models/User.php
protected $fillable = [
    // ... field Anda yang sudah ada ...
    'tenant_key',
    'phone_e164',
    'phone_verified_at',
    'onboarding_completed_at',
];

protected function casts(): array
{
    return [
        // ... cast Anda yang sudah ada ...
        'phone_verified_at' => 'datetime',
        'onboarding_completed_at' => 'datetime',
    ];
}
```

---

## Selesai! Endpoint API Anda Sudah Siap

Setelah instalasi, route API ini otomatis tersedia:

### Endpoint Pelanggan (Publik)
| Method | URL | Fungsi |
|--------|-----|--------|
| POST | `/api/v1/whatsapp/request-otp` | Kirim OTP ke HP pelanggan |
| POST | `/api/v1/whatsapp/verify-otp` | Verifikasi kode OTP dan login |
| POST | `/api/v1/whatsapp/resend-otp` | Kirim ulang kode OTP |

### Endpoint Admin (Perlu auth + role admin)
| Method | URL | Fungsi |
|--------|-----|--------|
| GET | `/api/v1/admin/sender-pool` | Lihat semua nomor pengirim |
| POST | `/api/v1/admin/sender-pool` | Tambah nomor pengirim baru |
| GET | `/api/v1/admin/sender-pool/{id}/qr` | Ambil QR code untuk pairing |
| POST | `/api/v1/admin/sender-pool/{id}/pairing-code` | Ambil kode pairing 8 digit |
| POST | `/api/v1/admin/sender-pool/{id}/start` | Mulai sesi pengirim |
| POST | `/api/v1/admin/sender-pool/{id}/stop` | Hentikan sesi pengirim |
| POST | `/api/v1/admin/sender-pool/{id}/test-send` | Kirim pesan percobaan |
| DELETE | `/api/v1/admin/sender-pool/{id}` | Hapus pengirim |
| GET | `/api/v1/admin/verified-members` | Lihat member terverifikasi |
| GET | `/api/v1/admin/whatsapp-gateway` | Status gateway |

---

## Referensi File

```
laravel/                              ← folder ini
├── app/
│   ├── Http/Controllers/
│   │   ├── WhatsAppOtpController.php           # Endpoint OTP pelanggan
│   │   ├── Admin/
│   │   │   ├── SenderPoolController.php        # Kelola nomor pengirim
│   │   │   ├── VerifiedMembersController.php   # Lihat member terverifikasi
│   │   │   └── WhatsAppGatewayController.php   # Status gateway + auth Nginx
│   │   └── BackOffice/
│   │       └── WhatsAppMemberWebhookController.php
│   ├── Models/
│   │   ├── OtpChallenge.php
│   │   ├── OtpDelivery.php
│   │   └── SenderPool.php
│   ├── Providers/
│   │   └── WhatsAppOtpServiceProvider.php
│   └── Services/WhatsAppOtp/
│       ├── OpenWaSessionClient.php
│       ├── OtpService.php
│       ├── SenderPoolService.php
│       ├── PhoneNumber.php
│       ├── TenantResolver.php
│       ├── OtpException.php
│       └── Transport/
│           ├── PooledTransport.php
│           ├── OpenWaTransport.php
│           ├── MockTransport.php
│           ├── OtpTransport.php
│           └── DeliveryResult.php
├── config/
│   └── whatsapp_otp.php
├── database/migrations/
│   ├── 0001_wa_users.php               # Tambah kolom ke tabel users
│   ├── 0002_wa_otp.php                 # Buat tabel OTP
│   └── 0003_wa_senders.php             # Buat tabel sender pool
├── nginx/
│   └── gateway-reverse-proxy.conf      # Untuk konsol gateway tertanam
├── routes/
│   └── whatsapp.php                    # Semua route API WhatsApp
├── ui/                                 # Panel admin React (opsional)
│   ├── components/
│   │   ├── SenderPoolPanel.tsx
│   │   ├── VerifiedMembersPanel.tsx
│   │   ├── WhatsAppOtpForm.tsx
│   │   └── OtpInput.tsx
│   └── openwa/                         # Micro-frontend gateway tertanam
└── README.id.md                        # File ini
```

---

## 9 Menu Admin

Ketika Anda mengintegrasikan panel UI ke dashboard admin, Anda mendapatkan 9 menu manajemen WhatsApp:

| # | Label Menu | Tipe | Komponen |
|---|-----------|------|----------|
| 1 | Sesi & Nomor Pengirim WA | Panel Native | `<SenderPoolPanel />` |
| 2 | Member Terverifikasi | Panel Native | `<VerifiedMembersPanel />` |
| 3 | Dasbor | Tertanam | Micro-frontend gateway |
| 4 | Obrolan | Tertanam | Micro-frontend gateway |
| 5 | Webhook | Tertanam | Micro-frontend gateway |
| 6 | Templat | Tertanam | Micro-frontend gateway |
| 7 | Penguji Pesan | Tertanam | Micro-frontend gateway |
| 8 | Plugin | Tertanam | Micro-frontend gateway |
| 9 | Log | Tertanam | Micro-frontend gateway |

Menu 1-2 adalah panel React mandiri yang memanggil API Laravel Anda langsung.  
Menu 3-9 adalah micro-frontend tertanam yang di-proxy melalui Nginx (lihat `nginx/gateway-reverse-proxy.conf`).

---

## Setup Nginx (Hanya untuk Menu 3-9)

Jika Anda ingin konsol gateway tertanam (menu 3-9), tambahkan ini ke konfigurasi Nginx Anda:

```nginx
include /path/ke/laravel/nginx/gateway-reverse-proxy.conf;
```

Ini mem-proxy `/gateway-api/*` dan `/gateway-socket/*` ke container Docker, dengan otentikasi admin dipastikan oleh Laravel.
