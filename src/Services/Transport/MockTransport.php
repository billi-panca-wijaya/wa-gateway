<?php

namespace BilliPancaWijaya\WhatsAppGateway\Services\Transport;

use Illuminate\Support\Facades\Cache;

/**
 * Local/testing-only transport. Keeps the last message per phone in the cache for the OTP TTL so a
 * developer can read it. Never logs sensitive codes in plaintext logs.
 */
class MockTransport implements OtpTransport
{
    public static ?string $forceStatus = null;

    public function name(): string
    {
        return 'mock';
    }

    public function senderRef(): ?string
    {
        return 'mock';
    }

    public function send(string $phoneE164, #[\SensitiveParameter] string $message, string $idempotencyKey, ?string $tenantKey = null): DeliveryResult
    {
        if (self::$forceStatus === DeliveryResult::FAILED) {
            return DeliveryResult::failed('MOCK_FAILED');
        }
        if (self::$forceStatus === DeliveryResult::UNKNOWN) {
            return DeliveryResult::unknown('MOCK_TIMEOUT');
        }

        $ttl = (int) config('whatsapp-gateway.ttl_seconds', config('whatsapp_otp.ttl_seconds', 300));
        $sent = Cache::get(self::key($phoneE164), []);
        $sent[] = ['message' => $message, 'idempotency_key' => $idempotencyKey];
        Cache::put(self::key($phoneE164), $sent, $ttl);

        return DeliveryResult::submitted('mock-'.$idempotencyKey);
    }

    public static function messages(string $phoneE164): array
    {
        return Cache::get(self::key($phoneE164), []);
    }

    public static function lastCode(string $phoneE164): ?string
    {
        $messages = self::messages($phoneE164);
        $last = end($messages);

        return $last && preg_match('/\b(\d{4})\b/', $last['message'], $m) ? $m[1] : null;
    }

    private static function key(string $phoneE164): string
    {
        return 'wa-otp:mock-outbox:'.$phoneE164;
    }
}
