# Paket Laravel WhatsApp Gateway & Multi-Sender OTP

[English](README.md) | [Bahasa Indonesia](README.id.md)

Paket integrasi lengkap siap pakai untuk framework Laravel, menyediakan WhatsApp Gateway multi-sender pool dengan rotasi otomatis, failover cerdas, autentikasi OTP pelanggan, serta 9 menu Back-Office bawaan siap pakai.

---

## Arsitektur Sistem

Paket ini menghubungkan aplikasi Laravel Anda langsung dengan instance WhatsApp Gateway (container Docker atau server lokal).

```
+-----------------------------------------------------------------------------------+
|                           Laravel Back-Office UI                                  |
|                                                                                   |
|  [Sidebar Menu WhatsApp Gateway - 9 Menu Lengkap]                                 |
|  1. Sesi & Nomor Pengirim WA   -> Panel Native (SenderPoolPanel.tsx)              |
|  2. Member Terverifikasi        -> Panel Native (VerifiedMembersPanel.tsx)         |
|  3. Dasbor                     -> Micro-Frontend Tertanam (OpenWaApp.tsx)          |
|  4. Obrolan                    -> Micro-Frontend Tertanam (OpenWaApp.tsx)          |
|  5. Webhook                    -> Micro-Frontend Tertanam (OpenWaApp.tsx)          |
|  6. Templat                    -> Micro-Frontend Tertanam (OpenWaApp.tsx)          |
|  7. Penguji Pesan              -> Micro-Frontend Tertanam (OpenWaApp.tsx)          |
|  8. Plugin                     -> Micro-Frontend Tertanam (OpenWaApp.tsx)          |
|  9. Log                        -> Micro-Frontend Tertanam (OpenWaApp.tsx)          |
+----------------------------------------+------------------------------------------+
                                         |
     +-----------------------------------+-----------------------------------+
     | Panggilan API Admin Terautentikasi    | Reverse Proxy & Otorisasi Nginx
     v                                       v
+------------------------------------+   +------------------------------------+
| Controller Laravel                 |   | Nginx Reverse Proxy                |
| - WhatsAppOtpController            |   | - auth_request /_gateway_auth      |
| - SenderPoolController             |   | - /gateway-api/ -> Gateway:2785    |
| - VerifiedMembersController        |   | - /gateway-socket/ -> WebSocket    |
| - WhatsAppGatewayController        |   +-----------------+------------------+
| - OtpService & SenderPoolService   |                     |
+-----------------+------------------+                     |
                  |                                        |
                  +-------------------+--------------------+
                                      |
                                      v
                        +---------------------------+
                        | Zero-Config WA Gateway    |
                        | (Container Docker :2785)  |
                        +---------------------------+
```

---

## Struktur Folder Paket

```
laravel/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       ├── WhatsAppOtpController.php           # Permintaan & verifikasi OTP member
│   │       ├── Admin/
│   │       │   ├── SenderPoolController.php        # Manajemen pool sesi & nomor pengirim
│   │       │   ├── VerifiedMembersController.php   # Data member terverifikasi WhatsApp
│   │       │   └── WhatsAppGatewayController.php   # Status gateway & auth subrequest Nginx
│   │       └── BackOffice/
│   │           └── WhatsAppMemberWebhookController.php # Penerima webhook member verified
│   ├── Models/
│   │   ├── SenderPool.php                         # Model database sesi nomor pengirim WA
│   │   ├── OtpChallenge.php                       # Token OTP terenkripsi, batas waktu, limit
│   │   └── OtpDelivery.php                        # Riwayat pengiriman OTP per sesi
│   ├── Providers/
│   │   └── WhatsAppOtpServiceProvider.php         # Registrasi dependency injection service
│   └── Services/
│       └── WhatsAppOtp/
│           ├── OpenWaSessionClient.php            # HTTP client ke REST API Gateway
│           ├── OtpService.php                     # Logika OTP, hashing HMAC, & verifikasi
│           ├── SenderPoolService.php              # Logika rotasi pool & failover otomatis
│           ├── PhoneNumber.php                    # Normalisasi nomor telepon standar E.164
│           ├── TenantResolver.php                 # Resolusi multi-tenant dari domain/host
│           └── Transport/                         # PooledTransport, OpenWaTransport, Mock
├── config/
│   └── whatsapp_otp.php                           # Konfigurasi lengkap OTP & Gateway
├── database/
│   └── migrations/
│       ├── 2026_10_08_000001_add_whatsapp_login_to_users_table.php
│       ├── 2026_10_08_000002_create_otp_challenges_tables.php
│       ├── 2026_10_14_000001_create_sender_pool_table.php
│       └── 2026_10_15_000001_make_api_key_nullable_in_sender_pool_table.php
├── nginx/
│   └── gateway-reverse-proxy.conf                 # Konfigurasi Nginx subrequest auth
├── routes/
│   └── whatsapp.php                               # Definisi route lengkap
└── ui/
    ├── components/
    │   ├── SenderPoolPanel.tsx                    # Menu 1: Sesi & Nomor Pengirim WA
    │   ├── VerifiedMembersPanel.tsx               # Menu 2: Member Terverifikasi
    │   ├── WhatsAppGatewayPanel.tsx               # Wrapper multi-sender panel
    │   ├── WhatsAppOtpForm.tsx                    # Form login pelanggan via nomor WhatsApp
    │   └── OtpInput.tsx                           # Input kode OTP 4-6 digit interaktif
    ├── openwa/                                    # Micro-frontend tertanam (Menu 3 - 9)
    │   ├── OpenWaApp.tsx                          # Komponen utama micro-frontend
    │   ├── pages.ts                               # Definisi navigasi halaman gateway
    │   └── pages/                                 # Dasbor, Chats, Webhooks, Templates, dll.
    └── examples/
        └── AdminDashboardSidebarExample.tsx       # Contoh lengkap integrasi 9 menu admin
```

---

## Langkah Integrasi ke Proyek Laravel Anda

### 1. Salin File Backend
Salin folder-folder berikut ke dalam proyek Laravel Anda:
- `app/` -> `app/`
- `config/whatsapp_otp.php` -> `config/whatsapp_otp.php`
- `database/migrations/*` -> `database/migrations/`
- `routes/whatsapp.php` -> `routes/whatsapp.php`

### 2. Daftarkan Service Provider
Pada Laravel 11 atau 10, tambahkan `WhatsAppOtpServiceProvider` di `bootstrap/providers.php` (atau `config/app.php`):

```php
return [
    // ...
    App\Providers\WhatsAppOtpServiceProvider::class,
];
```

### 3. Jalankan Migrasi Database
Jalankan perintah Artisan:
```bash
php artisan migrate
```

Tabel dan kolom yang otomatis dibuat:
- `sender_pool`: Menyimpan nomor pengirim WA, ID sesi, status koneksi, dan counter kegagalan.
- `otp_challenges` & `otp_deliveries`: Menyimpan token verifikasi terenkripsi HMAC dan log pengiriman.
- Kolom `phone_e164`, `phone_verified_at`, dan `tenant_key` pada tabel `users`.

### 4. Daftarkan Route API
Buka `routes/api.php` Anda dan tambahkan:
```php
require __DIR__ . '/whatsapp.php';
```

---

## Panduan Lengkap Konfigurasi 9 Menu Back-Office

Sesuai dengan tampilan pada menu admin Back-Office Anda, berikut adalah susunan **9 menu lengkap** di bawah grup **WhatsApp Gateway**:

| No | Label Menu | Fungsi | Komponen / Implementasi | Route / Endpoint |
|---|---|---|---|---|
| 1 | **Sesi & Nomor Pengirim WA** | Kelola nomor WhatsApp sender, pairing QR / kode, dan status rotasi | `<SenderPoolPanel />` (Native React) | `/api/v1/admin/sender-pool` |
| 2 | **Member Terverifikasi** | Kelola daftar member yang sukses login via nomor WhatsApp | `<VerifiedMembersPanel />` (Native React) | `/api/v1/admin/verified-members` |
| 3 | **Dasbor** | Metrik real-time gateway, uptime, & beban sesi | `<OpenWaApp />` (Tertanam) | `/dashboard/gateway` |
| 4 | **Obrolan** | Konsol chat langsung WhatsApp & balas pesan | `<OpenWaApp />` (Tertanam) | `/dashboard/gateway/chats` |
| 5 | **Webhook** | Pemantau endpoint webhook masuk & keluar | `<OpenWaApp />` (Tertanam) | `/dashboard/gateway/webhooks` |
| 6 | **Templat** | Pengaturan templat pesan WhatsApp cepat | `<OpenWaApp />` (Tertanam) | `/dashboard/gateway/templates` |
| 7 | **Penguji Pesan** | Alat uji kirim teks & media ke nomor target | `<OpenWaApp />` (Tertanam) | `/dashboard/gateway/message-tester` |
| 8 | **Plugin** | Ekstensi plugin & otomasi tambahan | `<OpenWaApp />` (Tertanam) | `/dashboard/gateway/plugins` |
| 9 | **Log** | Log transmisi dan riwayat jaringan gateway | `<OpenWaApp />` (Tertanam) | `/dashboard/gateway/logs` |

### Cara Kerja Menu:
1. **Menu 1 & Menu 2** adalah panel native khusus yang terpasang langsung pada UI Admin Laravel Anda. Panel ini berkomunikasi dengan `SenderPoolController` dan `VerifiedMembersController` melalui otentikasi Sanctum standar.
2. **Menu 3 hingga Menu 9** dijalankan melalui micro-frontend ringan (`OpenWaApp.tsx`). Saat admin mengklik salah satu dari 7 menu ini, router berpindah ke `/dashboard/gateway/*` dan menampilkan layar konsol gateway secara mulus di dalam layout admin Anda.

Contoh kode lengkap integrasi sidebar dapat Anda lihat di:
`laravel/ui/examples/AdminDashboardSidebarExample.tsx`

---

## Keamanan Nginx Subrequest Auth

Untuk menjamin keamanan maksimal tanpa pernah membocorkan token atau membuka port gateway ke publik, Nginx bertindak sebagai proxy pintar dengan pengecekan hak akses otomatis:

```nginx
# Masukkan baris ini di dalam blok server {} Nginx Laravel Anda:
include /path/ke/laravel/nginx/gateway-reverse-proxy.conf;
```

### Alur Keamanan:
1. Setiap panggilan browser ke `/gateway-api/*` atau `/gateway-socket/*` akan dicek terlebih dahulu oleh Nginx ke subrequest `/_gateway_auth` (`WhatsAppGatewayController::authorizeDashboard`).
2. Laravel memeriksa cookie session admin dan memastikan role adalah `super-admin` atau `admin`.
3. Jika lolos, Laravel merespons dengan HTTP `204 No Content`, dan Nginx langsung meneruskan request ke container internal gateway (`http://openwa:2785`).
4. Jika tidak login atau bukan admin, request langsung ditolak dengan HTTP `403 Forbidden`.

---

## Fitur Multi-Sender Pool & Failover Otomatis

- **Rotasi Round-Robin**: Setiap kali pelanggan meminta OTP, sistem memutar pengiriman ke nomor sender WhatsApp aktif secara bergiliran sehingga tidak membebani satu nomor.
- **Deteksi Gangguan**: Sistem memantau status setiap nomor (apakah terhubung, terputus, atau terblokir).
- **Failover Otomatis**: Jika satu nomor mengalami kendala jaringan atau batas pengiriman, sistem secara otomatis mengalihkan pengiriman ke nomor cadangan berikutnya tanpa disadari oleh pelanggan.
- **Jeda Otomatis (Auto-Suspend)**: Nomor yang gagal berulang kali akan dinonaktifkan sementara untuk menjaga reputasi nomor pengirim lainnya.

---

## Form Login OTP untuk Pelanggan

Gunakan komponen `<WhatsAppOtpForm />` pada halaman login website Anda:
- Normalisasi otomatis nomor HP (misal input `0812...` menjadi `+62812...`).
- Pembatasan frekuensi pengiriman (rate limiting) dengan hitung mundur.
- Input kode angka 4-6 digit dengan verifikasi otomatis begitu seluruh digit terisi.
- Login session otomatis begitu kode berhasil diverifikasi.
