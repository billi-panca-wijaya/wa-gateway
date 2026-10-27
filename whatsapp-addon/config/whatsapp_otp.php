<?php

return [

    /*
    | Tenant resolution. The tenant is never taken from the client body: it is resolved from the
    | request host via WA_OTP_TENANT_HOSTS ("host=tenant,host2=tenant2") and falls back to the
    | default tenant of this deployment.
    */
    'default_tenant' => env('WA_OTP_DEFAULT_TENANT', 'default'),
    'tenant_hosts' => env('WA_OTP_TENANT_HOSTS', ''),

    // Region applied to numbers typed without a leading "+" (e.g. 08xx for ID).
    'default_region' => env('WA_OTP_DEFAULT_REGION', 'ID'),

    'code_length' => 4, // Member login contract; independent of sender pairing codes.
    'ttl_seconds' => 300,
    'resend_cooldown_seconds' => 30,
    'max_attempts' => (int) env('WA_OTP_MAX_ATTEMPTS', 5),

    'turnstile' => [
        'enabled' => (bool) env('WA_OTP_TURNSTILE_ENABLED', true),
        'site_key' => env('WA_OTP_TURNSTILE_SITE_KEY', ''),
        'secret_key' => env('WA_OTP_TURNSTILE_SECRET_KEY', ''),
        'hostnames' => env('WA_OTP_TURNSTILE_HOSTNAMES', ''),
    ],
    'queue' => [
        'enabled' => (bool) env('WA_OTP_QUEUE_ENABLED', true),
        'connection' => env('WA_OTP_QUEUE_CONNECTION', 'redis'),
        'name' => env('WA_OTP_QUEUE_NAME', 'whatsapp-otp'),
        'max_wait_seconds' => (int) env('WA_OTP_QUEUE_MAX_WAIT_SECONDS', 30),
        'max_pending' => (int) env('WA_OTP_QUEUE_MAX_PENDING', 1000),
    ],
    // Operational limits, not a guarantee against WhatsApp account restrictions.
    'sender_limits' => [
        'enabled' => (bool) env('WA_OTP_SENDER_LIMITS_ENABLED', true),
        'min_interval_seconds' => (int) env('WA_OTP_SENDER_MIN_INTERVAL_SECONDS', 3),
    ],

    'limits' => [
        'send_per_phone' => (int) env('WA_OTP_SEND_PER_PHONE', 5),
        'send_per_ip' => (int) env('WA_OTP_SEND_PER_IP', 20),
        'send_system' => (int) env('WA_OTP_SEND_SYSTEM', 2000),
        'send_global' => (int) env('WA_OTP_SEND_GLOBAL', 500),
        'send_window_seconds' => (int) env('WA_OTP_SEND_WINDOW_SECONDS', 900),
        'verify_per_ip' => (int) env('WA_OTP_VERIFY_PER_IP', 60),
        'verify_window_seconds' => (int) env('WA_OTP_VERIFY_WINDOW_SECONDS', 900),
    ],

    // HMAC secret for OTP verifiers. Kept outside the database. Required in production.
    'hmac_secret' => env('WA_OTP_HMAC_SECRET'),

    // Days to keep finished challenges/deliveries (without verifier / body) before pruning.
    'retention_days' => (int) env('WA_OTP_RETENTION_DAYS', 30),

    'message_template' => env(
        'WA_OTP_MESSAGE_TEMPLATE',
        'Your :app sign-in code is :code. Expires in :minutes minutes. Do not share this code.'
    ),

    // openwa | mock (mock is refused outside local/testing).
    'transport' => env('WA_OTP_TRANSPORT', 'mock'),

    'openwa' => [
        'base_url' => env('OPENWA_BASE_URL', 'http://openwa:2785'),
        'api_key' => env('OPENWA_API_KEY'),
        'admin_api_key' => env('OPENWA_DASHBOARD_API_KEY'),
        'session_id' => env('OPENWA_SESSION_ID'),
        'connect_timeout' => (float) env('OPENWA_CONNECT_TIMEOUT', 3),
        'timeout' => (float) env('OPENWA_TIMEOUT', 15),
    ],

    /*
    | Multi-Sender Pool & Failover configuration.
    */
    'pool' => [
        'enabled' => (bool) env('WA_OTP_POOL_ENABLED', true),
        'failover_max_retries' => (int) env('WA_OTP_POOL_FAILOVER_RETRIES', 3),
        'auto_suspend_on_fail' => (bool) env('WA_OTP_POOL_AUTO_SUSPEND', true),
        'suspend_cooldown_minutes' => (int) env('WA_OTP_POOL_SUSPEND_COOLDOWN_MINUTES', 30),
    ],

    /*
    | Back-office integration & Webhooks
    */
    'webhook' => [
        'verified_member_url' => env('WA_OTP_VERIFIED_MEMBER_WEBHOOK_URL'),
        'secret' => env('WA_OTP_WEBHOOK_SECRET'),
    ],

];
