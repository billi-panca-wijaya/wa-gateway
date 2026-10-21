<?php

namespace App\Providers;

use App\Services\WhatsAppOtp\OpenWaSessionClient;
use App\Services\WhatsAppOtp\SenderPoolService;
use App\Services\WhatsAppOtp\Transport\MockTransport;
use App\Services\WhatsAppOtp\Transport\OpenWaTransport;
use App\Services\WhatsAppOtp\Transport\OtpTransport;
use App\Services\WhatsAppOtp\Transport\PooledTransport;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class WhatsAppOtpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OpenWaSessionClient::class, fn () => new OpenWaSessionClient((array) config('whatsapp_otp.openwa')));

        $this->app->bind(SenderPoolService::class, function ($app) {
            return new SenderPoolService(
                $app->make(OpenWaSessionClient::class),
                (array) config('whatsapp_otp.pool', [])
            );
        });

        $this->app->bind(OtpTransport::class, function ($app) {
            $driver = config('whatsapp_otp.transport');
            if ($driver === 'openwa') {
                return new PooledTransport(
                    $app->make(SenderPoolService::class),
                    (array) config('whatsapp_otp.openwa')
                );
            }
            if ($driver === 'mock' && $app->environment(['local', 'testing'])) {
                return new MockTransport;
            }

            throw new RuntimeException('Invalid WA_OTP_TRANSPORT for this environment (mock is local/testing only).');
        });
    }
}
