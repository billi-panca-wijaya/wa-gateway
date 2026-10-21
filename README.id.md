# WhatsApp Gateway & Integrasi Laravel

[English](README.md) | [Bahasa Indonesia](README.id.md)

WhatsApp API Gateway zero-configuration beserta paket integrasi lengkap untuk framework Laravel. Dirancang untuk keandalan tinggi, multi-sender pool dengan rotasi nomor, failover otomatis, dan pengelolaan Back-Office yang komprehensif.

---

## Keunggulan Utama

- **Gateway Zero-Config**: Berjalan langsung menggunakan Docker tanpa perlu konfigurasi token atau variabel manual.
- **Paket Khusus Laravel**: Seluruh kode sumber lengkap tersedia di folder [`laravel/`](laravel/) mencakup Controller, Model, Service, Migrasi Database, Route, dan komponen UI React.
- **9 Menu Lengkap di Sidebar Back-Office**:
  1. **Sesi & Nomor Pengirim WA** (Manajemen pool nomor pengirim & pairing QR)
  2. **Member Terverifikasi** (Audit member yang terverifikasi via WhatsApp)
  3. **Dasbor** (Metrik real-time & beban sesi)
  4. **Obrolan** (Konsol interaktif chat langsung WhatsApp)
  5. **Webhook** (Pemantau endpoint webhook & retry)
  6. **Templat** (Templat pesan cepat)
  7. **Penguji Pesan** (Alat penguji kirim pesan & media)
  8. **Plugin** (Ekstensi plugin & otomasi)
  9. **Log** (Log transmisi jaringan & sistem gateway)
- **Multi-Sender Pool & Failover Otomatis**: Distribusi pesan bergilir (round-robin) ke banyak nomor WhatsApp dengan pengalihan otomatis saat terjadi kendala.
- **Reverse Proxy Aman**: Konsol gateway tertanam dilindungi oleh otentikasi session admin Laravel via Nginx subrequest authorization.

---

## Struktur Repositori

```
wa-gateway/
├── docker-compose.yml              # Container Docker WhatsApp Gateway siap jalan
├── laravel/                        # Paket Lengkap Integrasi Laravel
│   ├── app/                        # Controller, Model, Provider, Service
│   ├── config/                     # Konfigurasi whatsapp_otp.php
│   ├── database/migrations/        # Skema database pool sender & tabel OTP
│   ├── nginx/                      # Konfigurasi Nginx subrequest auth
│   ├── routes/                     # Definisi route API & Admin siap pakai
│   ├── ui/                         # Panel React, micro-frontend & contoh 9 menu
│   ├── README.md                   # Panduan integrasi Laravel lengkap (Bahasa Inggris)
│   └── README.id.md                # Panduan integrasi Laravel lengkap (Bahasa Indonesia)
├── README.md                       # File ini (Bahasa Inggris)
└── README.id.md                    # File ini (Bahasa Indonesia)
```

---

## Memulai Cepat: Menjalankan Gateway

Jalankan container menggunakan Docker Compose:

```bash
docker compose up -d
```

Gateway akan aktif pada:
- Endpoint Healthcheck: `http://127.0.0.1:18091/api/health/ready`
- Port Internal Gateway: `2785`

---

## Integrasi ke Framework Laravel

Seluruh kode integrasi, model, controller, dan komponen antarmuka admin berada di dalam folder [`laravel/`](laravel/).

Langkah instalasi:
1. Salin `laravel/app/`, `laravel/config/`, dan `laravel/database/` ke proyek Laravel Anda.
2. Jalankan `php artisan migrate`.
3. Panggil `routes/whatsapp.php` di dalam `routes/api.php` Laravel Anda.
4. Pasang komponen UI ke dalam dasbor Back-Office Anda.

Untuk panduan konfigurasi 9 menu admin dan keamanan Nginx secara mendalam, silakan baca [Panduan Integrasi Laravel](laravel/README.id.md).
