# Reference Laravel Integration Package: WhatsApp Gateway

Paket integrasi ini siap langsung dipasang ke dalam framework Laravel perusahaan Anda tanpa perlu setup environment variable (`.env`) tambahan (**Zero-Config**).

---

## 📂 Struktur Paket yang Disediakan

```
reference-laravel-client/
├── config/
│   └── whatsapp_otp.php                                  # Konfigurasi OTP, rate limit, dan template pesan
├── database/migrations/
│   ├── 2026_10_08_000002_create_otp_challenges_tables.php # Tabel tantangan OTP & log pengiriman
│   └── 2026_10_14_000001_create_sender_pool_table.php    # Tabel kolam nomor pengirim WhatsApp
└── src/
    ├── Controllers/
    │   ├── WhatsAppOtpController.php                     # Endpoint login/verifikasi OTP member
    │   ├── SenderPoolController.php                      # Endpoint panel admin (manajemen nomor, QR, pairing)
    │   ├── VerifiedMembersController.php                 # Endpoint statistik member terverifikasi
    │   └── WhatsAppMemberWebhookController.php           # Event listener webhook member.verified
    └── Services/WhatsAppOtp/
        ├── OtpService.php                                # Logika utama pembuatan OTP 4 digit & verifier HMAC
        ├── SenderPoolService.php                         # Pengatur rotasi nomor & status pengirim
        ├── OpenWaSessionClient.php                       # Driver koneksi internal ke engine WhatsApp
        ├── PhoneNumber.php                               # Normalisasi format nomor (+62)
        ├── TenantResolver.php                            # Resolver multi-tenant Agent
        ├── OtpException.php                              # Custom exceptions
        └── Transport/
            ├── OtpTransport.php                          # Interface transport
            ├── PooledTransport.php                       # Driver rotasi multi-nomor & auto-failover
            ├── OpenWaTransport.php                       # Driver HTTP ke engine lokal
            └── DeliveryResult.php                        # Status hasil pengiriman
```

---

## 🚀 Panduan Pemasangan ke Laravel (Hanya 3 Langkah)

### 1. Salin File ke Direktori Laravel Anda
```bash
# 1. Salin Config
cp config/whatsapp_otp.php config/

# 2. Salin Migrations
cp database/migrations/* database/migrations/

# 3. Salin Services
cp -r src/Services/WhatsAppOtp app/Services/

# 4. Salin Controllers
cp src/Controllers/WhatsAppOtpController.php app/Http/Controllers/Api/
cp src/Controllers/SenderPoolController.php app/Http/Controllers/Api/Admin/
cp src/Controllers/VerifiedMembersController.php app/Http/Controllers/Api/Admin/
cp src/Controllers/WhatsAppMemberWebhookController.php app/Http/Controllers/Api/
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

// 1. ENDPOINT MEMBER STOREFRONT (OTP 4 DIGIT)
Route::prefix('auth/whatsapp')->middleware('throttle:60,1')->group(function () {
    Route::post('request-otp', [WhatsAppOtpController::class, 'requestOtp']);
    Route::post('verify-otp', [WhatsAppOtpController::class, 'verifyOtp']);
    Route::post('resend-otp', [WhatsAppOtpController::class, 'resendOtp']);
});

// 2. ENDPOINT ADMIN BACK-OFFICE (MANAJEMEN NOMOR & QR)
Route::prefix('admin/sender-pool')
    ->middleware(['auth:sanctum', 'role:super-admin|admin'])
    ->controller(SenderPoolController::class)
    ->group(function () {
        Route::get('/', 'index');                 // Ambil daftar nomor
        Route::post('/', 'store');               // Tambah nomor/sesi baru
        Route::get('{id}', 'show');              // Detail nomor
        Route::post('{id}/toggle', 'toggle');    // Jeda / Aktifkan nomor
        Route::post('{id}/sync', 'sync');        // Sinkronisasi status live
        Route::get('{id}/qr', 'qr');             // Ambil QR code scan
        Route::post('{id}/pairing-code', 'pairingCode'); // Minta 8-digit kode tautan
        Route::post('{id}/test-send', 'testSend');       // Uji kirim pesan
        Route::delete('{id}', 'destroy');        // Hapus nomor
    });

// 3. DAFTAR MEMBER TERVERIFIKASI
Route::prefix('admin/verified-members')
    ->middleware(['auth:sanctum', 'role:super-admin|admin'])
    ->controller(VerifiedMembersController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::get('{id}', 'show');
    });

// 4. WEBHOOK MEMBER VERIFIED
Route::post('webhooks/whatsapp/member-verified', WhatsAppMemberWebhookController::class)
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
```
