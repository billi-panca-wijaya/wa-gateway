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
    'ttl_seconds' => (int) env('WA_OTP_TTL_SECONDS', 300),
    'resend_cooldown_seconds' => (int) env('WA_OTP_RESEND_COOLDOWN_SECONDS', 30),
    'max_attempts' => (int) env('WA_OTP_MAX_ATTEMPTS', 5),

    'limits' => [
        'send_per_phone' => (int) env('WA_OTP_SEND_PER_PHONE', 5),
        'send_per_ip' => (int) env('WA_OTP_SEND_PER_IP', 20),
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
        'Kode masuk :app Anda: :code. Berlaku :minutes menit. Jangan bagikan kode ini kepada siapa pun.'
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
