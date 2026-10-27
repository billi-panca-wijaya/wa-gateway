# Protected, queued WhatsApp member login

Member codes remain **4 numeric digits**, including leading zeros, with automatic verification after the fourth digit. The code lasts **300 seconds from worker generation**. Resend becomes available **30 seconds after request acceptance**. Sender QR/pairing codes are separate and unchanged.

## Request and delivery flow

1. The customer enters a phone number and clicks **Request OTP**. Only then does the browser load and execute Turnstile. Resend also requires a fresh challenge.
2. Laravel verifies the token server-side, including hostname and action (`otp_request` or `otp_resend`). Missing, invalid, expired, or reused tokens are rejected. Provider outages fail closed. An exact retry with the same session, action, identity, and nonempty idempotency key can reuse the verified result for up to 300 seconds.
3. Admission checks the cooldown, phone/IP/tenant/system limits, and live pending queue capacity. Accepted requests return HTTP **202** with `delivery_status=queued`; this does not mean the message was sent.
4. Redis carries only the challenge ID. A worker creates the code in memory, saves its peppered HMAC verifier, and sends through an available sender. A busy sender defers the job. A queued job older than 30 seconds is cancelled before sending.
5. The browser polls every three seconds while queued/sending. `submitted` means gateway acceptance, not confirmed handset delivery. `unknown` means the outcome could not be confirmed; no automatic resend occurs because it could duplicate a message. Explicit resend invalidates the previous challenge.
6. Verification consumes the challenge once. Account creation occurs only after successful verification. The browser stores only the challenge ID in session storage; restoration requires the original authenticated browser session cookie (a guest session is sufficient).

## Host configuration

Install the new `app/Jobs/WhatsAppSendOtp.php`, bot-verification service, frontend hook, and `routes/whatsapp_otp_delivery.php`, alongside the updated add-on files. Review diffs on upgrades. Do not overwrite Laravel's default User, Controller, or route files. Register the existing add-on routes once under `/api`; the provider separately loads the new delivery/config routes with their own `/api` prefix. Sanctum stateful sessions are required.

Set these values in the **host Laravel environment**, never in frontend secrets or the gateway engine environment:

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

Configure Redis connectivity and existing OpenWA session/API keys separately. Use HTTPS and secure session cookies. Allow the Turnstile script/frame origin in the host CSP. The hostname list is comma-separated and contains hostnames without scheme/port. Turnstile is enabled by default and requires real keys before live login can work. Disabling Turnstile is accepted only in local/testing. Production also requires Redis cache and the asynchronous queue. All API instances/workers must share cache, database, configuration, encryption keys, and cache prefix. Independent host deployments need coordinated sender capacity or distinct sender sessions; cache locks do not span different Redis namespaces.

Start a supervised worker, for example using [the Supervisor template](deploy/whatsapp-otp-worker.conf):

```bash
php artisan config:cache
php artisan queue:work redis --queue=whatsapp-otp --sleep=1 --tries=20 --timeout=60 --max-time=3600
# After an application update, gracefully restart existing workers:
php artisan queue:restart
```

The queue `retry_after` must exceed the 60-second job timeout. The example uses 90 seconds. Use Linux workers with PCNTL for timeout enforcement. A process manager must restart exited workers. The repository root Docker Compose starts the gateway engine only; it does not start Laravel, Redis, or these workers.

## Capacity and failure behavior

Defaults per 900-second window are 5 accepted requests per phone/tenant, 20 per IP, 500 per tenant, and 2,000 system-wide. Accepted requests consume limits even if later delivery fails. The maximum live pending queue is 1,000. These are configurable operating limits, not throughput promises. Tune them using measured capacity and shared-IP usage.

Each physical sender session allows one in-flight send with at least three seconds between send starts. Sender locks and intervals use the shared Redis cache. More workers do not bypass these limits. Pool failover is limited to definite failures; uncertain delivery is never automatically retried. No sender count or delay guarantees that an unofficial WhatsApp session will avoid restrictions or bans.

A missing worker causes queued requests to expire; a polling customer sees failure and can request again after cooldown. If a worker dies during sending, polling reports `unknown` after 65 seconds and the job does not send again. If a gateway request was already in flight, cancellation cannot recall that message. Monitor queue age, failed jobs, unknown/failed deliveries, sender health, and HTTP 429/503 responses. Conduct a staging load test with real Redis and fake outbound delivery before increasing capacity.

The database stores HMAC verifiers, and queue/failed-job payloads contain no plaintext OTP. The local/testing mock outbox intentionally stores messages; gateway-side message history has its own retention policy. Do not log OTP request bodies or challenge tokens through host observability tools.

## API additions

- `GET /api/v1/auth/whatsapp/challenge-config`: public site key/enabled state only; never returns the secret.
- `GET /api/v1/auth/whatsapp/challenges/{id}`: delivery status, masked number, and remaining timers; restricted to the requesting session and tenant.
- Existing request/resend POSTs now accept `challenge_token`. Send a unique `Idempotency-Key` per logical request and preserve it for uncertain HTTP retries.

Errors retain the entered phone number. Cooldown/rate-limit responses expose a retry timer. Network/challenge failures allow a manual retry. A restored request cannot be accessed from another browser session. See [regression test instructions](tests/README.md).
