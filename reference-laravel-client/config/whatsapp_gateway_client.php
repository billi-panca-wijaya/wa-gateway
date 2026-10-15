<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WhatsApp API Gateway Base URL
    |--------------------------------------------------------------------------
    | The base URL where the WhatsApp API Gateway backend is deployed.
    | e.g. https://api.yourdomain.com
    */
    'base_url' => env('WA_GATEWAY_API_URL', 'http://localhost:8000'),

    /*
    |--------------------------------------------------------------------------
    | Admin API Token / Bearer Token
    |--------------------------------------------------------------------------
    | Bearer token generated from the admin account with role:admin or super-admin.
    */
    'token' => env('WA_GATEWAY_API_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | HTTP Request Timeout
    |--------------------------------------------------------------------------
    | Connect and total timeouts in seconds for gateway requests.
    */
    'timeout' => (int) env('WA_GATEWAY_TIMEOUT', 15),
    'connect_timeout' => (int) env('WA_GATEWAY_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Webhook Secret
    |--------------------------------------------------------------------------
    | Shared secret used to verify the X-Webhook-Signature HMAC header.
    */
    'webhook_secret' => env('WA_GATEWAY_WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Default Tenant Key
    |--------------------------------------------------------------------------
    */
    'default_tenant' => env('WA_GATEWAY_DEFAULT_TENANT', 'default'),
];
