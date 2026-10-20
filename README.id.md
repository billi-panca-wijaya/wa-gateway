# WhatsApp Gateway & Back-Office Integration Engine

<p align="center">
  <strong>Infrastruktur WhatsApp Gateway Ringan dengan Multi-Sender Pool, Auto-Failover, dan Integrasi Native Laravel Admin</strong>
</p>

<p align="center">
  <a href="README.md"><strong>English</strong></a> •
  <a href="README.id.md"><strong>Bahasa Indonesia</strong></a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Author-Billi%20Panca%20Wijaya-25d366?style=for-the-badge&logo=whatsapp&logoColor=white" alt="Author"/>
  <img src="https://img.shields.io/badge/Laravel-11%20Native%20Ready-ff2d20?style=for-the-badge&logo=laravel" alt="Laravel"/>
  <img src="https://img.shields.io/badge/Docker-Production%20Ready-2496ed?style=for-the-badge&logo=docker" alt="Docker"/>
  <img src="https://img.shields.io/badge/License-MIT-blue?style=for-the-badge" alt="License"/>
</p>

---

## 📌 Ringkasan Proyek

Repository ini menyediakan **WhatsApp Gateway Engine & Paket Integrasi Laravel** siap pakai yang dioptimalkan khusus untuk **operasional Back-Office dan alur transaksi** (Autentikasi Member, OTP 4 Digit, Notifikasi Pesanan, dan Portal Pelanggan).

Berbeda dari tumpukan kode yang rumit dan membutuhkan kompilasi JavaScript, repositori ini telah disederhanakan murni menjadi dua bagian:
1. **Engine Docker Zero-Config** (`docker-compose.yml`): Satu file Docker Compose yang langsung menjalankan engine socket WhatsApp (Baileys) dari image resmi siap pakai tanpa perlu kompilasi Node.js lokal.
2. **Paket Native Laravel** (`reference-laravel-client/`): Berkas service, controller, migration, dan konfigurasi yang siap disalin langsung ke dalam aplikasi Laravel perusahaan Anda tanpa perlu token API eksternal atau URL `.env`.

---

## 🏗️ 1. Diagram Arsitektur & Ekosistem Sistem

Memahami bagaimana lapisan engine, pengelola, dan pengguna saling terhubung secara terstruktur:

```mermaid
flowchart TD
    classDef platform fill:#0f172a,stroke:#3b82f6,stroke-width:2px,color:#fff;
    classDef operator fill:#1e293b,stroke:#10b981,stroke-width:2px,color:#fff;
    classDef gateway fill:#18181b,stroke:#25d366,stroke-width:2px,color:#fff;
    classDef enduser fill:#064e3b,stroke:#34d399,stroke-width:2px,color:#fff;

    subgraph PLATFORM_LAYER ["1. CORE SISTEM LARAVEL (PHP)"]
        direction TB
        B1["WhatsApp OTP Service<br/>(OTP 4 Digit, HMAC-SHA256, Auto-Restore)"]:::platform
        B2["Multi-Sender Pool Manager<br/>(Rotasi Beban & Engine Failover)"]:::platform
        B3["Webhook & Event Dispatcher<br/>(Notifikasi member.verified)"]:::platform
    end

    subgraph OPERATOR_LAYER ["2. ANTARMUKA PENGELOLA & PENGGUNA (FRONTEND)"]
        direction LR
        O1["Panel Admin Back-Office<br/>(Admin Kelola Nomor & QR)"]:::operator
        O2["Etalase Web Toko / Portal<br/>(Pelanggan Login via OTP WA)"]:::operator
    end

    subgraph GATEWAY_LAYER ["3. MESIN WHATSAPP GATEWAY (DOCKER)"]
        direction TB
        G1["Gateway REST API & Socket Controller<br/>(http://openwa:2785)"]:::gateway
        subgraph ACTIVE_POOL ["Kolam Sesi WhatsApp Terhubung"]
            S1["Pengirim 1: Nomor WhatsApp Utama"]:::gateway
            S2["Pengirim 2: Nomor Cadangan (Failover)"]:::gateway
        end
    end

    subgraph WA_NETWORK ["4. JARINGAN RESMI WHATSAPP"]
        W1["WhatsApp Cloud Server & Ponsel Pelanggan"]:::enduser
    end

    %% Interaksi alur data
    O2 -->|"1. Minta OTP / Login"| B1
    O1 -->|"Hubungkan Nomor & Pantau Status"| B2
    B1 -->|"2. Pilih Nomor Pengirim yang Ready"| B2
    B2 -->|"3. Teruskan Pesan via HTTP"| G1
    G1 -->|"Rotasi Sesi Aktif"| S1 & S2
    S1 & S2 -->|"4. Kirim Pesan ke HP"| W1
    W1 -.->|"5. Pelanggan Menerima Kode 4 Digit"| O2
    O2 -->|"6. Verifikasi OTP & Terbitkan Sesi"| B1
    B1 -.->|"7. Picu Event Member Terverifikasi"| B3
```

---

## 🔄 2. Diagram Alur Kerja Operasional (Step-by-Step)

### A. Alur Login & Verifikasi OTP 4 Digit
Bagaimana pelanggan masuk ke sistem dengan mudah tanpa perlu mengingat kata sandi rumit:

```mermaid
sequenceDiagram
    autonumber
    actor Pelanggan as 📱 Pelanggan
    participant Web as 🛒 Web Toko / Portal
    participant Laravel as ⚙️ Backend Laravel
    participant Gateway as 🚀 WhatsApp Gateway
    participant WA as 💬 Server WhatsApp

    Pelanggan->>Web: Masukkan Nomor WhatsApp (misal: 08123456789)
    Web->>Laravel: POST /api/v1/auth/whatsapp/request-otp
    Laravel->>Laravel: Generate kode 4 digit & siapkan verifier HMAC
    Laravel->>Gateway: Kirim pesan lewat nomor yang paling santai (Round-Robin)
    Gateway->>WA: Teruskan teks via socket Baileys aktif
    WA->>Pelanggan: Pesan masuk di HP: "Kode masuk Anda: 5821"
    Pelanggan->>Web: Ketik 4 angka di layar
    Web->>Laravel: POST /api/v1/auth/whatsapp/verify-otp
    alt Akun Soft-deleted / Baru
        Laravel->>Laravel: Otomatis pulihkan (restore) & aktifkan akun
    end
    Laravel->>Web: Status Berhasil (Cookie Sesi Sanctum Aktif)
    Web->>Pelanggan: Masuk ke Dashboard Pelanggan
```

---

### B. Alur Multi-Sender Auto-Failover (Anti-Macet)
Bagaimana transaksi pelanggan diselamatkan jika ada nomor pengirim yang bermasalah:

```mermaid
sequenceDiagram
    autonumber
    participant Engine as ⚙️ Sender Pool Engine
    participant S1 as 📵 Nomor 1 (Utama)
    participant S2 as 📱 Nomor 2 (Cadangan)
    participant Pelanggan as 👤 HP Pelanggan

    Engine->>S1: Coba kirim pesan OTP
    alt Nomor Utama Normal
        S1->>Pelanggan: Pesan OTP terkirim sukses
    else Nomor 1 Terputus / Rate-Limited / Diblokir
        S1-->>Engine: Status pengiriman gagal
        Engine->>Engine: Masukkan Nomor 1 ke masa jeda (Cooldown)
        Engine->>S2: AUTO-FAILOVER: Seketika alihkan ke Nomor 2
        S2->>Pelanggan: Pesan OTP tetap terkirim ke HP pelanggan tanpa error!
    end
```

---

### C. Alur Pairing Nomor WhatsApp dengan Auto-Dismiss Modal
Kemudahan pengelola dalam menyambungkan nomor WhatsApp tanpa perlu me-refresh layar:

```mermaid
sequenceDiagram
    autonumber
    actor Admin as 👨‍💼 Admin Back-Office
    participant Modal as 🖥️ Modal Hubungkan WA
    participant Backend as ⚙️ API Admin Laravel
    participant OpenWA as 🚀 Engine OpenWA
    actor HP as 📱 HP Operasional Kantor

    Admin->>Modal: Buka Modal "Hubungkan WhatsApp"
    Modal->>OpenWA: Minta QR Code atau 8-Digit Kode Tautan
    OpenWA-->>Modal: Tampilkan QR Code / Kode Tautan
    Admin->>HP: Scan QR atau masukkan Kode Tautan di WA HP
    loop Polling Status Realtime (Tiap 2.5 Detik)
        Modal->>Backend: Cek status koneksi & sinkronkan nomor HP
        Backend->>OpenWA: Cek status sesi
        OpenWA-->>Backend: Status berubah: "ready / working"
        Backend-->>Modal: Sesi terhubung!
    end
    Modal->>Modal: Otomatis menutup popup modal
    Modal->>Admin: Tampilkan banner hijau "WhatsApp Terhubung"
```

---

## ⚡ 3. Fitur Utama & Keunggulan Arsitektur

### 1. 🔄 Multi-Sender Pool & Failover
* **Rotasi Beban (Round-Robin)**: Membagi volume pengiriman pesan ke seluruh nomor aktif berdasarkan `last_used_at` agar nomor awet dan terhindar dari pemblokiran.
* **Auto-Failover Cerdas**: Jika salah satu nomor mengalami limit atau gangguan jaringan, sistem otomatis mengalihkan pengiriman ke nomor cadangan berikutnya di pool.
* **Pemulihan Otomatis (Self-Healing)**: Nomor yang sempat gagal akan masuk masa jeda (*cooldown*) dan otomatis aktif kembali setelah periode pemulihan.

### 2. 🔢 OTP WhatsApp 4 Digit (Zero-Leak)
* **Format**: 4 digit angka acak (`0000`–`9999`) yang ringkas dan ramah pengguna di ponsel.
* **Keamanan Tanpa Kebocoran**: Diverifikasi melalui tanda tangan HMAC-SHA256 stateless; kode OTP mentah tidak pernah disimpan di database.
* **Auto-Restore Akun**: Memulihkan akun yang sebelumnya di-soft-delete dalam masa pengujian (*testing*) begitu verifikasi nomor berhasil.
* **Kirim Ulang Terproteksi**: Cooldown kirim ulang 30 detik yang ditegakkan dari sisi server.

### 3. 📲 Pilihan Dua Metode Pairing
* **Scan Kode QR**: Alur pairing standar seperti WhatsApp Web.
* **Kode Tautan 8 Karakter**: Menghubungkan nomor via notifikasi WhatsApp di HP tanpa kamera.
* **Auto-Dismiss UI**: Modal pairing otomatis mendeteksi koneksi aktif dan menutup sendiri.

---

## 📂 4. Struktur Direktori Proyek yang Bersih

```
wa-gateway/
├── docker-compose.yml            # 🐳 Zero-config Docker stack untuk menjalankan Engine WhatsApp
├── reference-laravel-client/     # 🌟 Paket integrasi lengkap siap pakai untuk Laravel
│   ├── config/
│   │   └── whatsapp_otp.php      # File konfigurasi Laravel
│   ├── database/migrations/      # Migration database (sender_pool & otp_challenges)
│   ├── src/
│   │   ├── Controllers/          # Controller WhatsAppOtpController, SenderPoolController, dll
│   │   └── Services/WhatsAppOtp/ # Service OtpService, SenderPoolService, PooledTransport, dll
│   └── README.md                 # Panduan instalasi ke Laravel
├── README.md                     # Dokumentasi utama (Bahasa Inggris)
├── README.id.md                  # Dokumentasi utama (Bahasa Indonesia)
└── LICENSE                       # Lisensi MIT
```

---

## 💻 5. Integrasi Native Laravel (Zero-Config / Tanpa .env)

Modul ini dirancang untuk **Integrasi Native Zero-Config** langsung di dalam backend Laravel Anda. Anda **TIDAK PERLU** token API tambahan, kunci rahasia manual, atau URL `.env` eksternal.

### 1. Salin Berkas Paket ke Laravel Anda
```bash
# 1. Salin Konfigurasi
cp reference-laravel-client/config/whatsapp_otp.php config/

# 2. Salin Migration Database
cp reference-laravel-client/database/migrations/* database/migrations/

# 3. Salin Services
cp -r reference-laravel-client/src/Services/WhatsAppOtp app/Services/

# 4. Salin Controllers
cp reference-laravel-client/src/Controllers/WhatsAppOtpController.php app/Http/Controllers/Api/
cp reference-laravel-client/src/Controllers/SenderPoolController.php app/Http/Controllers/Api/Admin/
cp reference-laravel-client/src/Controllers/VerifiedMembersController.php app/Http/Controllers/Api/Admin/
cp reference-laravel-client/src/Controllers/WhatsAppMemberWebhookController.php app/Http/Controllers/Api/
```

### 2. Jalankan Migration Database
```bash
php artisan migrate
```

### 3. Daftarkan Rute di `routes/api.php`
```php
use App\Http\Controllers\Api\WhatsAppOtpController;
use App\Http\Controllers\Api\Admin\SenderPoolController;
use App\Http\Controllers\Api\Admin\VerifiedMembersController;
use App\Http\Controllers\Api\WhatsAppMemberWebhookController;

// 1. Endpoint Login / OTP Pelanggan
Route::prefix('auth/whatsapp')->middleware('throttle:60,1')->group(function () {
    Route::post('request-otp', [WhatsAppOtpController::class, 'requestOtp']);
    Route::post('verify-otp', [WhatsAppOtpController::class, 'verifyOtp']);
    Route::post('resend-otp', [WhatsAppOtpController::class, 'resendOtp']);
});

// 2. Endpoint Panel Admin Pengelola Nomor WhatsApp
Route::prefix('admin/sender-pool')
    ->middleware(['auth:sanctum', 'role:super-admin|admin'])
    ->controller(SenderPoolController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('{id}', 'show');
        Route::post('{id}/toggle', 'toggle');
        Route::post('{id}/sync', 'sync');
        Route::get('{id}/qr', 'qr');
        Route::post('{id}/pairing-code', 'pairingCode');
        Route::post('{id}/test-send', 'testSend');
        Route::delete('{id}', 'destroy');
    });

// 3. Daftar Member Terverifikasi
Route::prefix('admin/verified-members')
    ->middleware(['auth:sanctum', 'role:super-admin|admin'])
    ->controller(VerifiedMembersController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::get('{id}', 'show');
    });

// 4. Webhook Realtime Member Terverifikasi
Route::post('webhooks/whatsapp/member-verified', WhatsAppMemberWebhookController::class)
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
```

---

## 🐳 6. Menjalankan Engine WhatsApp (Docker)

Untuk menjalankan mesin background WhatsApp Baileys di server, cukup ketik:

```bash
docker compose up -d
```

* Selesai! Tanpa setup `.env` dan tanpa perlu compile Node.js.
* Engine akan berjalan otomatis di latar belakang dan melayani komunikasi privat di port `18091` (dan `http://openwa:2785` di jaringan bridge Docker).

---

## 🔒 7. Kebijakan Keamanan & Data Pribadi

1. **Kredensial Terenkripsi**: API key pada tabel pool disimpan terenkripsi menggunakan AES-256 (`Crypt::encryptString`) dan tidak pernah diekspos dalam format plaintext di respons API.
2. **Verifier Stateless HMAC**: Kode OTP diverifikasi melalui tanda tangan HMAC-SHA256 acak sekali pakai (*single-use*); kode OTP mentah tidak disimpan di database.
3. **Isolasi Jaringan Internal**: Engine WhatsApp berkomunikasi secara privat di dalam jaringan bridge Docker internal (`http://openwa:2785`), tidak dapat diakses dari internet publik.

---

## 👤 Pengembang & Hak Cipta

Dikembangkan dan disesuaikan oleh:
* **Billi Panca Wijaya** ([GitHub: @billi-panca-wijaya](https://github.com/billi-panca-wijaya))

Dilisensikan di bawah lisensi [MIT License](LICENSE).
