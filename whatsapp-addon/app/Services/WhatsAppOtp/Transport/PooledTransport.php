<?php

namespace App\Services\WhatsAppOtp\Transport;

use App\Services\WhatsAppOtp\SenderPoolService;
use Illuminate\Support\Facades\Log;

/**
 * Multi-sender round-robin transport with automatic failover.
 * Wraps OpenWaTransport per sender. If table sender_pool is empty or has no active senders,
 * seamlessly falls back to the legacy single-session env configuration.
 */
class PooledTransport implements OtpTransport
{
    private ?string $lastUsedSender = null;

    public function __construct(
        private readonly SenderPoolService $poolService,
        private readonly array $defaultConfig = []
    ) {}

    public function name(): string
    {
        return 'openwa';
    }

    public function senderRef(): ?string
    {
        return $this->lastUsedSender ?? ($this->defaultConfig['session_id'] ?? null);
    }

    public function send(string $phoneE164, #[\SensitiveParameter] string $message, string $idempotencyKey, ?string $tenantKey = null): DeliveryResult
    {
        $defaultTenant = (string) config('whatsapp_otp.default_tenant', 'default');
        $tenantKey ??= $defaultTenant;
        $this->lastUsedSender = null;
        $poolEnabled = (bool) config('whatsapp_otp.pool.enabled', true);

        $activeSenders = $poolEnabled
            ? $this->poolService->getActiveSendersInRotation($tenantKey)
            : collect();

        // Fallback to legacy single-session if pool is empty or disabled
        if ($activeSenders->isEmpty()) {
            if ($tenantKey !== $defaultTenant) {
                return DeliveryResult::failed('NO_TENANT_SENDERS');
            }
            $fallbackTransport = new OpenWaTransport($this->defaultConfig);
            $this->lastUsedSender = $fallbackTransport->senderRef();

            return $fallbackTransport->send($phoneE164, $message, $idempotencyKey);
        }

        $lastResult = null;
        $maxRetries = (int) config('whatsapp_otp.pool.failover_max_retries', 3);
        $attempts = 0;

        foreach ($activeSenders as $sender) {
            $this->lastUsedSender = $sender->session_id;

            $defaultSession = $this->defaultConfig['session_id'] ?? null;
            $fallbackKey = ($sender->session_id !== $defaultSession && ! empty($this->defaultConfig['admin_api_key']))
                ? $this->defaultConfig['admin_api_key']
                : ($this->defaultConfig['api_key'] ?? ($this->defaultConfig['admin_api_key'] ?? null));

            $senderConfig = [
                'base_url' => $this->defaultConfig['base_url'] ?? 'http://openwa:2785',
                'session_id' => $sender->session_id,
                'api_key' => $sender->api_key ?: $fallbackKey,
                'connect_timeout' => $this->defaultConfig['connect_timeout'] ?? 3,
                'timeout' => $this->defaultConfig['timeout'] ?? 15,
            ];

            $transport = new OpenWaTransport($senderConfig);
            $result = $transport->send($phoneE164, $message, $idempotencyKey);
            if ($result->errorCode === 'SENDER_BUSY') {
                $lastResult ??= $result;

                continue;
            }
            $attempts++;
            $lastResult = $result;

            if ($result->status !== DeliveryResult::FAILED) {
                // Succeeded or Unknown (gateway accepted/in-flight)
                $this->poolService->recordSuccess($sender);

                return $result;
            }

            // Delivery failed for this sender -> record failure and auto-failover to next sender
            $this->poolService->recordFailure($sender, $result->errorCode ?? 'DELIVERY_FAILED');

            Log::warning("PooledTransport: Sender [{$sender->session_id}] failed ({$result->errorCode}). Trying next sender in pool.");

            if ($attempts >= $maxRetries) {
                break;
            }
        }

        if ($attempts > 0) {
            Log::error("PooledTransport: All available senders in pool failed. Total attempts: {$attempts}.");
        }

        return $lastResult ?? DeliveryResult::failed('ALL_POOL_SENDERS_FAILED');
    }
}
