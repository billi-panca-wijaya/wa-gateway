<?php

use BilliPancaWijaya\WhatsAppGateway\Http\Controllers\Admin\WhatsAppGatewayController;
use BilliPancaWijaya\WhatsAppGateway\Http\Controllers\Admin\WhatsAppSenderPoolController;
use BilliPancaWijaya\WhatsAppGateway\Http\Controllers\Admin\WhatsAppVerifiedMembersController;
use BilliPancaWijaya\WhatsAppGateway\Http\Controllers\BackOffice\WhatsAppMemberWebhookController;
use BilliPancaWijaya\WhatsAppGateway\Http\Controllers\WhatsAppOtpController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WhatsApp Gateway & OTP Package Routes
|--------------------------------------------------------------------------
*/

// 1. Customer OTP Endpoints (Public + Rate-Limited)
Route::prefix('auth/whatsapp')->middleware('throttle:60,1')->group(function () {
    Route::post('request-otp', [WhatsAppOtpController::class, 'requestOtp'])->name('whatsapp-gateway.otp.request');
    Route::post('verify-otp', [WhatsAppOtpController::class, 'verifyOtp'])->name('whatsapp-gateway.otp.verify');
    Route::post('resend-otp', [WhatsAppOtpController::class, 'resendOtp'])->name('whatsapp-gateway.otp.resend');
    Route::get('challenge-config', [WhatsAppOtpController::class, 'challengeConfig'])->name('whatsapp-gateway.otp.config');
    Route::get('challenges/{id}', [WhatsAppOtpController::class, 'deliveryStatus'])->where('id', '[a-f0-9]{40}')->name('whatsapp-gateway.otp.status');
});

// 2. Admin Back-Office Endpoints (Auth + Role Restricted)
$adminMiddleware = config('whatsapp-gateway.routes.admin_middleware', ['auth:sanctum', 'role:super-admin|admin', 'throttle:60,1']);

Route::middleware($adminMiddleware)->prefix('admin')->group(function () {
    // Single / Default Gateway Session
    Route::prefix('whatsapp-gateway')->controller(WhatsAppGatewayController::class)->group(function () {
        Route::get('/', 'status')->name('whatsapp-gateway.admin.status');
        Route::get('qr', 'qr')->name('whatsapp-gateway.admin.qr');
        Route::post('start', 'start')->name('whatsapp-gateway.admin.start');
        Route::post('stop', 'stop')->name('whatsapp-gateway.admin.stop');
        Route::post('logout', 'logout')->name('whatsapp-gateway.admin.logout');
        Route::post('pairing-code', 'pairingCode')->name('whatsapp-gateway.admin.pairing-code');
    });

    // Multi-Sender Pool Management
    Route::prefix('sender-pool')->controller(WhatsAppSenderPoolController::class)->group(function () {
        Route::get('/', 'index')->name('whatsapp-gateway.pool.index');
        Route::post('/', 'store')->name('whatsapp-gateway.pool.store');
        Route::get('{id}', 'show')->name('whatsapp-gateway.pool.show');
        Route::put('{id}', 'update')->name('whatsapp-gateway.pool.update');
        Route::delete('{id}', 'destroy')->name('whatsapp-gateway.pool.destroy');
        Route::post('{id}/sync', 'sync')->name('whatsapp-gateway.pool.sync');
        Route::post('{id}/toggle', 'toggle')->name('whatsapp-gateway.pool.toggle');
        Route::get('{id}/qr', 'qr')->name('whatsapp-gateway.pool.qr');
        Route::post('{id}/pairing-code', 'pairingCode')->name('whatsapp-gateway.pool.pairing-code');
        Route::post('{id}/start', 'start')->name('whatsapp-gateway.pool.start');
        Route::post('{id}/stop', 'stop')->name('whatsapp-gateway.pool.stop');
        Route::post('{id}/logout', 'logout')->name('whatsapp-gateway.pool.logout');
        Route::post('{id}/test-send', 'testSend')->name('whatsapp-gateway.pool.test-send');
    });

    // Verified Members Directory
    Route::prefix('verified-members')->controller(WhatsAppVerifiedMembersController::class)->group(function () {
        Route::get('/', 'index')->name('whatsapp-gateway.members.index');
        Route::get('{id}', 'show')->name('whatsapp-gateway.members.show');
    });

    // Nginx Subrequest Auth for Embedded Gateway Micro-Frontend
    Route::get('gateway-auth', [WhatsAppGatewayController::class, 'authorizeDashboard'])
        ->name('whatsapp-gateway.admin.auth');
});

// 3. Webhook Receiver for Agent Back-Office Sync
Route::post('webhooks/whatsapp-member-verified', WhatsAppMemberWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('whatsapp-gateway.webhook.member-verified');
