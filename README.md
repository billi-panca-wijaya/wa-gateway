# WhatsApp Gateway — Add-on for Laravel

[English](README.md) | [Bahasa Indonesia](README.id.md)

Drop-in WhatsApp Login & multi-sender OTP add-on for **existing** Laravel websites.  
Your Laravel app stays untouched — you only **add** new files, never overwrite existing ones.

---

## What This Does

| Feature | Description |
|---------|-------------|
| **WhatsApp OTP Login** | Customers log in by entering their phone number and receiving a 4-digit OTP via WhatsApp |
| **Multi-Sender Pool** | Multiple WhatsApp sender numbers with round-robin rotation and auto-failover |
| **9-Menu Admin Panel** | Full Back-Office management console added to your existing admin dashboard |
| **Zero-Config Gateway** | WhatsApp engine runs as a Docker container — no API keys or tokens needed |

---

## How It Works (Simple)

```
Customer types phone number
        |
        v
Your Laravel app sends OTP via WhatsApp
(auto-rotates between multiple sender numbers)
        |
        v
Customer enters the 4-digit code
        |
        v
Verified → Logged in automatically
```

---

## Quick Start

### 1. Start the WhatsApp Engine

```bash
docker compose up -d
```

### 2. Copy the Add-on Files to Your Laravel Project

```bash
# Copy backend files (new files only — nothing is overwritten)
cp -r laravel/app/Http/Controllers/WhatsApp/    your-laravel/app/Http/Controllers/WhatsApp/
cp -r laravel/app/Models/Wa*                    your-laravel/app/Models/
cp -r laravel/app/Models/Otp*                   your-laravel/app/Models/
cp -r laravel/app/Models/SenderPool.php         your-laravel/app/Models/
cp -r laravel/app/Services/WhatsAppOtp/         your-laravel/app/Services/WhatsAppOtp/
cp -r laravel/app/Providers/WhatsAppOtpServiceProvider.php your-laravel/app/Providers/
cp    laravel/config/whatsapp_otp.php           your-laravel/config/
cp    laravel/database/migrations/*             your-laravel/database/migrations/
cp    laravel/routes/whatsapp.php               your-laravel/routes/
```

### 3. Register & Migrate

```php
// bootstrap/providers.php (Laravel 11) or config/app.php
App\Providers\WhatsAppOtpServiceProvider::class,
```

```php
// routes/api.php — add this line
require __DIR__ . '/whatsapp.php';
```

```bash
php artisan migrate
```

**Done.** Your existing Laravel website now has WhatsApp login.

---

## Repository Structure

```
wa-gateway/
├── docker-compose.yml          # WhatsApp engine (just run: docker compose up -d)
├── laravel/                    # Add-on files to copy into your Laravel project
│   ├── app/                    # Controllers, Models, Services, Providers
│   ├── config/                 # whatsapp_otp.php
│   ├── database/migrations/    # 3 short migration files
│   │   ├── 0001_wa_users.php   # Adds phone columns to your users table
│   │   ├── 0002_wa_otp.php     # Creates otp_challenges & otp_deliveries tables
│   │   └── 0003_wa_senders.php # Creates sender_pool table
│   ├── nginx/                  # Reverse proxy config (for embedded gateway console)
│   ├── routes/whatsapp.php     # All API routes — just require() in your api.php
│   └── ui/                     # React admin panels (optional)
├── README.md                   # This file
└── README.id.md                # Bahasa Indonesia version
```

---

## What Files Get Added to Your Laravel Project

> **Important**: No existing file is overwritten. Everything below is a NEW file.

| Your Laravel Project | What Gets Added | Purpose |
|---------------------|----------------|---------|
| `app/Http/Controllers/WhatsApp/` | New folder with 4 controllers | OTP, SenderPool, VerifiedMembers, Gateway |
| `app/Models/` | 3 new model files | OtpChallenge, OtpDelivery, SenderPool |
| `app/Services/WhatsAppOtp/` | New folder with service classes | OTP logic, sender pool rotation, phone normalization |
| `app/Providers/` | 1 new provider file | WhatsAppOtpServiceProvider |
| `config/` | 1 new config file | whatsapp_otp.php |
| `database/migrations/` | 3 new migration files | New tables + columns on users table |
| `routes/` | 1 new route file | whatsapp.php |

---

## Detailed Guide

For the complete step-by-step guide including the 9-item admin menu setup, Nginx reverse proxy configuration, and React UI panel integration, see the [**Laravel Add-on Guide**](laravel/README.md).

---

## License

[MIT License](LICENSE)
