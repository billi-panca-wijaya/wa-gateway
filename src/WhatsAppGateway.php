<?php

namespace BilliPancaWijaya\WhatsAppGateway;

use BilliPancaWijaya\WhatsAppGateway\Services\OpenWaSessionClient;
use BilliPancaWijaya\WhatsAppGateway\Services\OtpService;
use BilliPancaWijaya\WhatsAppGateway\Services\PhoneNumber;
use BilliPancaWijaya\WhatsAppGateway\Services\SenderPoolService;
use BilliPancaWijaya\WhatsAppGateway\Services\Transport\DeliveryResult;
use BilliPancaWijaya\WhatsAppGateway\Services\Transport\OtpTransport;

class WhatsAppGateway
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly SenderPoolService $senderPoolService,
        private readonly OpenWaSessionClient $sessionClient,
        private readonly OtpTransport $transport,
    ) {}

    public function otp(): OtpService
    {
        return $this->otpService;
    }

    public function pool(): SenderPoolService
    {
        return $this->senderPoolService;
    }

    public function client(): OpenWaSessionClient
    {
        return $this->sessionClient;
    }

    public function transport(): OtpTransport
    {
        return $this->transport;
    }

    /**
     * Request an OTP challenge for a phone number.
     */
    public function requestOtp(string $phone, ?string $idempotencyKey = null, ?string $tenant = null, string $ip = '127.0.0.1'): array
    {
        $tenant ??= (string) config('whatsapp-gateway.default_tenant', 'default');

        return $this->otpService->request($tenant, $phone, $idempotencyKey, $ip);
    }

    /**
     * Verify an OTP challenge code.
     */
    public function verifyOtp(string $challengeId, #[\SensitiveParameter] string $code, ?string $tenant = null, string $ip = '127.0.0.1'): array
    {
        $tenant ??= (string) config('whatsapp-gateway.default_tenant', 'default');

        return $this->otpService->verify($tenant, $challengeId, $code, $ip);
    }

    /**
     * Resend an OTP challenge.
     */
    public function resendOtp(string $challengeId, ?string $idempotencyKey = null, ?string $tenant = null, string $ip = '127.0.0.1'): array
    {
        $tenant ??= (string) config('whatsapp-gateway.default_tenant', 'default');

        return $this->otpService->resend($tenant, $challengeId, $idempotencyKey, $ip);
    }

    /**
     * Check status of an OTP challenge.
     */
    public function getDeliveryStatus(string $challengeId, ?string $tenant = null): array
    {
        $tenant ??= (string) config('whatsapp-gateway.default_tenant', 'default');

        return $this->otpService->status($tenant, $challengeId);
    }

    /**
     * Send an arbitrary message directly through the configured sender pool or transport.
     */
    public function sendMessage(string $phone, #[\SensitiveParameter] string $message, ?string $tenant = null, ?string $idempotencyKey = null): DeliveryResult
    {
        $tenant ??= (string) config('whatsapp-gateway.default_tenant', 'default');
        $idempotencyKey ??= 'msg-'.bin2hex(random_bytes(10));
        $defaultRegion = (string) config('whatsapp-gateway.default_region', 'ID');
        $e164 = PhoneNumber::normalize($phone, $defaultRegion);

        return $this->transport->send($e164, $message, $idempotencyKey, $tenant);
    }
}
