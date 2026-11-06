# Protected, Queued WhatsApp Member Login

Member codes remain **4 numeric digits**, including leading zeros, with automatic verification after the fourth digit. The code lasts **300 seconds from worker generation**. Resend becomes available **30 seconds after request acceptance**. Sender QR/pairing codes are separate and unchanged.

## Request and Delivery Flow

1. The customer enters a phone number and clicks **Request OTP**. Only then does the browser load and execute Turnstile. Resend also requires a fresh challenge.
2. Laravel verifies the token server-side, including hostname and action (`otp_request` or `otp_resend`). Missing, invalid, expired, or reused tokens are rejected. Provider outages fail closed. An exact retry with the same session, action, identity, and nonempty idempotency key can reuse the verified result for up to 300 seconds.
3. Admission checks the cooldown, phone/IP/tenant/system limits, and live pending queue capacity. Accepted requests return HTTP **202** with `delivery_status=queued`; this does not mean the message was sent.
4. Redis carries only the challenge ID. A worker creates the code in memory, saves its peppered HMAC verifier, and sends through an available sender. A busy sender defers the job. A queued job older than 30 seconds is cancelled before sending.
5. The browser polls every three seconds while queued/sending. `submitted` means gateway acceptance, not confirmed handset delivery. `unknown` means the outcome could not be confirmed; no automatic resend occurs because it could duplicate a message. Explicit resend invalidates the previous challenge.
6. Verification consumes the challenge once. Account creation occurs only after successful verification. The browser stores only the challenge ID in session storage; restoration requires the original authenticated browser session cookie (a guest session is sufficient).

## Host Configuration

Install the package via Composer:

```bash
composer require billi-panca-wijaya/laravel-whatsapp-gateway
```

Set these values in the **host Laravel environment** (`.env`):

```dotenv
WA_OTP_TRANSPORT=openwa
WA_OTP_HMAC_SECRET=<independent-random-secret>
WA_OTP_TURNSTILE_ENABLED=true
WA_OTP_TURNSTILE_SITE_KEY=<public-site-key>
WA_OTP_TURNSTILE_SECRET_KEY=<server-secret-key>
WA_OTP_TURNSTILE_HOSTNAMES=login.example.com
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_QUEUE_RETRY_AFTER=90
WA_OTP_QUEUE_ENABLED=true
WA_OTP_QUEUE_CONNECTION=redis
WA_OTP_QUEUE_NAME=whatsapp-otp
WA_OTP_QUEUE_MAX_WAIT_SECONDS=30
WA_OTP_QUEUE_MAX_PENDING=1000
WA_OTP_SENDER_LIMITS_ENABLED=true
WA_OTP_SENDER_MIN_INTERVAL_SECONDS=3
```

Start a supervised worker, for example using `deploy/whatsapp-otp-worker.conf`:

```bash
php artisan config:cache
php artisan queue:work redis --queue=whatsapp-otp --sleep=1 --tries=20 --timeout=60 --max-time=3600

# After an application update, gracefully restart existing workers:
php artisan queue:restart
```
