<?php

namespace App\Services\WhatsAppOtp\Transport;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Adapter for the OpenWA gateway (unofficial WhatsApp Web / Baileys session gateway).
 * Uses the verified upstream route POST /api/sessions/{sessionId}/messages/send-text with
 * X-API-Key (OPERATOR role, ideally scoped to the sender session) and Idempotency-Key.
 */
class OpenWaTransport implements OtpTransport
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'openwa';
    }

    public function senderRef(): ?string
    {
        return $this->config['session_id'] ?? null;
    }

    public function send(string $phoneE164, #[\SensitiveParameter] string $message, string $idempotencyKey, ?string $tenantKey = null): DeliveryResult
    {
        if (! config('whatsapp_otp.sender_limits.enabled', true)) {
            return $this->deliver($phoneE164, $message, $idempotencyKey, $tenantKey);
        }
        $key = 'wa-otp:sender:'.hash('sha256', ($this->config['base_url'] ?? '').'|'.($this->config['session_id'] ?? ''));
        $lock = Cache::lock($key.':lock', 120);
        if (! $lock->get()) {
            return DeliveryResult::failed('SENDER_BUSY');
        }
        try {
            if (! Cache::add($key.':interval', true, max(1, (int) config('whatsapp_otp.sender_limits.min_interval_seconds', 3)))) {
                return DeliveryResult::failed('SENDER_BUSY');
            }

            return $this->deliver($phoneE164, $message, $idempotencyKey, $tenantKey);
        } finally {
            $lock->release();
        }
    }

    private function deliver(string $phoneE164, #[\SensitiveParameter] string $message, string $idempotencyKey, ?string $tenantKey = null): DeliveryResult
    {
        $session = $this->config['session_id'] ?? null;
        if (empty($this->config['base_url']) || empty($this->config['api_key']) || empty($session)) {
            return DeliveryResult::failed('GATEWAY_NOT_CONFIGURED');
        }

        try {
            $response = Http::baseUrl(rtrim($this->config['base_url'], '/'))
                ->acceptJson()
                ->connectTimeout($this->config['connect_timeout'] ?? 3)
                ->timeout($this->config['timeout'] ?? 15)
                ->withHeaders([
                    'X-API-Key' => $this->config['api_key'],
                    'Idempotency-Key' => $idempotencyKey,
                ])
                ->post('/api/sessions/'.rawurlencode($session).'/messages/send-text', [
                    'chatId' => ltrim($phoneE164, '+').'@c.us',
                    'text' => $message,
                ]);
        } catch (ConnectionException $e) {
            // cURL 6/7 and connect-phase timeouts prove nothing reached the gateway.
            $msg = $e->getMessage();
            if (preg_match('/cURL error (6|7)\b|Connection timed out|Could not resolve|Connection refused/i', $msg)) {
                return DeliveryResult::failed('GATEWAY_UNREACHABLE');
            }

            return DeliveryResult::unknown('GATEWAY_TIMEOUT');
        }

        if ($response->successful()) {
            return DeliveryResult::submitted($response->json('messageId'));
        }

        $status = $response->status();
        $code = (string) ($response->json('code') ?? '');
        if (in_array($code, ['IDEMPOTENCY_OUTCOME_UNKNOWN', 'IDEMPOTENCY_KEY_IN_PROGRESS'], true)) {
            return DeliveryResult::unknown('GATEWAY_'.$code);
        }
        // Upstream documents 4xx and 503 (recipient lookup) as refusals before sending.
        if ($status < 500 || $status === 503 || $status === 501) {
            return DeliveryResult::failed('GATEWAY_HTTP_'.$status);
        }

        return DeliveryResult::unknown('GATEWAY_HTTP_'.$status);
    }
}
