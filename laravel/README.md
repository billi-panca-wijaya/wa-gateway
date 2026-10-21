# WhatsApp Login Add-on — Laravel Integration Guide

[English](README.md) | [Bahasa Indonesia](README.id.md)

This folder contains all files needed to add WhatsApp OTP login and multi-sender management to your **existing** Laravel website. Nothing here replaces or overwrites your current files.

---

## Before You Start

**Requirements:**
- Laravel 10 or 11
- PHP 8.1+
- Docker (for the WhatsApp engine)
- Your Laravel website is already running

**What you'll get after installation:**
- Customers can log in using their WhatsApp phone number
- Admin panel to manage multiple WhatsApp sender numbers
- Automatic rotation and failover between sender numbers
- 9 new menu items in your admin dashboard

---

## Step 1: Start the WhatsApp Engine

In the root of this repository (where `docker-compose.yml` is):

```bash
docker compose up -d
```

This starts a WhatsApp engine container on port `18091` (internal: `2785`).  
No configuration needed — it just works.

---

## Step 2: Copy Files to Your Laravel Project

Copy these folders and files into your Laravel project.  
**All files are new — nothing overwrites your existing code.**

```bash
# Controllers (new folder: WhatsApp/)
cp app/Http/Controllers/WhatsAppOtpController.php         YOUR_LARAVEL/app/Http/Controllers/
cp -r app/Http/Controllers/Admin/                          YOUR_LARAVEL/app/Http/Controllers/Admin/
cp -r app/Http/Controllers/BackOffice/                     YOUR_LARAVEL/app/Http/Controllers/BackOffice/

# Models (3 new files)
cp app/Models/OtpChallenge.php                             YOUR_LARAVEL/app/Models/
cp app/Models/OtpDelivery.php                              YOUR_LARAVEL/app/Models/
cp app/Models/SenderPool.php                               YOUR_LARAVEL/app/Models/

# Services (new folder)
cp -r app/Services/WhatsAppOtp/                            YOUR_LARAVEL/app/Services/WhatsAppOtp/

# Provider (1 new file)
cp app/Providers/WhatsAppOtpServiceProvider.php            YOUR_LARAVEL/app/Providers/

# Config (1 new file)
cp config/whatsapp_otp.php                                 YOUR_LARAVEL/config/

# Migrations (3 new short files)
cp database/migrations/0001_wa_users.php                   YOUR_LARAVEL/database/migrations/
cp database/migrations/0002_wa_otp.php                     YOUR_LARAVEL/database/migrations/
cp database/migrations/0003_wa_senders.php                 YOUR_LARAVEL/database/migrations/

# Routes (1 new file)
cp routes/whatsapp.php                                     YOUR_LARAVEL/routes/
```

---

## Step 3: Register the Service Provider

**Laravel 11** — open `bootstrap/providers.php`:
```php
return [
    // ... your existing providers ...
    App\Providers\WhatsAppOtpServiceProvider::class,  // ← add this line
];
```

**Laravel 10** — open `config/app.php`, find the `providers` array:
```php
'providers' => [
    // ... your existing providers ...
    App\Providers\WhatsAppOtpServiceProvider::class,  // ← add this line
],
```

---

## Step 4: Add the Routes

Open your `routes/api.php` and add one line at the bottom:

```php
require __DIR__ . '/whatsapp.php';
```

---

## Step 5: Run Migrations

```bash
php artisan migrate
```

This will:
- Add `phone_e164`, `phone_verified_at`, `tenant_key` columns to your `users` table
- Create new table: `otp_challenges`
- Create new table: `otp_deliveries`
- Create new table: `sender_pool`

---

## Step 6: Add the User Model Trait (Optional)

If your `User` model doesn't already have the phone columns in its `$fillable`, add them:

```php
// app/Models/User.php
protected $fillable = [
    // ... your existing fields ...
    'tenant_key',
    'phone_e164',
    'phone_verified_at',
    'onboarding_completed_at',
];

protected function casts(): array
{
    return [
        // ... your existing casts ...
        'phone_verified_at' => 'datetime',
        'onboarding_completed_at' => 'datetime',
    ];
}
```

---

## That's It! Your API Endpoints Are Ready

After installation, these API routes are automatically available:

### Customer Endpoints (Public)
| Method | URL | Description |
|--------|-----|-------------|
| POST | `/api/v1/whatsapp/request-otp` | Send OTP to customer's phone |
| POST | `/api/v1/whatsapp/verify-otp` | Verify OTP code and log in |
| POST | `/api/v1/whatsapp/resend-otp` | Resend OTP code |

### Admin Endpoints (Requires auth + admin role)
| Method | URL | Description |
|--------|-----|-------------|
| GET | `/api/v1/admin/sender-pool` | List all sender numbers |
| POST | `/api/v1/admin/sender-pool` | Add new sender number |
| GET | `/api/v1/admin/sender-pool/{id}/qr` | Get QR code for pairing |
| POST | `/api/v1/admin/sender-pool/{id}/pairing-code` | Get 8-digit pairing code |
| POST | `/api/v1/admin/sender-pool/{id}/start` | Start sender session |
| POST | `/api/v1/admin/sender-pool/{id}/stop` | Stop sender session |
| POST | `/api/v1/admin/sender-pool/{id}/test-send` | Send test message |
| DELETE | `/api/v1/admin/sender-pool/{id}` | Remove sender |
| GET | `/api/v1/admin/verified-members` | List verified members |
| GET | `/api/v1/admin/whatsapp-gateway` | Gateway status |

---

## Files Reference

```
laravel/                              ← this folder
├── app/
│   ├── Http/Controllers/
│   │   ├── WhatsAppOtpController.php           # Customer OTP endpoints
│   │   ├── Admin/
│   │   │   ├── SenderPoolController.php        # Manage sender numbers
│   │   │   ├── VerifiedMembersController.php   # View verified members
│   │   │   └── WhatsAppGatewayController.php   # Gateway health + Nginx auth
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
│   ├── 0001_wa_users.php               # Adds columns to users table
│   ├── 0002_wa_otp.php                 # Creates OTP tables
│   └── 0003_wa_senders.php             # Creates sender pool table
├── nginx/
│   └── gateway-reverse-proxy.conf      # For embedded gateway console
├── routes/
│   └── whatsapp.php                    # All WhatsApp API routes
├── ui/                                 # React admin panels (optional)
│   ├── components/
│   │   ├── SenderPoolPanel.tsx
│   │   ├── VerifiedMembersPanel.tsx
│   │   ├── WhatsAppOtpForm.tsx
│   │   └── OtpInput.tsx
│   └── openwa/                         # Embedded gateway micro-frontend
└── README.md                           # This file
```

---

## 9 Admin Menu Items

When you integrate the UI panels into your admin dashboard, you get 9 WhatsApp management menus:

| # | Menu Label | Type | Component |
|---|-----------|------|-----------|
| 1 | Sesi & Nomor Pengirim WA | Native Panel | `<SenderPoolPanel />` |
| 2 | Member Terverifikasi | Native Panel | `<VerifiedMembersPanel />` |
| 3 | Dasbor | Embedded | Gateway micro-frontend |
| 4 | Obrolan | Embedded | Gateway micro-frontend |
| 5 | Webhook | Embedded | Gateway micro-frontend |
| 6 | Templat | Embedded | Gateway micro-frontend |
| 7 | Penguji Pesan | Embedded | Gateway micro-frontend |
| 8 | Plugin | Embedded | Gateway micro-frontend |
| 9 | Log | Embedded | Gateway micro-frontend |

Menus 1-2 are standalone React panels that call your Laravel API directly.  
Menus 3-9 are an embedded micro-frontend proxied through Nginx (see `nginx/gateway-reverse-proxy.conf`).

---

## Nginx Setup (For Menus 3-9 Only)

If you want the embedded gateway console (menus 3-9), add this to your Nginx config:

```nginx
include /path/to/laravel/nginx/gateway-reverse-proxy.conf;
```

This proxies `/gateway-api/*` and `/gateway-socket/*` to the Docker container, with admin authentication enforced by Laravel.
