<?php

use App\Http\Controllers\Admin\SenderPoolController;
use App\Http\Controllers\Admin\VerifiedMembersController;
use App\Http\Controllers\Admin\WhatsAppGatewayController;
use App\Http\Controllers\BackOffice\WhatsAppMemberWebhookController;
use App\Http\Controllers\WhatsAppOtpController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WhatsApp Gateway & OTP Routes (Add-on Package)
|--------------------------------------------------------------------------
|
| Non-intrusive route registration.
| Include this file in your routes/api.php:
|   require __DIR__ . '/whatsapp.php';
|
| Or let WhatsAppOtpServiceProvider load it automatically.
|
*/

// 1. Customer OTP Endpoints (Public + Rate-Limited)
Route::prefix('v1/whatsapp')->middleware('throttle:60,1')->group(function () {
    Route::post('request-otp', [WhatsAppOtpController::class, 'requestOtp']);
    Route::post('verify-otp', [WhatsAppOtpController::class, 'verifyOtp']);
    Route::post('resend-otp', [WhatsAppOtpController::class, 'resendOtp']);
});

// 2. Admin Back-Office Endpoints (Auth + Role Restricted)
Route::prefix('v1/admin/whatsapp-gateway')
    ->middleware(['auth:sanctum', 'role:super-admin|admin', 'throttle:60,1'])
    ->controller(WhatsAppGatewayController::class)
    ->group(function () {
        Route::get('/', 'status');
        Route::get('qr', 'qr');
        Route::post('start', 'start');
        Route::post('stop', 'stop');
        Route::post('logout', 'logout');
        Route::post('pairing-code', 'pairingCode');
    });

// Multi-Sender Pool Management (Menu 1: WhatsApp Sessions & Senders)
Route::prefix('v1/admin/sender-pool')
    ->middleware(['auth:sanctum', 'role:super-admin|admin', 'throttle:60,1'])
    ->controller(SenderPoolController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('{id}', 'show');
        Route::put('{id}', 'update');
        Route::delete('{id}', 'destroy');
        Route::get('{id}/status', 'status');
        Route::get('{id}/qr', 'qr');
        Route::post('{id}/pairing-code', 'pairingCode');
        Route::post('{id}/start', 'start');
        Route::post('{id}/stop', 'stop');
        Route::post('{id}/logout', 'logout');
        Route::post('{id}/test-send', 'testSend');
    });

// Verified Members List (Menu 2: Verified Members)
Route::prefix('v1/admin/verified-members')
    ->middleware(['auth:sanctum', 'role:super-admin|admin', 'throttle:60,1'])
    ->controller(VerifiedMembersController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::get('{id}', 'show');
    });

// Nginx Subrequest Auth for Embedded Gateway Micro-Frontend (Menus 3-9: Dashboard, Chats, Webhooks, Templates, etc.)
Route::get('v1/admin/gateway-auth', [WhatsAppGatewayController::class, 'authorizeDashboard'])
    ->middleware(['auth:sanctum', 'role:super-admin|admin']);

// 3. Webhook Receiver for Agent Back-Office (Optional Sync)
Route::post('v1/webhooks/whatsapp-member-verified', WhatsAppMemberWebhookController::class)
    ->middleware('throttle:120,1');
