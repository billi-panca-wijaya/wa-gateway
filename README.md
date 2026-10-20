# WhatsApp Gateway & Back-Office Integration Engine

<p align="center">
  <strong>Lightweight WhatsApp Gateway Infrastructure with Multi-Sender Pool, Auto-Failover, and Zero-Config Laravel Admin Integration</strong>
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

## 📌 Project Overview

This repository provides an enterprise-ready **WhatsApp Gateway Engine & Laravel Integration Package** specifically optimized for **Back-Office operations and transactional workflows** (Member Authentication, 4-Digit OTP, Order Notifications, and Customer Portals).

Unlike bloated monolithic stacks that require compiling JavaScript or maintaining heavy build tools, this project has been streamlined into two clean components:
1. **Zero-Config Docker Engine** (`docker-compose.yml`): A single, lightweight container running the headless WhatsApp socket engine (Baileys) via prebuilt image. No local Node.js compilation required.
2. **Native Laravel Package** (`reference-laravel-client/`): Complete, drop-in Laravel services, controllers, migrations, and routes. No extra `.env` API tokens or external URLs needed.

---

## 🏗️ 1. Architecture & Entity Ecosystem

Understanding how the platform engine, operators, and end-users interact:

```mermaid
flowchart TD
    classDef platform fill:#0f172a,stroke:#3b82f6,stroke-width:2px,color:#fff;
    classDef operator fill:#1e293b,stroke:#10b981,stroke-width:2px,color:#fff;
    classDef gateway fill:#18181b,stroke:#25d366,stroke-width:2px,color:#fff;
    classDef enduser fill:#064e3b,stroke:#34d399,stroke-width:2px,color:#fff;

    subgraph PLATFORM_LAYER ["1. LARAVEL CORE BACKEND (PHP)"]
        direction TB
        B1["WhatsApp OTP Service<br/>(4-Digit OTP, HMAC-SHA256, Auto-Restore)"]:::platform
        B2["Multi-Sender Pool Manager<br/>(Load Balancing & Failover Engine)"]:::platform
        B3["Webhook & Event Dispatcher<br/>(member.verified Notification)"]:::platform
    end

    subgraph OPERATOR_LAYER ["2. OPERATOR & STOREFRONT (PORTAL APPS)"]
        direction LR
        O1["Back-Office Admin Panel<br/>(Operator Manages Senders & QR)"]:::operator
        O2["End-User Storefront<br/>(Customer Signs In via WA OTP)"]:::operator
    end

    subgraph GATEWAY_LAYER ["3. WHATSAPP GATEWAY ENGINE (DOCKER)"]
        direction TB
        G1["Gateway REST API & Socket Controller<br/>(http://openwa:2785)"]:::gateway
        subgraph ACTIVE_POOL ["Connected Sender Sockets"]
            S1["Sender 1: Primary WhatsApp Number"]:::gateway
            S2["Sender 2: Standby Backup Number (Failover)"]:::gateway
        end
    end

    subgraph WA_NETWORK ["4. OFFICIAL WHATSAPP NETWORK"]
        W1["WhatsApp Cloud Servers & Customer Mobile Phone"]:::enduser
    end

    %% Flow interactions
    O2 -->|"1. Request OTP / Sign In"| B1
    O1 -->|"Pair Numbers & Monitor Status"| B2
    B1 -->|"2. Select Available Sender"| B2
    B2 -->|"3. Dispatch Message via HTTP"| G1
    G1 -->|"Socket Route"| S1 & S2
    S1 & S2 -->|"4. Deliver Message to Handset"| W1
    W1 -.->|"5. Customer Receives 4-Digit Code"| O2
    O2 -->|"6. Verify OTP & Establish Session"| B1
    B1 -.->|"7. Trigger Verified Member Event"| B3
```

---

## 🔄 2. Operational Workflows (Step-by-Step)

### A. 4-Digit OTP Login & Verification Flow
How customers sign in swiftly without remembering complex passwords:

```mermaid
sequenceDiagram
    autonumber
    actor Customer as 📱 Customer
    participant Web as 🛒 Storefront Web
    participant Laravel as ⚙️ Laravel Backend
    participant Gateway as 🚀 WhatsApp Gateway
    participant WA as 💬 WhatsApp Server

    Customer->>Web: Enter Phone Number (e.g. 08123456789)
    Web->>Laravel: POST /api/v1/auth/whatsapp/request-otp
    Laravel->>Laravel: Generate 4-digit code & create stateless HMAC verifier
    Laravel->>Gateway: Send message using least-recently-used sender
    Gateway->>WA: Forward text via active Baileys socket
    WA->>Customer: Message arrives: "Your login code: 5821"
    Customer->>Web: Type 4 digits on screen
    Web->>Laravel: POST /api/v1/auth/whatsapp/verify-otp
    alt Soft-deleted or New User
        Laravel->>Laravel: Automatically restore & activate account
    end
    Laravel->>Web: Success status (Sanctum Auth Cookie & Session)
    Web->>Customer: Redirect to Customer Dashboard
```

---

### B. Multi-Sender Auto-Failover Flow (Anti-Downtime)
How transactions are protected when a WhatsApp number encounters issues:

```mermaid
sequenceDiagram
    autonumber
    participant Engine as ⚙️ Sender Pool Engine
    participant S1 as 📵 Sender 1 (Primary)
    participant S2 as 📱 Sender 2 (Backup)
    participant Customer as 👤 Customer Mobile

    Engine->>S1: Attempt to deliver OTP message
    alt Primary Sender Normal
        S1->>Customer: Message delivered successfully
    else Sender 1 Disconnected / Rate-Limited / Blocked
        S1-->>Engine: Delivery failed error
        Engine->>Engine: Temporarily place Sender 1 in Cooldown
        Engine->>S2: AUTO-FAILOVER: Immediately reroute to Sender 2
        S2->>Customer: OTP delivered successfully without customer error!
    end
```

---

### C. WhatsApp Pairing Flow with Auto-Dismiss Modal
Effortless phone connection for operators without manual refresh:

```mermaid
sequenceDiagram
    autonumber
    actor Admin as 👨‍💼 Back-Office Admin
    participant Modal as 🖥️ Pairing Modal UI
    participant Backend as ⚙️ Laravel Admin API
    participant OpenWA as 🚀 OpenWA Engine
    actor Phone as 📱 Operator Phone

    Admin->>Modal: Open "Connect WhatsApp" Modal
    Modal->>OpenWA: Request QR Code or 8-Digit Pairing Code
    OpenWA-->>Modal: Display QR Code / 8-Digit Code
    Admin->>Phone: Scan QR with WhatsApp or input Pairing Code
    loop Realtime Status Polling (Every 2.5s)
        Modal->>Backend: Check connection state & sync phone number
        Backend->>OpenWA: Query session state
        OpenWA-->>Backend: Status changes to "ready / working"
        Backend-->>Modal: Session verified!
    end
    Modal->>Modal: Automatically dismiss popup modal
    Modal->>Admin: Show green "WhatsApp Connected" banner
```

---

## ⚡ 3. Key Features & Capabilities

### 1. 🔄 Multi-Sender Pool & Failover
* **Round-Robin Load Balancing**: Balances traffic across all active senders based on `last_used_at` to prevent account flagging.
* **Intelligent Auto-Failover**: If a sender fails, the dispatch pipeline automatically fails over to the next standby sender in the pool without dropping the user's request.
* **Self-Healing Cooldown**: Senders with transient errors are suspended for a cooldown window and automatically reactivated.

### 2. 🔢 4-Digit Frictionless OTP
* **Format**: 4 random numeric digits (`0000`–`9999`) optimized for mobile input.
* **Zero-Leak Security**: Code is verified via an ephemeral HMAC-SHA256 signature; raw OTPs are never stored in plaintext in the database.
* **Auto-Restore Soft-Deleted Accounts**: Automatically restores soft-deleted test or inactive accounts upon successful phone verification.
* **Smart Rate Limiting**: 30-second resend cooldown with server-side enforcement.

### 3. 📲 Dual Pairing Options
* **QR Code Scan**: Standard scan flow identical to WhatsApp Web.
* **8-Digit Pairing Code**: Link using phone number notifications without needing a camera.
* **Auto-Dismiss UI**: The pairing modal automatically detects connected status and closes itself.

---

## 📂 4. Clean Directory Structure

```
wa-gateway/
├── docker-compose.yml            # 🐳 Zero-config Docker stack to run WhatsApp Engine
├── reference-laravel-client/     # 🌟 Complete Laravel Integration Package
│   ├── config/
│   │   └── whatsapp_otp.php      # Laravel configuration
│   ├── database/migrations/      # Database migrations (sender_pool & otp_challenges)
│   ├── src/
│   │   ├── Controllers/          # WhatsAppOtpController, SenderPoolController, etc.
│   │   └── Services/WhatsAppOtp/ # OtpService, SenderPoolService, PooledTransport, etc.
│   └── README.md                 # Laravel installation guide
├── README.md                     # Main documentation (English)
├── README.id.md                  # Main documentation (Bahasa Indonesia)
└── LICENSE                       # MIT License
```

---

## 💻 5. Native Laravel Integration (Zero-Config / No .env Tokens)

The WhatsApp Gateway is designed for **Zero-Config Native Integration** directly within your Laravel backend. You **DO NOT NEED** extra `.env` tokens, manual API keys, or external gateway URLs.

### 1. Copy Package Files to Laravel
```bash
# 1. Copy Config
cp reference-laravel-client/config/whatsapp_otp.php config/

# 2. Copy Database Migrations
cp reference-laravel-client/database/migrations/* database/migrations/

# 3. Copy Services
cp -r reference-laravel-client/src/Services/WhatsAppOtp app/Services/

# 4. Copy Controllers
cp reference-laravel-client/src/Controllers/WhatsAppOtpController.php app/Http/Controllers/Api/
cp reference-laravel-client/src/Controllers/SenderPoolController.php app/Http/Controllers/Api/Admin/
cp reference-laravel-client/src/Controllers/VerifiedMembersController.php app/Http/Controllers/Api/Admin/
cp reference-laravel-client/src/Controllers/WhatsAppMemberWebhookController.php app/Http/Controllers/Api/
```

### 2. Run Database Migrations
```bash
php artisan migrate
```

### 3. Register Routes in `routes/api.php`
```php
use App\Http\Controllers\Api\WhatsAppOtpController;
use App\Http\Controllers\Api\Admin\SenderPoolController;
use App\Http\Controllers\Api\Admin\VerifiedMembersController;
use App\Http\Controllers\Api\WhatsAppMemberWebhookController;

// 1. Customer OTP Endpoints
Route::prefix('auth/whatsapp')->middleware('throttle:60,1')->group(function () {
    Route::post('request-otp', [WhatsAppOtpController::class, 'requestOtp']);
    Route::post('verify-otp', [WhatsAppOtpController::class, 'verifyOtp']);
    Route::post('resend-otp', [WhatsAppOtpController::class, 'resendOtp']);
});

// 2. Admin Back-Office Senders Management
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

// 3. Verified Members Directory
Route::prefix('admin/verified-members')
    ->middleware(['auth:sanctum', 'role:super-admin|admin'])
    ->controller(VerifiedMembersController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::get('{id}', 'show');
    });

// 4. Realtime Webhook Listener for Verified Members
Route::post('webhooks/whatsapp/member-verified', WhatsAppMemberWebhookController::class)
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
```

---

## 🐳 6. Running the WhatsApp Engine (Docker)

To launch the background WhatsApp Baileys socket engine, simply run:

```bash
docker compose up -d
```

* That's it! No `.env` setup or Node.js compilation required.
* The container will automatically start and expose the private WhatsApp socket interface at `http://localhost:18091` (and `http://openwa:2785` in the Docker bridge network).

---

## 🔒 7. Security & Data Protection

1. **Encrypted Credentials**: API keys stored in the sender pool table are encrypted using AES-256 (`Crypt::encryptString`) and never returned in plaintext API responses.
2. **Stateless HMAC Verifiers**: OTP codes are verified through one-time HMAC-SHA256 digests and are never stored in plaintext database fields.
3. **Internal Network Isolation**: The engine communicates locally inside the Docker bridge network (`http://openwa:2785`), inaccessible from the public internet.

---

## 👤 Author & License

Developed and refactored by:
* **Billi Panca Wijaya** ([GitHub: @billi-panca-wijaya](https://github.com/billi-panca-wijaya))

Licensed under the [MIT License](LICENSE).
