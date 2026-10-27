<?php

use App\Http\Controllers\WhatsAppOtpController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'throttle:120,1'])->prefix('api/v1/auth/whatsapp')->group(function () {
    Route::get('challenge-config', [WhatsAppOtpController::class, 'challengeConfig']);
    Route::get('challenges/{id}', [WhatsAppOtpController::class, 'deliveryStatus'])->where('id', '[a-f0-9]{40}');
});
