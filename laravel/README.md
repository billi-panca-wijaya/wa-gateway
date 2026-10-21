# Laravel WhatsApp Gateway & Multi-Sender OTP Package

[English](README.md) | [Bahasa Indonesia](README.id.md)

Production-ready Laravel integration package for WhatsApp Gateway, featuring multi-sender pool rotation, automated failover, customer phone OTP authentication, and a complete 9-menu Back-Office administration console.

---

## Architecture Overview

This package connects your Laravel application directly to a local or Docker-hosted WhatsApp Gateway instance. 

```
+-----------------------------------------------------------------------------------+
|                              Laravel Back-Office UI                               |
|                                                                                   |
|  [WhatsApp Gateway Sidebar Menu - 9 Items]                                        |
|  1. Sesi & Nomor Pengirim WA   -> Native Panel (SenderPoolPanel.tsx)             |
|  2. Member Terverifikasi        -> Native Panel (VerifiedMembersPanel.tsx)        |
|  3. Dasbor                     -> Embedded Micro-Frontend (OpenWaApp.tsx)         |
|  4. Obrolan                    -> Embedded Micro-Frontend (OpenWaApp.tsx)         |
|  5. Webhook                    -> Embedded Micro-Frontend (OpenWaApp.tsx)         |
|  6. Templat                    -> Embedded Micro-Frontend (OpenWaApp.tsx)         |
|  7. Penguji Pesan              -> Embedded Micro-Frontend (OpenWaApp.tsx)         |
|  8. Plugin                     -> Embedded Micro-Frontend (OpenWaApp.tsx)         |
|  9. Log                        -> Embedded Micro-Frontend (OpenWaApp.tsx)         |
+----------------------------------------+------------------------------------------+
                                         |
     +-----------------------------------+-----------------------------------+
     | Authenticated Admin API Calls         | Reverse-Proxied & Auth-Guarded
     v                                       v
+------------------------------------+   +------------------------------------+
| Laravel API                        |   | Nginx Reverse Proxy                |
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
                        | (Docker Container :2785)  |
                        +---------------------------+
```

---

## Package Directory Structure

```
laravel/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       ├── WhatsAppOtpController.php           # Customer OTP request & verification
│   │       ├── Admin/
│   │       │   ├── SenderPoolController.php        # Sender pool & session management
│   │       │   ├── VerifiedMembersController.php   # Verified member queries & audit
│   │       │   └── WhatsAppGatewayController.php   # Gateway health & Nginx auth subrequest
│   │       └── BackOffice/
│   │           └── WhatsAppMemberWebhookController.php # Webhook listener for verified members
│   ├── Models/
│   │   ├── SenderPool.php                         # WhatsApp sender session credentials & health
│   │   ├── OtpChallenge.php                       # Rate-limiting, HMAC verifier, expiry state
│   │   └── OtpDelivery.php                        # Delivery attempt audits & session tracking
│   ├── Providers/
│   │   └── WhatsAppOtpServiceProvider.php         # Service bindings & pool injection
│   └── Services/
│       └── WhatsAppOtp/
│           ├── OpenWaSessionClient.php            # Direct communication with Gateway API
│           ├── OtpService.php                     # Complete OTP generation, hashing & verify
│           ├── SenderPoolService.php              # Multi-sender rotation & automatic failover
│           ├── PhoneNumber.php                    # E.164 normalization & validation
│           ├── TenantResolver.php                 # Multi-tenant host resolution
│           └── Transport/                         # PooledTransport, OpenWaTransport, Mock
├── config/
│   └── whatsapp_otp.php                           # Complete OTP and Gateway configuration
├── database/
│   └── migrations/
│       ├── 2026_10_08_000001_add_whatsapp_login_to_users_table.php
│       ├── 2026_10_08_000002_create_otp_challenges_tables.php
│       ├── 2026_10_14_000001_create_sender_pool_table.php
│       └── 2026_10_15_000001_make_api_key_nullable_in_sender_pool_table.php
├── nginx/
│   └── gateway-reverse-proxy.conf                 # Secure subrequest proxy directives
├── routes/
│   └── whatsapp.php                               # All API and Admin routes ready to import
└── ui/
    ├── components/
    │   ├── SenderPoolPanel.tsx                    # Menu 1: Sesi & Nomor Pengirim WA
    │   ├── VerifiedMembersPanel.tsx               # Menu 2: Member Terverifikasi
    │   ├── WhatsAppGatewayPanel.tsx               # Standalone multi-sender wrapper
    │   ├── WhatsAppOtpForm.tsx                    # Customer login form with OTP
    │   └── OtpInput.tsx                           # 4-6 digit numeric OTP pin input
    ├── openwa/                                    # Embedded micro-frontend (Menus 3 - 9)
    │   ├── OpenWaApp.tsx                          # Root micro-frontend router
    │   ├── pages.ts                               # Route definitions for menus 3 - 9
    │   └── pages/                                 # Dasbor, Chats, Webhooks, Templates, etc.
    └── examples/
        └── AdminDashboardSidebarExample.tsx       # Complete 9-item back-office example
```

---

## Step-by-Step Installation into Laravel

### 1. Copy Backend Files
Copy the directories to your Laravel application:
- `app/` -> `app/`
- `config/whatsapp_otp.php` -> `config/whatsapp_otp.php`
- `database/migrations/*` -> `database/migrations/`
- `routes/whatsapp.php` -> `routes/whatsapp.php`

### 2. Register Service Provider
In Laravel 11 / 10, register the service provider in `bootstrap/providers.php` (or `config/app.php`):

```php
return [
    // ...
    App\Providers\WhatsAppOtpServiceProvider::class,
];
```

### 3. Run Migrations
Execute the database schema updates:
```bash
php artisan migrate
```

This will create:
- `sender_pool`: Stores WhatsApp session identifiers, sender phone numbers, session statuses, and failover counters.
- `otp_challenges` & `otp_deliveries`: Stores hashed verification tokens, expiry timestamps, and delivery logs.
- Adds `phone_e164`, `phone_verified_at`, and `tenant_key` columns to your `users` table.

### 4. Register Routes
In your Laravel `routes/api.php`, add:
```php
require __DIR__ . '/whatsapp.php';
```

---

## The 9 Back-Office Menu Items Configuration

To display the complete 9-item menu under **WhatsApp Gateway** in your admin sidebar:

| # | Menu Item Label (Indonesian) | English Function | Component / Implementation | Target Endpoint / URL |
|---|-----------------------------|------------------|---------------------------|-----------------------|
| 1 | **Sesi & Nomor Pengirim WA** | WhatsApp Senders & Sessions | `<SenderPoolPanel />` (Native React) | `/api/v1/admin/sender-pool` |
| 2 | **Member Terverifikasi** | Verified Members | `<VerifiedMembersPanel />` (Native React) | `/api/v1/admin/verified-members` |
| 3 | **Dasbor** | Gateway Dashboard | `<OpenWaApp />` (Embedded) | `/dashboard/gateway` |
| 4 | **Obrolan** | Live Chat Console | `<OpenWaApp />` (Embedded) | `/dashboard/gateway/chats` |
| 5 | **Webhook** | Webhook Manager | `<OpenWaApp />` (Embedded) | `/dashboard/gateway/webhooks` |
| 6 | **Templat** | Message Templates | `<OpenWaApp />` (Embedded) | `/dashboard/gateway/templates` |
| 7 | **Penguji Pesan** | Message Tester | `<OpenWaApp />` (Embedded) | `/dashboard/gateway/message-tester` |
| 8 | **Plugin** | Plugins & Add-ons | `<OpenWaApp />` (Embedded) | `/dashboard/gateway/plugins` |
| 9 | **Log** | System & Network Logs | `<OpenWaApp />` (Embedded) | `/dashboard/gateway/logs` |

### How It Works:
1. **Menus 1 & 2** are dedicated native panels rendered directly inside your Laravel React Admin theme. They interact with `SenderPoolController` and `VerifiedMembersController` through standard Sanctum authentication.
2. **Menus 3 through 9** are powered by the lightweight embedded micro-frontend (`OpenWaApp.tsx`). When the admin clicks any of these 7 menus, the URL switches to `/dashboard/gateway/*`, seamlessly rendering the corresponding gateway management screen inside your admin layout.

---

## Nginx Subrequest Security Architecture

To prevent exposing WhatsApp Gateway credentials or exposing internal ports directly to the browser, Nginx proxies all embedded console requests via an internal authorization check:

```nginx
# In your Laravel Nginx configuration:
include /path/to/laravel/nginx/gateway-reverse-proxy.conf;
```

### Flow:
1. When the browser requests `/gateway-api/*` or `/gateway-socket/*`, Nginx intercepts the request and fires an internal subrequest to `/_gateway_auth` (`WhatsAppGatewayController::authorizeDashboard`).
2. Laravel verifies the admin's Sanctum session and role (`super-admin` or `admin`).
3. If valid, Laravel returns HTTP `204 No Content`, and Nginx forwards the request to the gateway container on `http://openwa:2785`.
4. If unauthorized, Laravel returns HTTP `403 Forbidden`, terminating the request immediately.

---

## Multi-Sender Pool & Auto-Failover Features

- **Round-Robin Rotation**: When customers request OTP codes, `SenderPoolService` rotates across all active sender numbers, spreading dispatch volume evenly.
- **Automatic Health Checks**: Detects if a WhatsApp session becomes disconnected or banned.
- **Failover on Dispatch Error**: If a sender number encounters a network failure or temporary limit, the system automatically dispatches the code using the next available sender in the pool without user disruption.
- **Auto-Suspension**: Unresponsive sessions are automatically suspended with cooldown periods to prevent repeated delivery failures.

---

## Customer WhatsApp Login Form

Include `<WhatsAppOtpForm />` on your customer-facing authentication page. It handles:
1. International / local phone number formatting (e.g. `0812...` automatically converted to `+62812...`).
2. OTP request rate limiting with countdown timer.
3. 4 to 6-digit numeric OTP pin input with automatic verification.
4. Auto-login session creation upon successful code verification.
