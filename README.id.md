# WhatsApp Gateway — Paket Add-on Non-Intrusif untuk Laravel

[English](README.md) | [Bahasa Indonesia](README.id.md)

Paket add-on Login WhatsApp & OTP multi-sender siap pakai (*drop-in*) untuk website Laravel yang **sudah berjalan**.  
**Jaminan Anti-Bentrok (*Zero-Overwrite Guarantee*)**: Website Laravel Anda tetap 100% utuh. Paket ini hanya **menambahkan** file baru tanpa pernah menimpa, mengubah, atau menghapus file bawaan Laravel Anda.

---

## 🛡️ Jaminan Anti-Bentrok (Zero-Overwrite Guarantee)

Seluruh file di dalam paket ini sengaja diberi awalan nama unik (`whatsapp_`, `WhatsApp`, `0001_wa_`) dan namespace khusus agar tidak bertabrakan dengan file default Laravel.

| File Bawaan Laravel Anda | File yang Kami Gunakan | Risiko Menimpa? |
| :--- | :--- | :--- |
| `app/Http/Controllers/Controller.php` | `app/Http/Controllers/WhatsAppOtpController.php` | ❌ **TIDAK MUNGKIN** |
| `app/Models/User.php` | `app/Models/WhatsAppOtpChallenge.php`, `WhatsAppSenderPool.php` | ❌ **TIDAK MUNGKIN** |
| `routes/api.php` & `routes/web.php` | `routes/whatsapp.php` | ❌ **TIDAK MUNGKIN** |
| `config/app.php` & `config/auth.php` | `config/whatsapp_gateway.php`, `config/whatsapp_otp.php` | ❌ **TIDAK MUNGKIN** |
| Migrasi bawaan `create_users_table` | `database/migrations/0001_wa_users.php` (hanya menambah kolom) | ❌ **TIDAK MUNGKIN** |
| File frontend bawaan Anda | `frontend/components/WhatsAppOtpForm.tsx`, `frontend/backoffice/*` | ❌ **TIDAK MUNGKIN** |

---

## 🚀 Fitur Utama

| Fitur | Penjelasan |
| :--- | :--- |
| **Login OTP WhatsApp** | Pelanggan cukup memasukkan nomor telepon dan menerima 6 digit kode OTP via WhatsApp. |
| **Pool Multi-Nomor Pengirim** | Pasang banyak nomor WhatsApp sekaligus dengan rotasi otomatis (*round-robin*) dan *failover* bila nomor terputus. |
| **9 Menu Portal Back-Office** | Konsol admin lengkap: Dashboard, Chats, Webhooks, Templates, Message Tester, Infrastructure, Plugins, Logs, dan Member Terverifikasi. |
| **Engine Gateway Mandiri** | Berjalan di kontainer Docker OpenWA v0.24.0 tanpa biaya langganan API cloud pihak ketiga. |

---

## 📁 Struktur Paket (`whatsapp-addon/`)

```text
whatsapp-addon/
├── app/
│   ├── Http/Controllers/
│   │   ├── WhatsAppOtpController.php                  # Endpoint publik OTP pelanggan
│   │   ├── Admin/WhatsAppGatewayController.php        # Kontrol sesi gateway
│   │   ├── Admin/WhatsAppSenderPoolController.php     # Manajemen pool multi-nomor pengirim
│   │   ├── Admin/WhatsAppVerifiedMembersController.php# Daftar member terverifikasi
│   │   └── BackOffice/WhatsAppMemberWebhookController.php # Penerima webhook internal
│   ├── Models/
│   │   ├── WhatsAppOtpChallenge.php                   # Model tantangan OTP
│   │   ├── WhatsAppOtpDelivery.php                    # Model log pengiriman pesan
│   │   └── WhatsAppSenderPool.php                     # Model pool nomor pengirim
│   ├── Providers/
│   │   └── WhatsAppOtpServiceProvider.php             # Service provider binding
│   └── Services/
│       ├── WhatsAppGatewayClient.php                  # HTTP client ke engine OpenWA
│       └── WhatsAppOtp/                               # Logika bisnis OTP & verifikasi HMAC
├── config/
│   ├── whatsapp_gateway.php                           # Konfigurasi koneksi gateway
│   └── whatsapp_otp.php                               # Aturan expired, cooldown, dan pool
├── database/migrations/
│   ├── 0001_wa_users.php                              # Menambah kolom telepon ke tabel users
│   ├── 0002_wa_otp.php                                # Membuat tabel otp_challenges & deliveries
│   └── 0003_wa_senders.php                            # Membuat tabel sender_pool
├── routes/
│   └── whatsapp.php                                   # Route terisolasi untuk WhatsApp
└── frontend/
    ├── components/
    │   ├── WhatsAppOtpForm.tsx                        # Form / modal login OTP pelanggan
    │   ├── WhatsAppSenderPoolPanel.tsx                # Panel manajemen nomor pengirim
    │   └── WhatsAppVerifiedMembersPanel.tsx           # Tabel member terverifikasi
    ├── backoffice/                                    # 9 Menu konsol gateway micro-frontend
    └── examples/
        └── AdminDashboardSidebarExample.tsx           # Contoh integrasi sidebar admin dashboard
```

---

## ⚡ Langkah Mudah Pemasangan

### Langkah 1: Jalankan Engine WhatsApp
Dari direktori utama repositori:
```bash
docker compose up -d
```
Engine gateway akan aktif di `http://127.0.0.1:18091`.

### Langkah 2: Salin File Add-on ke Proyek Laravel Anda
Salin seluruh isi folder `whatsapp-addon/` ke direktori proyek Laravel Anda:
```bash
# Linux / macOS
cp -r whatsapp-addon/* /path/ke/proyek-laravel-anda/

# Windows PowerShell
Copy-Item -Path "whatsapp-addon/*" -Destination "C:\path\ke\proyek-laravel-anda" -Recurse -Force
```
*Karena setiap nama file bersifat unik, proses ini hanya menggabungkan folder tanpa pernah menimpa file lama.*

### Langkah 3: Jalankan Migrasi Database
```bash
php artisan migrate
```
Perintah ini mengeksekusi 3 migrasi yang terisolasi:
1. `0001_wa_users.php` (menambahkan `phone_e164`, `phone_verified_at` ke `users`)
2. `0002_wa_otp.php` (membuat `otp_challenges` dan `otp_deliveries`)
3. `0003_wa_senders.php` (membuat `sender_pool`)

### Langkah 4: Daftarkan Route & Service Provider
Di file `routes/api.php`, tambahkan baris berikut di paling bawah:
```php
require __DIR__ . '/whatsapp.php';
```

Di file `bootstrap/providers.php` (Laravel 11+) atau `config/app.php` (Laravel 10), daftarkan:
```php
App\Providers\WhatsAppOtpServiceProvider::class,
```

### Langkah 5: Atur Environment Variable (`.env`)
Tambahkan variabel berikut ke file `.env` Anda:
```env
# Engine Gateway WhatsApp
OPENWA_BASE_URL=http://127.0.0.1:18091
OPENWA_DEFAULT_SESSION=otp-sender-1
OPENWA_API_KEY=

# Pengaturan Kriptografi OTP & Rate Limit
WA_OTP_HMAC_SECRET=kunci-rahasia-acak-minimal-32-karakter-di-sini
WA_OTP_TRANSPORT=openwa
WA_OTP_RATE_LIMIT_PER_MINUTE=5
WA_OTP_COOLDOWN_SECONDS=60
WA_OTP_EXPIRATION_MINUTES=5
```

---

## 🖥️ Integrasi 9 Menu Back-Office

Untuk memasang 9 menu konsol pengirim WhatsApp pada sidebar dashboard admin React Anda, silakan ikuti contoh pada:
`frontend/examples/AdminDashboardSidebarExample.tsx`.

Daftar 9 Menu:
1. **WhatsApp Sessions & Senders** (Kelola pairing QR & rotasi nomor)
2. **Verified Members** (Daftar customer yang verifikasi WhatsApp)
3. **Dashboard** (Konsol Gateway OpenWA)
4. **Chats** (Riwayat Chat Gateway)
5. **Webhooks** (Webhook Outgoing)
6. **Templates** (Template Pesan WhatsApp)
7. **Message Tester** (Uji Coba Pengiriman Pesan)
8. **Plugins** (Manajemen Ekstensi Gateway)
9. **Logs** (Log Operasional Server)

---

## 🔒 Arsitektur Keamanan
- **HMAC SHA-256**: Kode OTP tidak pernah disimpan dalam bentuk plaintext di database.
- **Konsumsi Atomik**: Verifikasi kode menggunakan query atomik bersyarat (`WHERE state = 'pending'`) untuk mencegah *double submit*.
- **hash_equals()**: Pengecekan kode kebal terhadap serangan *timing attack*.
- **Anti-CSWSH**: Reverse proxy memproteksi celah *Cross-Site WebSocket Hijacking*.

---
*Dibuat untuk integrasi enterprise Laravel yang aman, elegan, dan non-intrusif.*
