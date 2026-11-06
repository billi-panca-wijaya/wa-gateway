<?php

namespace BilliPancaWijaya\WhatsAppGateway\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \BilliPancaWijaya\WhatsAppGateway\Services\OtpService otp()
 * @method static \BilliPancaWijaya\WhatsAppGateway\Services\SenderPoolService pool()
 * @method static \BilliPancaWijaya\WhatsAppGateway\Services\OpenWaSessionClient client()
 * @method static \BilliPancaWijaya\WhatsAppGateway\Services\Transport\OtpTransport transport()
 * @method static array requestOtp(string $phone, ?string $idempotencyKey = null, ?string $tenant = null, string $ip = '127.0.0.1')
 * @method static array verifyOtp(string $challengeId, string $code, ?string $tenant = null, string $ip = '127.0.0.1')
 * @method static array resendOtp(string $challengeId, ?string $idempotencyKey = null, ?string $tenant = null, string $ip = '127.0.0.1')
 * @method static array getDeliveryStatus(string $challengeId, ?string $tenant = null)
 * @method static \BilliPancaWijaya\WhatsAppGateway\Services\Transport\DeliveryResult sendMessage(string $phone, string $message, ?string $tenant = null, ?string $idempotencyKey = null)
 *
 * @see \BilliPancaWijaya\WhatsAppGateway\WhatsAppGateway
 */
class WhatsAppGateway extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'whatsapp-gateway';
    }
}
