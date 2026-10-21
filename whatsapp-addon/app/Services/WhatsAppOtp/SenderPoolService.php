<?php

namespace App\Services\WhatsAppOtp;

use App\Models\SenderPool;
use App\Services\WhatsAppOtp\Transport\DeliveryResult;
use App\Services\WhatsAppOtp\Transport\OpenWaTransport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class SenderPoolService
{
    public function __construct(
        private readonly OpenWaSessionClient $sessionClient,
        private readonly array $config = []
    ) {}

    /**
     * List all sender pool records for a tenant.
     *
     * @return Collection<int, SenderPool>
     */
    public function listSenders(string $tenantKey): Collection
    {
        $this->autoReactivateCoolingSenders($tenantKey);

        return SenderPool::forTenant($tenantKey)
            ->orderBy('id', 'asc')
            ->get();
    }

    public function getSender(string $tenantKey, int $id): SenderPool
    {
        return SenderPool::forTenant($tenantKey)->findOrFail($id);
    }

    private function normalizeSenderPhone(string $raw): string
    {
        $trimmed = trim($raw);
        $region = (string) config('whatsapp_otp.default_region', 'ID');
        try {
            return PhoneNumber::normalize($trimmed, $region);
        } catch (OtpException $e) {
            $digits = preg_replace('/\D/', '', $trimmed);
            if ($digits !== '' && ! str_starts_with($digits, '0')) {
                return PhoneNumber::normalize('+'.$digits, $region);
            }
            throw $e;
        }
    }

    /**
     * Add a sender to the pool.
     */
    public function addSender(string $tenantKey, array $data, bool $createInOpenWa = false): SenderPool
    {
        $phoneE164 = null;
        if (! empty($data['phone'])) {
            $phoneE164 = $this->normalizeSenderPhone((string) $data['phone']);
        }

        $sessionId = trim((string) ($data['session_id'] ?? ''));
        if ($sessionId === '' && $phoneE164 !== null) {
            $sessionId = 'wa-'.ltrim($phoneE164, '+');
        }

        if (empty($sessionId)) {
            throw new OtpException('INVALID_SESSION_ID', 'Nomor WhatsApp atau Kode Sesi wajib diisi.', 422);
        }

        $existing = SenderPool::forTenant($tenantKey)->where('session_id', $sessionId)->first();
        if ($existing) {
            throw new OtpException('SESSION_ALREADY_EXISTS', 'Nomor / kode sesi ini sudah terdaftar di daftar pengirim.', 409);
        }

        if ($phoneE164 !== null) {
            $existingPhone = SenderPool::forTenant($tenantKey)->where('phone_e164', $phoneE164)->first();
            if ($existingPhone) {
                throw new OtpException('PHONE_ALREADY_EXISTS', 'Nomor WhatsApp '.$phoneE164.' sudah terdaftar di daftar pengirim.', 409);
            }
        }

        $sender = new SenderPool;
        $sender->tenant_key = $tenantKey;
        $sender->session_id = $sessionId;
        $sender->phone_e164 = $phoneE164;
        $sender->label = ! empty($data['label']) ? trim((string) $data['label']) : null;
        if (! empty($data['api_key'])) {
            $sender->api_key = $data['api_key'];
        }
        $sender->status = $data['status'] ?? 'active';
        $sender->save();

        if ($createInOpenWa) {
            try {
                $created = $this->sessionClient->createSession($sessionId, $data['label'] ?? $sessionId, $sender->api_key);
                if (! empty($created['id']) && $created['id'] !== $sender->session_id) {
                    $existingByUuid = SenderPool::forTenant($tenantKey)->where('session_id', $created['id'])->where('id', '!=', $sender->id)->first();
                    if (! $existingByUuid) {
                        if (empty($sender->label)) {
                            $sender->label = $phoneE164 ? ('WA '.$phoneE164) : $sessionId;
                        }
                        $sender->session_id = $created['id'];
                        $sender->save();
                    }
                }
            } catch (Throwable $e) {
                Log::warning('SenderPoolService: failed creating session in OpenWA: '.$e->getMessage());
            }
        }

        // Try syncing initial status
        try {
            $this->syncSenderStatus($sender);
        } catch (Throwable) {
            // Non-blocking
        }

        return $sender->fresh();
    }

    /**
     * Update sender details (label, status, api_key).
     */
    public function updateSender(string $tenantKey, int $id, array $data): SenderPool
    {
        $sender = $this->getSender($tenantKey, $id);

        if (array_key_exists('label', $data)) {
            $sender->label = $data['label'];
        }
        if (! empty($data['phone'])) {
            $sender->phone_e164 = $this->normalizeSenderPhone((string) $data['phone']);
        }
        if (! empty($data['api_key'])) {
            $sender->api_key = $data['api_key'];
        }
        if (! empty($data['status'])) {
            $sender->status = $data['status'];
            if ($data['status'] === 'active') {
                $sender->suspended_at = null;
                $sender->suspend_reason = null;
                $sender->fail_count = 0;
            }
        }

        $sender->save();

        return $sender;
    }

    /**
     * Remove sender from pool.
     */
    public function deleteSender(string $tenantKey, int $id, bool $deleteInOpenWa = false): bool
    {
        $sender = $this->getSender($tenantKey, $id);
        $sessionId = $sender->session_id;
        $apiKey = $sender->api_key;

        $deleted = $sender->delete();

        if ($deleted && $deleteInOpenWa) {
            try {
                $this->sessionClient->deleteSession($sessionId, $apiKey);
            } catch (Throwable $e) {
                Log::warning('SenderPoolService: failed deleting session in OpenWA: '.$e->getMessage());
            }
        }

        return (bool) $deleted;
    }

    /**
     * Toggle status active <-> suspended.
     */
    public function toggleStatus(string $tenantKey, int $id): SenderPool
    {
        $sender = $this->getSender($tenantKey, $id);
        if ($sender->status === 'active') {
            $sender->status = 'suspended';
            $sender->suspended_at = now();
            $sender->suspend_reason = 'manual_admin_toggle';
        } else {
            $sender->status = 'active';
            $sender->suspended_at = null;
            $sender->suspend_reason = null;
            $sender->fail_count = 0;
        }
        $sender->save();

        return $sender;
    }

    /**
     * Sync state and phone number from OpenWA.
     */
    public function syncSenderStatus(SenderPool $sender): array
    {
        $info = $this->sessionClient->status($sender->session_id, $sender->api_key);

        $status = strtolower((string) ($info['status'] ?? 'unknown'));
        $sender->push_name = $info['push_name'] ?? $sender->push_name;
        if (! empty($info['phone'])) {
            try {
                $sender->phone_e164 = $this->normalizeSenderPhone((string) $info['phone']);
            } catch (Throwable) {
                // Keep existing phone_e164 if gateway returned an unparseable value
            }
        }

        if (in_array($status, ['stopped', 'failed', 'disconnected', 'action_required', 'created'], true) && $sender->status === 'active') {
            $sender->status = 'disconnected';
        } elseif (in_array($status, ['ready', 'working'], true) && $sender->status === 'disconnected') {
            $sender->status = 'active';
        }

        $sender->save();

        return $info;
    }

    /**
     * Start session on OpenWA engine.
     */
    public function startSession(SenderPool $sender): array
    {
        return $this->sessionClient->start($sender->session_id, $sender->api_key);
    }

    /**
     * Stop session on OpenWA engine.
     */
    public function stopSession(SenderPool $sender): array
    {
        return $this->sessionClient->stop($sender->session_id, $sender->api_key);
    }

    /**
     * Logout session on OpenWA engine.
     */
    public function logoutSession(SenderPool $sender): array
    {
        return $this->sessionClient->logout($sender->session_id, $sender->api_key);
    }

    /**
     * Get QR code for this sender.
     */
    public function getQrCode(SenderPool $sender): array
    {
        return $this->sessionClient->qr($sender->session_id, $sender->api_key);
    }

    /**
     * Get 8-digit pairing code for this sender.
     */
    public function getPairingCode(SenderPool $sender, string $phoneDigits): array
    {
        return $this->sessionClient->pairingCode($phoneDigits, $sender->session_id, $sender->api_key);
    }

    /**
     * Send a test text message from a specific sender.
     */
    public function testSend(SenderPool $sender, string $phoneE164, string $text): DeliveryResult
    {
        $openwaCfg = (array) config('whatsapp_otp.openwa', []);
        $defaultSession = $openwaCfg['session_id'] ?? null;
        $openwaCfg['session_id'] = $sender->session_id;
        if (! empty($sender->api_key)) {
            $openwaCfg['api_key'] = $sender->api_key;
        } elseif ($sender->session_id !== $defaultSession && ! empty($openwaCfg['admin_api_key'])) {
            $openwaCfg['api_key'] = $openwaCfg['admin_api_key'];
        }

        $transport = new OpenWaTransport($openwaCfg);
        $idempotencyKey = 'test-send-'.bin2hex(random_bytes(10));

        return $transport->send($phoneE164, $text, $idempotencyKey);
    }

    /**
     * Get next active sender for dispatch based on round-robin (last_used_at ASC).
     */
    public function nextAvailableSender(string $tenantKey): ?SenderPool
    {
        $this->autoReactivateCoolingSenders($tenantKey);

        return SenderPool::forTenant($tenantKey)
            ->active()
            ->orderByRaw('last_used_at IS NOT NULL, last_used_at ASC, id ASC')
            ->first();
    }

    /**
     * Get list of all currently active senders in round-robin order for failover loop.
     *
     * @return Collection<int, SenderPool>
     */
    public function getActiveSendersInRotation(string $tenantKey): Collection
    {
        $this->autoReactivateCoolingSenders($tenantKey);

        return SenderPool::forTenant($tenantKey)
            ->active()
            ->orderByRaw('last_used_at IS NOT NULL, last_used_at ASC, id ASC')
            ->get();
    }

    public function recordSuccess(SenderPool $sender): void
    {
        $sender->update([
            'last_used_at' => now(),
            'fail_count' => 0,
        ]);
    }

    public function recordFailure(SenderPool $sender, string $reason): void
    {
        $autoSuspend = (bool) config('whatsapp_otp.pool.auto_suspend_on_fail', true);
        $threshold = (int) config('whatsapp_otp.pool.failover_max_retries', 3);

        $sender->increment('fail_count');

        if ($autoSuspend || $sender->fail_count >= $threshold) {
            $sender->update([
                'status' => 'suspended',
                'suspended_at' => now(),
                'suspend_reason' => mb_substr($reason, 0, 250),
            ]);
            Log::warning("SenderPool: sender [{$sender->session_id}] marked suspended. Reason: {$reason}");
        }
    }

    /**
     * Automatically reactivate suspended senders after cooldown.
     */
    private function autoReactivateCoolingSenders(string $tenantKey): void
    {
        $cooldownMinutes = (int) config('whatsapp_otp.pool.suspend_cooldown_minutes', 30);
        if ($cooldownMinutes <= 0) {
            return;
        }

        $cutoff = now()->subMinutes($cooldownMinutes);

        SenderPool::forTenant($tenantKey)
            ->where('status', 'suspended')
            ->where('suspend_reason', '!=', 'manual_admin_toggle')
            ->where('suspended_at', '<=', $cutoff)
            ->update([
                'status' => 'active',
                'suspended_at' => null,
                'suspend_reason' => null,
                'fail_count' => 0,
            ]);
    }
}
