# WhatsApp Gateway — Non-Intrusive Add-on for Laravel

[English](README.md) | [Bahasa Indonesia](README.id.md)

Drop-in WhatsApp Login & Multi-Sender OTP add-on for **existing** Laravel applications.  
**Zero-Overwrite Guarantee**: Your existing Laravel app stays 100% intact. You only **add** new files — no default Laravel file is ever overwritten, modified, or deleted.

---

## 🛡️ Zero-Overwrite Guarantee

Every single file in this package has been explicitly named with unique prefixes (`whatsapp_`, `WhatsApp`, `0001_wa_`) and isolated into dedicated namespaces.

| Laravel Default File | What We Use Instead | Conflict Possible? |
| :--- | :--- | :--- |
| `app/Http/Controllers/Controller.php` | `app/Http/Controllers/WhatsAppOtpController.php` | ❌ **NEVER** |
| `app/Models/User.php` | `app/Models/WhatsAppOtpChallenge.php`, `WhatsAppSenderPool.php` | ❌ **NEVER** |
| `routes/api.php` & `routes/web.php` | `routes/whatsapp.php` | ❌ **NEVER** |
| `config/app.php` & `config/auth.php` | `config/whatsapp_gateway.php`, `config/whatsapp_otp.php` | ❌ **NEVER** |
| `database/migrations/*create_users*` | `database/migrations/0001_wa_users.php` (additive columns only) | ❌ **NEVER** |
| Default frontend assets | `frontend/components/WhatsAppOtpForm.tsx`, `frontend/backoffice/*` | ❌ **NEVER** |

---

## 🚀 Key Features

| Feature | Description |
| :--- | :--- |
| **WhatsApp OTP Authentication** | Customers log in with their phone number and verify with a secure 6-digit numeric OTP. |
| **Multi-Sender Device Pool** | Multiple WhatsApp sender numbers with round-robin rotation and automatic failover if a session disconnects. |
| **9-Menu Back-Office Portal** | Full administration UI: Dashboard, Chats, Webhooks, Templates, Message Tester, Infrastructure, Plugins, Logs, and Verified Members. |
| **Zero-Config Docker Engine** | Self-hosted OpenWA v0.24.0 WhatsApp engine runs in Docker with zero third-party cloud API costs. |

---

## 📁 Add-on Structure (`whatsapp-addon/`)

```text
whatsapp-addon/
├── app/
│   ├── Http/Controllers/
│   │   ├── WhatsAppOtpController.php                  # Public customer OTP endpoints
│   │   ├── Admin/WhatsAppGatewayController.php        # Gateway session operations
│   │   ├── Admin/WhatsAppSenderPoolController.php     # Multi-number pool management
│   │   ├── Admin/WhatsAppVerifiedMembersController.php# Verified members listing
│   │   └── BackOffice/WhatsAppMemberWebhookController.php # Optional external webhook receiver
│   ├── Models/
│   │   ├── WhatsAppOtpChallenge.php                   # OTP challenge lifecycle
│   │   ├── WhatsAppOtpDelivery.php                    # Delivery audit log
│   │   └── WhatsAppSenderPool.php                     # Sender device pool
│   ├── Providers/
│   │   └── WhatsAppOtpServiceProvider.php             # Service container bindings
│   └── Services/
│       ├── WhatsAppGatewayClient.php                  # Gateway HTTP client
│       └── WhatsAppOtp/                               # Business logic & HMAC verification
├── config/
│   ├── whatsapp_gateway.php                           # Gateway connection settings
│   └── whatsapp_otp.php                               # OTP length, cooldown, and pool rules
├── database/migrations/
│   ├── 0001_wa_users.php                              # Adds phone login columns to users table
│   ├── 0002_wa_otp.php                                # Creates otp_challenges & otp_deliveries
│   └── 0003_wa_senders.php                            # Creates sender_pool multi-device table
├── routes/
│   └── whatsapp.php                                   # Isolated WhatsApp route definitions
└── frontend/
    ├── components/
    │   ├── WhatsAppOtpForm.tsx                        # Customer-facing login modal/form
    │   ├── WhatsAppSenderPoolPanel.tsx                # Admin device management panel
    │   └── WhatsAppVerifiedMembersPanel.tsx           # Verified customer list table
    ├── backoffice/                                    # 9-menu embedded micro-frontend console
    └── examples/
        └── AdminDashboardSidebarExample.tsx           # Copy-paste sidebar integration example
```

---

## ⚡ Step-by-Step Installation

### Step 1: Start the WhatsApp Gateway Engine
From the repository root:
```bash
docker compose up -d
```
The gateway will start on `http://127.0.0.1:18091`.

### Step 2: Copy the Add-on Files to Your Laravel App
Copy the contents of `whatsapp-addon/` into your existing Laravel project root:
```bash
# Linux / macOS
cp -r whatsapp-addon/* /path/to/your-laravel-project/

# Windows PowerShell
Copy-Item -Path "whatsapp-addon/*" -Destination "C:\path\to\your-laravel-project" -Recurse -Force
```
*Because every filename is unique, this simply merges into your folders without overwriting any files.*

### Step 3: Run Database Migrations
```bash
php artisan migrate
```
This runs the 3 short, isolated migrations:
1. `0001_wa_users.php` (adds `phone_e164`, `phone_verified_at` to `users`)
2. `0002_wa_otp.php` (creates `otp_challenges` and `otp_deliveries`)
3. `0003_wa_senders.php` (creates `sender_pool`)

### Step 4: Include Routes & Provider
In your `routes/api.php`, append:
```php
require __DIR__ . '/whatsapp.php';
```

In your `bootstrap/providers.php` (Laravel 11+) or `config/app.php` (Laravel 10), register:
```php
App\Providers\WhatsAppOtpServiceProvider::class,
```

### Step 5: Environment Variables
Add these to your `.env` file:
```env
# WhatsApp Gateway Engine
OPENWA_BASE_URL=http://127.0.0.1:18091
OPENWA_DEFAULT_SESSION=otp-sender-1
OPENWA_API_KEY=

# OTP Cryptography & Rate Limits
WA_OTP_HMAC_SECRET=your-random-32-char-secret-key-here
WA_OTP_TRANSPORT=openwa
WA_OTP_RATE_LIMIT_PER_MINUTE=5
WA_OTP_COOLDOWN_SECONDS=60
WA_OTP_EXPIRATION_MINUTES=5
```

---

## 🖥️ Back-Office Admin Integration (9 Menus)

To include the 9 management menus in your existing React / Blade admin dashboard, refer to:
`frontend/examples/AdminDashboardSidebarExample.tsx`.

The 9 Menus:
1. **WhatsApp Sessions & Senders** (Native Panel: QR pairing & multi-device rotation)
2. **Verified Members** (Native Panel: customers verified via WhatsApp)
3. **Dashboard** (Embedded Gateway Console)
4. **Chats** (Embedded Gateway Console)
5. **Webhooks** (Embedded Gateway Console)
6. **Templates** (Embedded Gateway Console)
7. **Message Tester** (Embedded Gateway Console)
8. **Plugins** (Embedded Gateway Console)
9. **Logs** (Embedded Gateway Console)

---

## 🔒 Security Architecture
- **HMAC SHA-256**: OTP codes are never saved in plaintext. Only salt-peppered HMAC hashes are stored in the database.
- **Atomic Single-Use**: OTP verification uses atomic database updates to prevent race conditions.
- **Timing Safe**: Code comparison uses `hash_equals()` to resist timing attacks.
- **CSWSH Protected**: Reverse proxy blocks Cross-Site WebSocket Hijacking on gateway sockets.

---
*Created and maintained for seamless, non-intrusive enterprise Laravel integration.*
