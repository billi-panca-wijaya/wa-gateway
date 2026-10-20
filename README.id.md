# WhatsApp Gateway & Back-Office Integration Engine

<p align="center">
  <strong>Infrastruktur WhatsApp Gateway Performa Tinggi dengan Multi-Sender Pool, Auto-Failover, dan Integrasi Native Laravel Admin</strong>
</p>

<p align="center">
  <a href="README.md"><strong>English</strong></a> •
  <a href="README.id.md"><strong>Bahasa Indonesia</strong></a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Author-Billi%20Panca%20Wijaya-25d366?style=for-the-badge&logo=whatsapp&logoColor=white" alt="Author"/>
  <img src="https://img.shields.io/badge/Node-22%20LTS-brightgreen?style=for-the-badge&logo=node.js" alt="Node"/>
  <img src="https://img.shields.io/badge/Laravel-11%20Native%20Ready-ff2d20?style=for-the-badge&logo=laravel" alt="Laravel"/>
  <img src="https://img.shields.io/badge/Docker-Production%20Ready-2496ed?style=for-the-badge&logo=docker" alt="Docker"/>
  <img src="https://img.shields.io/badge/License-MIT-blue?style=for-the-badge" alt="License"/>
</p>

---

## 📌 Ringkasan Proyek

Repository ini merupakan **WhatsApp Gateway Engine** kelas enterprise yang telah dirombak, dioptimasi, dan disesuaikan secara khusus untuk kebutuhan **integrasi Back-Office dan sistem transaksi online** (Autentikasi Member, OTP 4 Digit, Notifikasi Pesanan, dan Portal Pelanggan).

Berbeda dari gateway mandiri standar yang berjalan sebagai panel terpisah (*isolated standalone panel*), arsitektur pada repositori ini dirancang sebagai **fondasi infrastruktur *headless*** yang menyatu langsung ke sistem Laravel Admin:
* **Zero-Config Integrasi Native**: Modul controller dan service (`reference-laravel-client/`) langsung ditempatkan di dalam struktur Laravel Anda. Tidak perlu konfigurasi token API manual atau URL `.env` eksternal.
* **Multi-Sender Pool & Auto-Failover**: Mendukung banyak nomor WhatsApp sekaligus. Sistem membagi beban pengiriman (*Round-Robin*) dan seketika beralih ke nomor cadangan jika nomor utama mengalami limit atau gangguan.
* **OTP WhatsApp 4 Digit Aman (Zero-Leak)**: Verifikasi 4 digit angka yang cepat dan ramah pengguna dengan verifier stateless HMAC-SHA256 (tanpa menyimpan kode OTP mentah di database) serta pemulihan otomatis (*auto-restore*) untuk akun yang terhapus saat pengujian.
* **Pairing Realtime dengan Auto-Close Modal**: Menghubungkan nomor WhatsApp melalui Scan QR Code atau 8-digit Kode Tautan. Tampilan popup secara otomatis mendeteksi koneksi aktif (`ready/working`) dan menutup sendiri seketika.

---

## 🏗️ 1. Diagram Arsitektur & Ekosistem Sistem

Memahami bagaimana lapisan engine, pengelola, dan pengguna saling terhubung secara terstruktur:

```mermaid
flowchart TD
    classDef platform fill:#0f172a,stroke:#3b82f6,stroke-width:2px,color:#fff;
    classDef operator fill:#1e293b,stroke:#10b981,stroke-width:2px,color:#fff;
    classDef gateway fill:#18181b,stroke:#25d366,stroke-width:2px,color:#fff;
    classDef enduser fill:#064e3b,stroke:#34d399,stroke-width:2px,color:#fff;

    subgraph PLATFORM_LAYER ["1. CORE SISTEM BACKEND (LARAVEL)"]
        direction TB
        B1["WhatsApp OTP Service<br/>(OTP 4 Digit, HMAC-SHA256, Auto-Restore)"]:::platform
        B2["Multi-Sender Pool Manager<br/>(Rotasi Beban & Engine Failover)"]:::platform
        B3["Webhook & Event Dispatcher<br/>(Notifikasi member.verified HMAC)"]:::platform
    end

    subgraph OPERATOR_LAYER ["2. ANTARMUKA PENGELOLA & PENGGUNA (FRONTEND)"]
        direction LR
        O1["Panel Admin Back-Office<br/>(Admin Kelola Nomor & QR)"]:::operator
        O2["Etalase Web Toko / Portal<br/>(Pelanggan Login via OTP WA)"]:::operator
    end

    subgraph GATEWAY_LAYER ["3. MESIN WHATSAPP GATEWAY (OPENWA CORE)"]
        direction TB
        G1["Gateway REST API & Socket Controller"]:::gateway
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
    B2 -->|"3. Teruskan Pesan ke Engine"| G1
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

### 4. 🎨 Tampilan Terpadu Bergaya OpenWA
* Dirancang dengan estetika *sleek dark mode*, aksen hijau WhatsApp (`#25d366`), dan scrollbar kustom *ultra-thin* (6px).
* Menu terpadu **"Sesi & Nomor Pengirim WA"**, menjaga pengaturan internal server tersembunyi di latar belakang.

---

## 📂 4. Struktur Direktori Proyek

```
wa-gateway/
├── reference-laravel-client/     # 🌟 Modul referensi siap pakai untuk Laravel Admin
│   ├── src/
│   │   ├── Services/             # WhatsAppGatewayClient.php (Internal Caller & Failover)
│   │   └── Controllers/          # BackOffice & Webhook Controllers
│   └── README.md                 # Panduan cepat Laravel client
├── src/                          # Core WhatsApp Gateway Engine (NestJS + TypeScript)
│   ├── modules/
│   │   ├── session/              # Manajemen sesi WhatsApp (Baileys)
│   │   ├── message/              # Pengiriman pesan teks, media, dan template
│   │   ├── webhook/              # Pengolahan webhook & antrean outbox
│   │   └── auth/                 # Autentikasi API key & tenant routing
├── deploy/                       # Skrip deployment Docker produksi
│   ├── compose.yaml              # Docker Compose multi-session stack
│   └── nginx.conf                # Konfigurasi proxy Nginx
├── dashboard/                    # Source UI panel kontrol OpenWA (React)
├── docs/                         # Dokumentasi arsitektur & spesifikasi API
└── README.md                     # Dokumentasi utama proyek
```

---

## 💻 5. Integrasi Native Laravel (Zero-Config / Tanpa .env)

Modul ini dirancang untuk **Integrasi Native Zero-Config** langsung di dalam backend Laravel Anda. Anda **TIDAK PERLU** token API tambahan, kunci rahasia manual, atau URL `.env` eksternal.

### 1. Struktur Penempatan Berkas di Laravel
Tempatkan file controller dan service ke dalam direktori aplikasi Laravel Anda:
```
app/
├── Http/
│   └── Controllers/
│       └── BackOffice/
│           ├── WhatsAppGatewayBackOfficeController.php  # Controller panel admin
│           └── WhatsAppMemberWebhookController.php       # Event listener member terverifikasi
└── Services/
    └── WhatsAppGateway/
        ├── WhatsAppGatewayClient.php                    # Service pemanggil internal & failover
        └── SenderPoolService.php                        # Service rotasi pool nomor WhatsApp
```

### 2. Salin Berkas Modul
```bash
# Salin Service ke app/Services/WhatsAppGateway/
cp reference-laravel-client/src/Services/* app/Services/WhatsAppGateway/

# Salin Controller ke app/Http/Controllers/BackOffice/
cp reference-laravel-client/src/Controllers/* app/Http/Controllers/BackOffice/
```

### 3. Daftarkan Rute di `routes/api.php`
Memanfaatkan autentikasi sesi admin yang sudah aktif:
```php
use App\Http\Controllers\BackOffice\WhatsAppGatewayBackOfficeController;
use App\Http\Controllers\BackOffice\WhatsAppMemberWebhookController;

// Rute Kontrol Pengirim & Member Terverifikasi (Admin Only)
Route::prefix('backoffice/whatsapp')->middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('senders', [WhatsAppGatewayBackOfficeController::class, 'listSenders']);
    Route::post('senders', [WhatsAppGatewayBackOfficeController::class, 'addSender']);
    Route::post('senders/{id}/toggle', [WhatsAppGatewayBackOfficeController::class, 'toggleSender']);
    Route::get('senders/{id}/qr', [WhatsAppGatewayBackOfficeController::class, 'getQrCode']);
    Route::post('senders/{id}/pairing-code', [WhatsAppGatewayBackOfficeController::class, 'requestPairingCode']);
    Route::post('senders/{id}/test-send', [WhatsAppGatewayBackOfficeController::class, 'testSend']);
    Route::get('verified-members', [WhatsAppGatewayBackOfficeController::class, 'verifiedMembers']);
});

// Listener Event Saat Pelanggan Berhasil Verifikasi OTP
Route::post('webhooks/whatsapp/member-verified', WhatsAppMemberWebhookController::class)
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
```

---

## 🛠️ 6. Katalog API Endpoints Gateway

| Method | Endpoint | Deskripsi |
|---|---|---|
| `GET` | `/api/v1/admin/sender-pool` | Mengambil seluruh daftar nomor WhatsApp di pool beserta status dan statistik rotasi |
| `POST` | `/api/v1/admin/sender-pool` | Menambahkan nomor/sesi baru (`session_id`, `label`) |
| `GET` | `/api/v1/admin/sender-pool/{id}` | Detail nomor + status live dari container OpenWA |
| `DELETE` | `/api/v1/admin/sender-pool/{id}` | Menghapus nomor dari pool rotasi |
| `POST` | `/api/v1/admin/sender-pool/{id}/toggle` | Menunda (*pause*) atau mengaktifkan kembali nomor |
| `POST` | `/api/v1/admin/sender-pool/{id}/sync` | Sinkronisasi status dan nomor telepon live dari engine |
| `GET` | `/api/v1/admin/sender-pool/{id}/qr` | Mengambil data QR code (base64 PNG) untuk scan pairing |
| `POST` | `/api/v1/admin/sender-pool/{id}/pairing-code` | Meminta 8-digit kode pairing WhatsApp via nomor HP |
| `POST` | `/api/v1/admin/sender-pool/{id}/test-send` | Mengirim pesan WhatsApp uji coba dari nomor pengirim ini |
| `GET` | `/api/v1/admin/verified-members` | Daftar pengguna yang telah terverifikasi via OTP WhatsApp |

---

## 🐳 7. Menjalankan Engine Gateway (Docker)

1. **Clone Repository:**
   ```bash
   git clone https://github.com/billi-panca-wijaya/wa-gateway.git
   cd wa-gateway/deploy
   ```

2. **Jalankan Kontainer Gateway:**
   ```bash
   docker compose up -d
   ```

3. **Periksa Status Layanan:**
   ```bash
   docker compose ps
   ```

---

## 🔒 8. Kebijakan Keamanan & Data Pribadi

1. **Kredensial Terenkripsi**: API key pada tabel pool disimpan terenkripsi menggunakan AES-256 (`Crypt::encryptString`) dan tidak pernah diekspos dalam format plaintext di respons API.
2. **Verifier Stateless HMAC**: Kode OTP diverifikasi melalui tanda tangan HMAC-SHA256 acak sekali pakai (*single-use*); kode OTP mentah tidak disimpan di database.
3. **Isolasi Jaringan Internal**: Engine WhatsApp berkomunikasi secara privat di dalam jaringan bridge Docker internal (`http://openwa:2785`), tidak dapat diakses dari internet publik.

---

## 👤 Pengembang & Hak Cipta

Dikembangkan dan disesuaikan oleh:
* **Billi Panca Wijaya** ([GitHub: @billi-panca-wijaya](https://github.com/billi-panca-wijaya))

Dilisensikan di bawah lisensi [MIT License](LICENSE).
