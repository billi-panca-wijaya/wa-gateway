<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User Model & Table Configuration
    |--------------------------------------------------------------------------
    |
    | Defines the Eloquent model representing application users/members.
    | Defaults to your Laravel default auth provider model or App\Models\User.
    |
    */
    'user_model' => env('WA_USER_MODEL', config('auth.providers.users.model', 'App\\Models\\User')),

    /*
    |--------------------------------------------------------------------------
    | Package Routes
    |--------------------------------------------------------------------------
    |
    | Configure whether the package should automatically register its API routes,
    | and specify the URL prefix and middleware groups.
    |
    */
    'routes' => [
        'enabled' => (bool) env('WA_GATEWAY_ROUTES_ENABLED', true),
        'prefix' => env('WA_GATEWAY_ROUTES_PREFIX', 'api/v1'),
        'middleware' => ['api'],
        'admin_middleware' => ['auth:sanctum', 'role:super-admin|admin', 'throttle:60,1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Resolution
    |--------------------------------------------------------------------------
    |
    | Multi-tenant configuration. The tenant is resolved from the request host
    | via WA_OTP_TENANT_HOSTS ("host1=tenant1,host2=tenant2") and falls back
    | to the default tenant of this deployment.
    |
    */
    'default_tenant' => env('WA_OTP_DEFAULT_TENANT', 'default'),
    'tenant_hosts' => env('WA_OTP_TENANT_HOSTS', ''),

    // Region applied to numbers typed without a leading "+" (e.g. 08xx for ID).
    'default_region' => env('WA_OTP_DEFAULT_REGION', 'ID'),

    /*
    |--------------------------------------------------------------------------
    | OTP Security & Validity
    |--------------------------------------------------------------------------
    |
    | Core login OTP rules. 4-digit code length, 5 minutes lifetime, 30s resend cooldown.
    |
    */
    'code_length' => (int) env('WA_OTP_CODE_LENGTH', 4),
    'ttl_seconds' => (int) env('WA_OTP_TTL_SECONDS', 300),
    'resend_cooldown_seconds' => (int) env('WA_OTP_RESEND_COOLDOWN_SECONDS', 30),
    'max_attempts' => (int) env('WA_OTP_MAX_ATTEMPTS', 5),

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile Bot Protection
    |--------------------------------------------------------------------------
    |
    | Invisible or interactive bot protection before OTP issuance and resend.
    |
    */
    'turnstile' => [
        'enabled' => (bool) env('WA_OTP_TURNSTILE_ENABLED', true),
        'site_key' => env('WA_OTP_TURNSTILE_SITE_KEY', ''),
        'secret_key' => env('WA_OTP_TURNSTILE_SECRET_KEY', ''),
        'hostnames' => env('WA_OTP_TURNSTILE_HOSTNAMES', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Async message dispatching via Redis or database queue.
    |
    */
    'queue' => [
        'enabled' => (bool) env('WA_OTP_QUEUE_ENABLED', true),
        'connection' => env('WA_OTP_QUEUE_CONNECTION', 'redis'),
        'name' => env('WA_OTP_QUEUE_NAME', 'whatsapp-otp'),
        'max_wait_seconds' => (int) env('WA_OTP_QUEUE_MAX_WAIT_SECONDS', 30),
        'max_pending' => (int) env('WA_OTP_QUEUE_MAX_PENDING', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anti-Ban Sender Throttling
    |--------------------------------------------------------------------------
    |
    | Minimum delay between sends on the same sender number to prevent WhatsApp bans.
    |
    */
    'sender_limits' => [
        'enabled' => (bool) env('WA_OTP_SENDER_LIMITS_ENABLED', true),
        'min_interval_seconds' => (int) env('WA_OTP_SENDER_MIN_INTERVAL_SECONDS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | IP and phone number rate limits to prevent brute-force and SMS bombing.
    |
    */
    'limits' => [
        'send_per_phone' => (int) env('WA_OTP_SEND_PER_PHONE', 5),
        'send_per_ip' => (int) env('WA_OTP_SEND_PER_IP', 20),
        'send_system' => (int) env('WA_OTP_SEND_SYSTEM', 2000),
        'send_global' => (int) env('WA_OTP_SEND_GLOBAL', 500),
        'send_window_seconds' => (int) env('WA_OTP_SEND_WINDOW_SECONDS', 900),
        'verify_per_ip' => (int) env('WA_OTP_VERIFY_PER_IP', 60),
        'verify_window_seconds' => (int) env('WA_OTP_VERIFY_WINDOW_SECONDS', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | HMAC Secret & Audit Retention
    |--------------------------------------------------------------------------
    |
    | HMAC secret for verifying OTP hashes without storing plaintext in database.
    |
    */
    'hmac_secret' => env('WA_OTP_HMAC_SECRET'),
    'retention_days' => (int) env('WA_OTP_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Message Template
    |--------------------------------------------------------------------------
    |
    | Placeholder tags: :app, :code, :minutes
    |
    */
    'message_template' => env(
        'WA_OTP_MESSAGE_TEMPLATE',
        'Your :app sign-in code is :code. Expires in :minutes minutes. Do not share this code.'
    ),

    /*
    |--------------------------------------------------------------------------
    | Transport Driver
    |--------------------------------------------------------------------------
    |
    | Supported: "openwa", "mock" (mock is only permitted in local/testing).
    |
    */
    'transport' => env('WA_OTP_TRANSPORT', 'openwa'),

    /*
    |--------------------------------------------------------------------------
    | OpenWA Gateway Connection
    |--------------------------------------------------------------------------
    |
    | Connection parameters for the OpenWA container or cluster.
    |
    */
    'openwa' => [
        'base_url' => env('OPENWA_BASE_URL', 'http://127.0.0.1:18091'),
        'api_key' => env('OPENWA_API_KEY'),
        'admin_api_key' => env('OPENWA_DASHBOARD_API_KEY'),
        'session_id' => env('OPENWA_SESSION_ID', 'default'),
        'connect_timeout' => (float) env('OPENWA_CONNECT_TIMEOUT', 3),
        'timeout' => (float) env('OPENWA_TIMEOUT', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Sender Pool & Failover
    |--------------------------------------------------------------------------
    |
    | Round-robin rotation across multiple WhatsApp sender numbers with auto-failover.
    |
    */
    'pool' => [
        'enabled' => (bool) env('WA_OTP_POOL_ENABLED', true),
        'failover_max_retries' => (int) env('WA_OTP_POOL_FAILOVER_RETRIES', 3),
        'auto_suspend_on_fail' => (bool) env('WA_OTP_POOL_AUTO_SUSPEND', true),
        'suspend_cooldown_minutes' => (int) env('WA_OTP_POOL_SUSPEND_COOLDOWN_MINUTES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Back-Office Webhooks
    |--------------------------------------------------------------------------
    |
    | Outgoing webhook dispatched when a member verifies their phone number.
    |
    */
    'webhook' => [
        'verified_member_url' => env('WA_OTP_VERIFIED_MEMBER_WEBHOOK_URL'),
        'secret' => env('WA_OTP_WEBHOOK_SECRET'),
    ],

];
