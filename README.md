# WhatsApp Gateway & Laravel Integration

[English](README.md) | [Bahasa Indonesia](README.id.md)

Zero-configuration WhatsApp API Gateway and production-grade Laravel integration package. Designed for high availability, multi-sender pool rotation, automated failover, and full Back-Office management.

---

## Highlights

- **Zero-Config Gateway**: Runs out of the box with Docker. No manual environment variables or token configuration required.
- **Dedicated Laravel Package**: Complete source code in [`laravel/`](laravel/) including Controllers, Models, Services, Migrations, Routes, and React UI components.
- **9-Item Back-Office Sidebar Menu**:
  1. **Sesi & Nomor Pengirim WA** (Multi-sender pool & session status)
  2. **Member Terverifikasi** (Customer phone verification management)
  3. **Dasbor** (Real-time gateway health & throughput)
  4. **Obrolan** (Interactive live WhatsApp chat console)
  5. **Webhook** (Webhook event subscriptions & retry monitor)
  6. **Templat** (Message templates & snippets)
  7. **Penguji Pesan** (Interactive message dispatch test console)
  8. **Plugin** (Add-on plugins & integrations)
  9. **Log** (Network transmission & gateway system logs)
- **Multi-Sender Pool & Auto-Failover**: Round-robin message delivery across multiple WhatsApp numbers with automatic failover.
- **Secure Reverse Proxy**: Embedded gateway access protected by Laravel admin session via Nginx subrequest authorization.

---

## Repository Structure

```
wa-gateway/
├── docker-compose.yml              # Zero-configuration WhatsApp Gateway container
├── laravel/                        # Complete Laravel Integration Package
│   ├── app/                        # Controllers, Models, Providers, Services
│   ├── config/                     # whatsapp_otp.php configuration
│   ├── database/migrations/        # Database schema for sender pool & OTP
│   ├── nginx/                      # Nginx reverse proxy subrequest auth configuration
│   ├── routes/                     # Ready-to-use API & Admin route definitions
│   ├── ui/                         # React panels, micro-frontend & 9-menu example
│   ├── README.md                   # Detailed Laravel installation guide (English)
│   └── README.id.md                # Detailed Laravel installation guide (Indonesian)
├── README.md                       # This file (English)
└── README.id.md                    # This file (Indonesian)
```

---

## Quick Start: Starting the Gateway

Run the container using Docker Compose:

```bash
docker compose up -d
```

The gateway will start on:
- Healthcheck Endpoint: `http://127.0.0.1:18091/api/health/ready`
- Internal Gateway Port: `2785`

---

## Integrating with Laravel

All integration code, models, controllers, and UI panels are located in the [`laravel/`](laravel/) directory.

To install:
1. Copy `laravel/app/`, `laravel/config/`, and `laravel/database/` into your Laravel project.
2. Run `php artisan migrate`.
3. Include `routes/whatsapp.php` in your Laravel `routes/api.php`.
4. Embed the UI panels into your Back-Office dashboard.

For complete, detailed instructions on configuring the 9-item admin sidebar menu and Nginx reverse proxy, read the [Laravel Integration Guide](laravel/README.md).
