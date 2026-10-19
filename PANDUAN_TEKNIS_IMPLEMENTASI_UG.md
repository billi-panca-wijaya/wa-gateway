# 🛠️ PANDUAN TEKNIS IMPLEMENTASI WEB UG (FRONTEND & BACKEND)

> **Target Pembaca:** Tim Developer Backend (Laravel) & Frontend (React / Vue / Blade / HTML).  
> **Tujuan:** Panduan langkah demi langkah cara menanam (*implement*) modul WhatsApp Gateway dan sinkronisasi data member ke Web UG yang asli.

---

## 🏗️ 1. PETA ARSITEKTUR & PEMBAGIAN TUGAS

```
                                      [ WEB UG ASLI ]
                  ┌─────────────────────────────────────────────────────┐
                  │ FRONTEND (UI/UX)                                    │
                  │ - Form Input Nomor Telepon                          │
                  │ - Komponen Input OTP 4 Digit (Auto-Focus)           │
                  │ - Timer Kirim Ulang (30 Detik)                      │
                  └──────────────────────────┬──────────────────────────┘
                                             │ HTTP REST API
                                             ▼
                  ┌─────────────────────────────────────────────────────┐
                  │ BACKEND LARAVEL (UG)                                │
                  │ - WhatsAppOtpController.php                         │
                  │ - OtpService.php (HMAC-SHA256, 4 Digit)             │
                  │ - PooledTransport.php (Multi-Sender & Auto-Failover)│
                  │ - SenderPoolService.php (Manajemen Nomor)           │
                  └──────────────┬───────────────────────┬──────────────┘
            Internal Docker HTTP │                       │ Webhook HTTPS
           (http://openwa:2785)  ▼                       ▼ (HMAC SHA-256)
             ┌─────────────────────────┐   ┌───────────────────────────┐
             │ DOCKER ENGINE (OpenWA)  │   │ AGENT (Master Back-Office)│
             │ Baileys / WA Web Socket │   │ - Database Master Member  │
             └───────────┬─────────────┘   │ - Wallet, Saldo & Transaksi│
                         ▼                 └───────────────────────────┘
                [ JARINGAN WHATSAPP ]
```

---

## 💻 2. PANDUAN UNTUK TIM BACKEND (LARAVEL UG)

### A. Struktur File yang Perlu Ditanam
Salin modul service dan controller berikut ke dalam direktori Laravel UG:

```
app/
├── Http/
│   └── Controllers/
│       └── Api/
│           ├── WhatsAppOtpController.php           # Endpoint publik login pelanggan
│           └── Admin/
│               └── SenderPoolController.php        # Endpoint admin pengelola nomor pengirim
└── Services/
    └── WhatsAppOtp/
        ├── OtpService.php                          # Core logic OTP 4 digit & verifier
        ├── SenderPoolService.php                   # Rotasi nomor pengirim & cooldown
        ├── OpenWaSessionClient.php                 # Penghubung internal ke engine OpenWA
        ├── PhoneNumber.php                         # Normalisasi nomor telepon (+62)
        ├── TenantResolver.php                      # Multi-tenant host resolver
        └── Transport/
            ├── OtpTransport.php                    # Interface transport
            ├── OpenWaTransport.php                 # Driver pengirim pesan OpenWA
            ├── PooledTransport.php                 # Driver rotasi multi-nomor & failover
            └── DeliveryResult.php                  # Data object status pengiriman
```

---

### B. Struktur Tabel Database Migration

#### 1. Tabel Pool Nomor Pengirim (`sender_pool`)
```php
Schema::create('sender_pool', function (Blueprint $table) {
    $table->id();
    $table->string('tenant_key', 64)->default('default');
    $table->string('session_id', 128);            // Contoh: otp-sender-1
    $table->string('label', 128)->nullable();     // Label nomor (misal: CS Utama)
    $table->text('api_key_encrypted')->nullable(); // Enkripsi AES-256 jika ada API key
    $table->string('status', 24)->default('active'); // active | suspended | disconnected
    $table->string('phone_e164', 20)->nullable(); // Nomor WA yang terhubung
    $table->string('push_name', 128)->nullable(); // Nama akun WA
    $table->timestamp('last_used_at')->nullable(); // Untuk rotasi round-robin
    $table->timestamp('suspended_at')->nullable(); // Waktu mulai cooldown jika gagal
    $table->string('suspend_reason', 255)->nullable();
    $table->unsignedInteger('fail_count')->default(0);
    $table->timestamps();

    $table->unique(['tenant_key', 'session_id']);
    $table->index(['tenant_key', 'status', 'last_used_at']);
});
```

#### 2. Tabel Tantangan OTP (`otp_challenges`)
```php
Schema::create('otp_challenges', function (Blueprint $table) {
    $table->string('id', 64)->primary();
    $table->string('tenant_key', 64)->default('default');
    $table->string('phone_e164', 20);
    $table->string('purpose', 32)->default('login');
    $table->string('verifier', 128)->nullable(); // HMAC hash (bukan plaintext kode!)
    $table->string('state', 24)->default('pending'); // pending | verified | expired | locked
    $table->unsignedSmallInteger('attempts')->default(0);
    $table->unsignedSmallInteger('max_attempts')->default(5);
    $table->timestamp('expires_at');
    $table->timestamp('resend_available_at');
    $table->string('idempotency_key', 128)->nullable();
    $table->timestamps();
});
```

---

### C. Konfigurasi `config/whatsapp_otp.php`
```php
return [
    'default_tenant' => env('WA_OTP_DEFAULT_TENANT', 'default'),
    'default_region' => env('WA_OTP_DEFAULT_REGION', 'ID'),
    
    // Spesifikasi OTP
    'code_length' => 4,                        // Wajib 4 digit
    'ttl_seconds' => 300,                      // Masa berlaku 5 menit
    'resend_cooldown_seconds' => 30,           // Cooldown kirim ulang 30 detik
    'max_attempts' => 5,                       // Maksimal 5x salah ketik

    // Template Pesan WA
    'message_template' => 'Kode masuk :app Anda: :code. Berlaku :minutes menit. Jangan berikan kode ini kepada siapa pun.',

    // Koneksi Internal ke Mesin OpenWA
    'openwa' => [
        'base_url' => env('OPENWA_BASE_URL', 'http://openwa:2785'),
        'api_key' => env('OPENWA_API_KEY'),
        'session_id' => env('OPENWA_SESSION_ID', 'otp-sender-1'),
    ],

    // Webhook Menyerahkan Data ke AGENT
    'webhook' => [
        'verified_member_url' => env('AGENT_VERIFIED_MEMBER_WEBHOOK_URL'),
        'secret' => env('AGENT_WEBHOOK_SECRET'),
    ],
];
```

---

### D. Pendaftaran Rute di `routes/api.php`
```php
use App\Http\Controllers\Api\WhatsAppOtpController;
use App\Http\Controllers\Api\Admin\SenderPoolController;

// 1. ENDPOINT STOREFRONT (UNTUK PELANGGAN)
Route::prefix('auth/whatsapp')->middleware('throttle:60,1')->group(function () {
    Route::post('request-otp', [WhatsAppOtpController::class, 'requestOtp']);
    Route::post('verify-otp', [WhatsAppOtpController::class, 'verifyOtp']);
    Route::post('resend-otp', [WhatsAppOtpController::class, 'resendOtp']);
});

// 2. ENDPOINT ADMIN (MANAJEMEN NOMOR WA)
Route::prefix('admin/sender-pool')
    ->middleware(['auth:sanctum', 'role:admin'])
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
```

---

### E. Alur Menyerahkan Data ke AGENT (Webhook Otomatis)
Ketika user berhasil memverifikasi OTP di UG, `OtpService.php` secara otomatis mengeksekusi penyerahan data ke AGENT:

```php
private function dispatchVerifiedMemberWebhook(User $user): void
{
    $url = config('whatsapp_otp.webhook.verified_member_url');
    if (empty($url)) return;

    $payload = [
        'event' => 'member.verified',
        'user_id' => $user->id,
        'uuid' => $user->uuid,
        'tenant_key' => $user->tenant_key,
        'phone_e164' => $user->phone_e164,
        'phone_verified_at' => optional($user->phone_verified_at)->toIso8601String(),
        'timestamp' => now()->toIso8601String(),
    ];

    $secret = config('whatsapp_otp.webhook.secret');
    $signature = hash_hmac('sha256', json_encode($payload), $secret);

    // Kirim data ke AGENT
    Http::timeout(5)
        ->withHeaders(['X-Webhook-Signature' => $signature])
        ->post($url, $payload);
}
```

---

## 🎨 3. PANDUAN UNTUK TIM FRONTEND (STOREFRONT UG)

### A. Alur Interaksi UI/UX Pelanggan (State Machine)
Formulir login/register di web UG memiliki 2 kondisi tampilan (*State*):
1. **State 1: Input Nomor Telepon**
   - User mengetik nomor WA (misal `08123456789`).
   - Validasi: Nomor harus angka, panjang 9–14 digit.
   - Klik tombol **"Kirim Kode Masuk"**.
2. **State 2: Input 4 Digit OTP**
   - Tampilkan 4 kotak angka terpisah.
   - Setiap kotak otomatis fokus ke kotak berikutnya saat 1 angka diketik.
   - Saat angka ke-4 selesai diketik, sistem **otomatis menembak verifikasi** tanpa user perlu menekan tombol submit lagi (*auto-submit*).
   - Tampilkan penghitung waktu mundur kirim ulang: **30 detik**.

---

### B. Contoh Implementasi API Call (JavaScript / TypeScript / Axios)

#### 1. Meminta Kode Masuk (Request OTP)
```javascript
async function requestOtp(phoneNumber) {
    try {
        const response = await axios.post('/api/v1/auth/whatsapp/request-otp', {
            phone: phoneNumber // Contoh: "081234567890"
        });

        // Simpan data challenge untuk langkah verifikasi
        const challengeId = response.data.challenge_id;
        const resendAfter = response.data.resend_after; // 30 (detik)
        
        // Pindahkan tampilan layar ke kotak 4 Digit OTP
        showOtpInputScreen(challengeId, resendAfter);
    } catch (error) {
        alert(error.response?.data?.message || 'Gagal mengirim OTP.');
    }
}
```

#### 2. Verifikasi 4 Digit Kode (Verify OTP)
```javascript
async function verifyOtp(challengeId, fourDigitsCode) {
    try {
        const response = await axios.post('/api/v1/auth/whatsapp/verify-otp', {
            challenge_id: challengeId,
            code: fourDigitsCode // Contoh: "5821"
        });

        if (response.data.authenticated) {
            // Berhasil login! Cookie sesi Sanctum otomatis terpasang
            window.location.href = response.data.next || '/dashboard';
        }
    } catch (error) {
        const remainingAttempts = error.response?.data?.attempts_remaining;
        alert(`Kode salah. Sisa percobaan: ${remainingAttempts}`);
    }
}
```

#### 3. Kirim Ulang Kode (Resend OTP)
```javascript
async function resendOtp(challengeId) {
    try {
        const response = await axios.post('/api/v1/auth/whatsapp/resend-otp', {
            challenge_id: challengeId
        });

        alert('Kode baru 4 digit telah dikirim via WhatsApp!');
        // Reset timer countdown 30 detik
        startCountdown(response.data.resend_after);
    } catch (error) {
        alert(error.response?.data?.message || 'Tunggu beberapa saat sebelum kirim ulang.');
    }
}
```

---

## 🚀 4. DIMANA DAN BAGAIMANA MENAMBAHKAN FITUR BARU?

### Kasus A: Mengirim Struk Transaksi / Notifikasi Pembelian
**Di mana?** Di controller transaksi Laravel UG (misal `CheckoutController.php` atau `PaymentCallbackController.php`).

```php
use App\Services\WhatsAppOtp\Transport\PooledTransport;

class PaymentCallbackController extends Controller
{
    public function onPaymentSuccess(Request $request, PooledTransport $wa)
    {
        $order = Order::find($request->order_id);

        $pesan = "Halo {$order->customer_name}!\n"
               . "Pembayaran sebesar Rp " . number_format($order->amount) . " telah kami terima.\n"
               . "Invoice: #{$order->invoice_number}\n"
               . "Item: {$order->item_name}\n\n"
               . "Terima kasih telah bertransaksi di toko kami!";

        // Otomatis terkirim lewat nomor WhatsApp pool yang sedang aktif
        $wa->send($order->customer_phone, $pesan, 'invoice-' . $order->id);
    }
}
```

### Kasus B: Mengirim Gambar / PDF Brosur atau Invoice
Di `app/Services/WhatsAppOtp/Transport/OpenWaTransport.php`, gunakan endpoint OpenWA `/messages/send-image` atau `/messages/send-file`:

```php
public function sendInvoicePdf(string $phoneE164, string $pdfUrl, string $caption)
{
    return Http::baseUrl($this->config['base_url'])
        ->withHeaders(['X-API-Key' => $this->config['api_key']])
        ->post("/api/sessions/{$this->config['session_id']}/messages/send-file", [
            'chatId' => ltrim($phoneE164, '+') . '@c.us',
            'file' => [
                'mimetype' => 'application/pdf',
                'url' => $pdfUrl,
                'filename' => 'Invoice.pdf',
            ],
            'caption' => $caption,
        ]);
}
```

---

## ✅ CHECKLIST DEPLOYMENT KE WEB UG ASLI

1. [ ] Jalankan kontainer Docker `openwa` di server hosting/VPS UG.
2. [ ] Jalankan migration tabel `sender_pool`, `otp_challenges`, dan `otp_deliveries`.
3. [ ] Buka Panel Admin UG -> Hubungkan nomor WhatsApp admin/pengirim via scan QR / pairing code.
4. [ ] Uji coba kirim pesan dari Panel Admin (*Test Send*).
5. [ ] Coba lakukan registrasi/login dari halaman depan toko UG.
6. [ ] Pastikan webhook ke sistem AGENT menerima event `member.verified` dengan tanda tangan HMAC yang sah.
