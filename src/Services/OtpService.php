<?php

namespace BilliPancaWijaya\WhatsAppGateway\Services;

use BilliPancaWijaya\WhatsAppGateway\Exceptions\OtpException;
use BilliPancaWijaya\WhatsAppGateway\Jobs\WhatsAppSendOtp;
use BilliPancaWijaya\WhatsAppGateway\Models\WhatsAppOtpChallenge as OtpChallenge;
use BilliPancaWijaya\WhatsAppGateway\Models\WhatsAppOtpDelivery as OtpDelivery;
use BilliPancaWijaya\WhatsAppGateway\Services\Transport\DeliveryResult;
use BilliPancaWijaya\WhatsAppGateway\Services\Transport\OtpTransport;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Throwable;

class OtpService
{
    public const PURPOSE_LOGIN = 'login';

    public function __construct(private readonly OtpTransport $transport) {}

    private function gatewayConfig(string $key, mixed $default = null): mixed
    {
        return config("whatsapp-gateway.{$key}", config("whatsapp_otp.{$key}", $default));
    }

    private function getUserModel(): string
    {
        return (string) config('whatsapp-gateway.user_model', config('auth.providers.users.model', 'App\\Models\\User'));
    }

    /**
     * Create a challenge for $rawPhone and send the code. Returns the public challenge payload.
     */
    public function request(string $tenant, string $rawPhone, ?string $idempotencyKey, string $ip): array
    {
        $defaultRegion = (string) $this->gatewayConfig('default_region', 'ID');
        $phone = PhoneNumber::normalize($rawPhone, $defaultRegion);

        if ($idempotencyKey !== null && ($replay = $this->replay($tenant, $phone, $idempotencyKey))) {
            return $replay;
        }

        return $this->issue($tenant, $phone, $idempotencyKey, $ip, null);
    }

    /**
     * Explicit resend: issue a new code for the phone of $challengeId and supersede the old one.
     */
    public function resend(string $tenant, string $challengeId, ?string $idempotencyKey, string $ip): array
    {
        $old = OtpChallenge::where('tenant_key', $tenant)->find($challengeId);
        if (! $old) {
            throw OtpException::make('CHALLENGE_NOT_RESENDABLE', 409);
        }

        if ($idempotencyKey !== null && ($replay = $this->replay($tenant, $old->phone_e164, $idempotencyKey))) {
            return $replay;
        }

        return $this->issue($tenant, $old->phone_e164, $idempotencyKey, $ip, $old->id);
    }

    /**
     * Verify a code. On success the challenge is consumed atomically and the user is returned.
     *
     * @return array{user: mixed, created: bool}
     */
    public function verify(string $tenant, string $challengeId, #[\SensitiveParameter] string $code, string $ip): array
    {
        $limits = (array) $this->gatewayConfig('limits', []);
        $verifyPerIp = (int) ($limits['verify_per_ip'] ?? 60);
        $verifyWindowSeconds = (int) ($limits['verify_window_seconds'] ?? 900);

        $ipKey = 'wa-otp:verify:ip:'.sha1($ip);
        if (RateLimiter::tooManyAttempts($ipKey, $verifyPerIp)) {
            throw OtpException::make('RATE_LIMITED', 429, [], RateLimiter::availableIn($ipKey));
        }
        RateLimiter::hit($ipKey, $verifyWindowSeconds);

        $challenge = OtpChallenge::where('tenant_key', $tenant)->find($challengeId);
        if (! $challenge) {
            $this->audit('verify', $tenant, null, 'not_found');
            throw OtpException::make('OTP_INVALID', 401);
        }

        $this->assertVerifiable($challenge);

        // Atomic attempt reservation: parallel requests cannot exceed max_attempts.
        $now = now();
        $reserved = OtpChallenge::whereKey($challenge->id)
            ->where('state', OtpChallenge::PENDING)
            ->whereColumn('attempts', '<', 'max_attempts')
            ->where('expires_at', '>', $now)
            ->update(['attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]);

        if ($reserved !== 1) {
            $challenge->refresh();
            if ($challenge->state === OtpChallenge::PENDING && $challenge->attempts >= $challenge->max_attempts) {
                $this->transition($challenge->id, OtpChallenge::LOCKED);
                $challenge->state = OtpChallenge::LOCKED;
            }
            $this->assertVerifiable($challenge);
            throw OtpException::make('OTP_INVALID', 401);
        }

        $challenge->refresh();
        $expected = $this->verifier($challenge->id, $tenant, $challenge->phone_e164, $challenge->purpose, $code);

        if ($challenge->verifier === null || ! hash_equals($challenge->verifier, $expected)) {
            $remaining = max(0, $challenge->max_attempts - $challenge->attempts);
            if ($remaining === 0) {
                $this->transition($challenge->id, OtpChallenge::LOCKED);
                $this->audit('verify', $tenant, $challenge->phone_e164, 'locked');
                throw OtpException::make('OTP_ATTEMPTS_EXCEEDED', 429);
            }
            $this->audit('verify', $tenant, $challenge->phone_e164, 'invalid');
            throw OtpException::make('OTP_INVALID', 401, ['attempts_remaining' => $remaining]);
        }

        // Single-use consumption: only one request can flip pending -> verified.
        $consumed = OtpChallenge::whereKey($challenge->id)
            ->where('state', OtpChallenge::PENDING)
            ->where('expires_at', '>', now())
            ->update([
                'state' => OtpChallenge::VERIFIED,
                'consumed_at' => now(),
                'verifier' => null,
                'updated_at' => now(),
            ]);
        if ($consumed !== 1) {
            $this->audit('verify', $tenant, $challenge->phone_e164, 'race_lost');
            throw OtpException::make('OTP_INVALID', 401);
        }

        $result = $this->resolveUser($tenant, $challenge->phone_e164);
        $this->audit('verify', $tenant, $challenge->phone_e164, $result['created'] ? 'success_new' : 'success_existing');

        return $result;
    }

    private function issue(string $tenant, string $phone, ?string $idempotencyKey, string $ip, ?string $resendOf): array
    {
        $this->secret();
        $queueCfg = (array) $this->gatewayConfig('queue', []);
        $async = (bool) ($queueCfg['enabled'] ?? true);
        $connection = (string) ($queueCfg['connection'] ?? 'redis');

        if ($async && config("queue.connections.{$connection}.driver") !== 'redis') {
            throw new OtpException('QUEUE_UNAVAILABLE', 'OTP delivery is temporarily unavailable. Please try again.', 503);
        }
        if (app()->environment('production') && (! $async || config('cache.stores.'.config('cache.default').'.driver') !== 'redis')) {
            throw new OtpException('QUEUE_UNAVAILABLE', 'OTP delivery is temporarily unavailable. Please try again.', 503);
        }

        $limits = (array) $this->gatewayConfig('limits', []);
        $sendWindowSeconds = (int) ($limits['send_window_seconds'] ?? 900);
        $keys = [
            'wa-otp:send:phone:'.$tenant.':'.$phone => (int) ($limits['send_per_phone'] ?? 5),
            'wa-otp:send:ip:'.sha1($ip) => (int) ($limits['send_per_ip'] ?? 20),
            'wa-otp:send:global:'.$tenant => (int) ($limits['send_global'] ?? 500),
            'wa-otp:send:system' => (int) ($limits['send_system'] ?? 2000),
        ];

        $lock = Cache::lock('wa-otp:issue:'.$tenant.':'.$phone, 120);
        if (! $lock->get()) {
            throw OtpException::make('REQUEST_IN_PROGRESS', 409);
        }

        try {
            if ($idempotencyKey !== null && ($replay = $this->replay($tenant, $phone, $idempotencyKey))) {
                return $replay;
            }
            $admission = Cache::lock('wa-otp:admission', 10);
            try {
                $admission->block(2);
            } catch (LockTimeoutException) {
                throw OtpException::make('REQUEST_IN_PROGRESS', 409);
            }
            try {
                if ($idempotencyKey !== null && ($replay = $this->replay($tenant, $phone, $idempotencyKey))) {
                    return $replay;
                }
                foreach ($keys as $key => $max) {
                    if (RateLimiter::tooManyAttempts($key, $max)) {
                        throw OtpException::make('RATE_LIMITED', 429, [], RateLimiter::availableIn($key));
                    }
                }
                $active = OtpChallenge::where('tenant_key', $tenant)->where('phone_e164', $phone)
                    ->whereIn('state', [OtpChallenge::PENDING, 'queued'])
                    ->where('resend_available_at', '>', now())->latest('created_at')->first();
                if ($active) {
                    throw OtpException::make('RESEND_COOLDOWN', 429, [], max(1, (int) ceil(now()->diffInSeconds($active->resend_available_at))));
                }
                $maxPending = (int) ($queueCfg['max_pending'] ?? 1000);
                if ($async && OtpChallenge::where('state', 'queued')->where('expires_at', '>', now())->count() >= $maxPending) {
                    throw new OtpException('QUEUE_FULL', 'OTP delivery is busy. Please try again shortly.', 503, [], 30);
                }
                $id = bin2hex(random_bytes(20));
                $maxWaitSeconds = (int) ($queueCfg['max_wait_seconds'] ?? 30);
                $resendCooldown = (int) $this->gatewayConfig('resend_cooldown_seconds', 30);
                $maxAttempts = (int) $this->gatewayConfig('max_attempts', 5);

                $challenge = DB::transaction(function () use ($tenant, $phone, $id, $idempotencyKey, $maxWaitSeconds, $resendCooldown, $maxAttempts) {
                    OtpChallenge::where('tenant_key', $tenant)->where('phone_e164', $phone)
                        ->whereIn('state', [OtpChallenge::PENDING, 'queued'])
                        ->update(['state' => OtpChallenge::SUPERSEDED, 'superseded_at' => now(), 'superseded_by' => $id, 'verifier' => null, 'updated_at' => now()]);

                    $challenge = OtpChallenge::create([
                        'id' => $id,
                        'tenant_key' => $tenant,
                        'phone_e164' => $phone,
                        'purpose' => self::PURPOSE_LOGIN,
                        'verifier' => null,
                        'state' => 'queued',
                        'attempts' => 0,
                        'max_attempts' => $maxAttempts,
                        'expires_at' => now()->addSeconds($maxWaitSeconds),
                        'resend_available_at' => now()->addSeconds($resendCooldown),
                        'idempotency_key' => $idempotencyKey,
                    ]);
                    OtpDelivery::create([
                        'challenge_id' => $id,
                        'transport' => $this->transport->name(),
                        'idempotency_key' => $id,
                        'status' => 'queued',
                    ]);

                    return $challenge;
                });
                foreach (array_keys($keys) as $key) {
                    RateLimiter::hit($key, $sendWindowSeconds);
                }
            } finally {
                $admission->release();
            }
        } finally {
            $lock->release();
        }

        if ($async) {
            try {
                $queueName = (string) ($queueCfg['name'] ?? 'whatsapp-otp');
                WhatsAppSendOtp::dispatch($id)->onConnection($connection)->onQueue($queueName);
            } catch (Throwable) {
                $this->cancelDelivery($id, 'QUEUE_UNAVAILABLE');
                throw new OtpException('QUEUE_UNAVAILABLE', 'OTP delivery is temporarily unavailable. Please try again.', 503);
            }
        } else {
            $this->deliverQueued($id);
        }

        $challenge->refresh();
        $status = (string) OtpDelivery::where('challenge_id', $id)->value('status');
        if ($status === DeliveryResult::FAILED) {
            throw OtpException::make('TRANSPORT_UNAVAILABLE', 503, ['delivery_status' => $status]);
        }

        return $this->present($challenge, $status);
    }

    public function deliverQueued(string $id): bool
    {
        $challenge = OtpChallenge::find($id);
        if (! $challenge || $challenge->state !== 'queued') {
            return true;
        }

        $lock = Cache::lock('wa-otp:issue:'.$challenge->tenant_key.':'.$challenge->phone_e164, 120);
        if (! $lock->get()) {
            return false;
        }

        try {
            $challenge->refresh();
            if ($challenge->state !== 'queued') {
                return true;
            }
            if ($challenge->expires_at->lte(now())) {
                $this->cancelDelivery($id, 'QUEUE_EXPIRED');

                return true;
            }

            $codeLength = (int) $this->gatewayConfig('code_length', 4);
            $maxRandom = (10 ** $codeLength) - 1;
            $code = str_pad((string) random_int(0, $maxRandom), $codeLength, '0', STR_PAD_LEFT);
            $ttlSeconds = (int) $this->gatewayConfig('ttl_seconds', 300);

            $challenge->update([
                'state' => OtpChallenge::PENDING,
                'verifier' => $this->verifier($id, $challenge->tenant_key, $challenge->phone_e164, self::PURPOSE_LOGIN, $code),
                'expires_at' => now()->addSeconds($ttlSeconds),
            ]);

            OtpDelivery::where('challenge_id', $id)->update(['status' => 'sending', 'updated_at' => now()]);

            try {
                $ttlMinutes = max(1, round($ttlSeconds / 60, 1));
                $result = $this->transport->send($challenge->phone_e164, $this->message($code, $ttlMinutes), $id, $challenge->tenant_key);
            } catch (Throwable) {
                // The provider may have accepted the message. Never retry ambiguous delivery.
                $result = DeliveryResult::unknown('DELIVERY_OUTCOME_UNKNOWN');
            } finally {
                unset($code);
            }

            if ($result->errorCode === 'SENDER_BUSY') {
                $queueWaitSeconds = (int) ($this->gatewayConfig('queue.max_wait_seconds', 30));
                $challenge->update([
                    'state' => 'queued',
                    'verifier' => null,
                    'expires_at' => $challenge->created_at->copy()->addSeconds($queueWaitSeconds),
                ]);
                OtpDelivery::where('challenge_id', $id)->update(['status' => 'queued', 'updated_at' => now()]);

                return false;
            }

            OtpDelivery::where('challenge_id', $id)->update([
                'sender_ref' => $this->transport->senderRef(),
                'provider_message_id' => $result->providerMessageId,
                'status' => $result->status,
                'error_code' => $result->errorCode,
                'updated_at' => now(),
            ]);

            if ($result->status === DeliveryResult::FAILED) {
                $this->cancelDelivery($id, $result->errorCode ?? 'DELIVERY_FAILED');
            }

            return true;
        } finally {
            $lock->release();
        }
    }

    private function cancelDelivery(string $id, string $reason): void
    {
        OtpChallenge::whereKey($id)->whereIn('state', ['queued', OtpChallenge::PENDING])
            ->update(['state' => OtpChallenge::CANCELLED, 'verifier' => null, 'updated_at' => now()]);
        OtpDelivery::where('challenge_id', $id)->update(['status' => DeliveryResult::FAILED, 'error_code' => $reason, 'updated_at' => now()]);
    }

    public function status(string $tenant, string $id): array
    {
        $challenge = OtpChallenge::where('tenant_key', $tenant)->findOrFail($id);
        if ($challenge->state === 'queued' && $challenge->expires_at->lte(now())) {
            $lock = Cache::lock('wa-otp:issue:'.$challenge->tenant_key.':'.$challenge->phone_e164, 120);
            if ($lock->get()) {
                try {
                    $challenge->refresh();
                    if ($challenge->state === 'queued' && $challenge->expires_at->lte(now())) {
                        $this->cancelDelivery($id, 'QUEUE_EXPIRED');
                        $challenge->refresh();
                    }
                } finally {
                    $lock->release();
                }
            }
        }

        $delivery = OtpDelivery::where('challenge_id', $id)->first();
        $status = $delivery?->status ?? 'unknown';
        if ($status === 'sending' && $delivery->updated_at->lt(now()->subSeconds(65))) {
            $status = 'unknown';
        }

        return $this->present($challenge, $status);
    }

    private function replay(string $tenant, string $phone, string $idempotencyKey): ?array
    {
        $existing = OtpChallenge::where('tenant_key', $tenant)->where('idempotency_key', $idempotencyKey)->first();
        if (! $existing) {
            return null;
        }
        if ($existing->phone_e164 !== $phone) {
            throw OtpException::make('IDEMPOTENCY_KEY_REUSED', 422);
        }
        $status = OtpDelivery::where('challenge_id', $existing->id)->latest('id')->value('status') ?? DeliveryResult::UNKNOWN;

        return $this->present($existing, $status) + ['replayed' => true];
    }

    private function assertVerifiable(OtpChallenge $challenge): void
    {
        switch ($challenge->state) {
            case 'queued':
                throw new OtpException('OTP_NOT_SENT', 'Your code is queued for sending. Please wait.', 409);
            case OtpChallenge::SUPERSEDED:
                throw OtpException::make('CHALLENGE_SUPERSEDED', 409);
            case OtpChallenge::LOCKED:
                throw OtpException::make('OTP_ATTEMPTS_EXCEEDED', 429);
            case OtpChallenge::EXPIRED:
                throw OtpException::make('OTP_EXPIRED', 401);
            case OtpChallenge::PENDING:
                if ($challenge->expires_at->lte(now())) {
                    $this->transition($challenge->id, OtpChallenge::EXPIRED);
                    throw OtpException::make('OTP_EXPIRED', 401);
                }

                return;
            default:
                throw OtpException::make('OTP_INVALID', 401);
        }
    }

    private function transition(string $id, string $state): void
    {
        OtpChallenge::whereKey($id)->where('state', OtpChallenge::PENDING)
            ->update(['state' => $state, 'verifier' => null, 'updated_at' => now()]);
    }

    /**
     * @return array{user: mixed, created: bool}
     */
    private function resolveUser(string $tenant, string $phone): array
    {
        $userClass = $this->getUserModel();
        $find = function () use ($userClass, $tenant, $phone) {
            $query = method_exists($userClass, 'withTrashed') ? $userClass::withTrashed() : $userClass::query();

            return $query->where('tenant_key', $tenant)->where('phone_e164', $phone)->first();
        };

        $created = false;
        $user = $find();

        if (! $user) {
            try {
                $user = $userClass::create([
                    'tenant_key' => $tenant,
                    'phone_e164' => $phone,
                    'phone_verified_at' => now(),
                    'last_login_at' => now(),
                    'is_active' => true,
                ]);
                $created = true;
                if (class_exists(Role::class) && method_exists($user, 'assignRole')) {
                    if (Role::where('name', 'user')->where('guard_name', 'web')->exists()) {
                        $user->assignRole('user');
                    }
                }
            } catch (UniqueConstraintViolationException) {
                $user = $find();
            }
        }

        if (method_exists($user, 'trashed') && $user->trashed()) {
            $user->restore();
            $user->is_active = true;
            $created = true;
        }

        if (isset($user->is_active) && ! $user->is_active) {
            $this->audit('verify', $tenant, $phone, 'account_disabled');
            throw OtpException::make('ACCOUNT_DISABLED', 403);
        }

        if (! $created) {
            $user->forceFill(['phone_verified_at' => now(), 'last_login_at' => now()])->save();
        } else {
            $this->dispatchVerifiedMemberWebhook($user);
        }

        return ['user' => $user, 'created' => $created];
    }

    private function dispatchVerifiedMemberWebhook(mixed $user): void
    {
        $url = $this->gatewayConfig('webhook.verified_member_url');
        if (empty($url)) {
            return;
        }

        try {
            $payload = [
                'event' => 'member.verified',
                'user_id' => $user->id ?? null,
                'uuid' => $user->uuid ?? null,
                'tenant_key' => $user->tenant_key ?? 'default',
                'phone_e164' => $user->phone_e164 ?? null,
                'phone_verified_at' => optional($user->phone_verified_at)->toIso8601String(),
                'timestamp' => now()->toIso8601String(),
            ];

            $secret = $this->gatewayConfig('webhook.secret');
            $signature = ! empty($secret) ? hash_hmac('sha256', json_encode($payload), $secret) : null;

            $request = Http::timeout(5)->acceptJson();
            if ($signature) {
                $request = $request->withHeaders(['X-Webhook-Signature' => $signature]);
            }

            $request->post($url, $payload);
        } catch (Throwable $e) {
            Log::warning('Failed dispatching verified member webhook: '.$e->getMessage());
        }
    }

    private function present(OtpChallenge $challenge, string $deliveryStatus): array
    {
        return [
            'challenge_id' => $challenge->id,
            'masked_phone' => PhoneNumber::mask($challenge->phone_e164),
            'expires_at' => $challenge->state === 'queued' ? null : $challenge->expires_at->toIso8601String(),
            'expires_in' => $challenge->state === 'queued' ? null : max(0, (int) ceil(now()->diffInSeconds($challenge->expires_at, false))),
            'state' => $challenge->state,
            'server_time' => now()->toIso8601String(),
            'resend_available_at' => $challenge->resend_available_at->toIso8601String(),
            'resend_after' => max(0, (int) ceil(now()->diffInSeconds($challenge->resend_available_at, false))),
            'delivery_status' => $deliveryStatus,
        ];
    }

    private function message(#[\SensitiveParameter] string $code, float $minutes): string
    {
        $template = (string) $this->gatewayConfig(
            'message_template',
            'Your :app sign-in code is :code. Expires in :minutes minutes. Do not share this code.'
        );

        return strtr($template, [
            ':app' => (string) config('app.name'),
            ':code' => $code,
            ':minutes' => (string) max(1, (int) round($minutes)),
        ]);
    }

    private function verifier(string $id, string $tenant, string $phone, string $purpose, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', implode('|', [$id, $tenant, $phone, $purpose, $code]), $this->secret());
    }

    private function secret(): string
    {
        $secret = (string) $this->gatewayConfig('hmac_secret');
        if ($secret !== '') {
            return $secret;
        }
        if (app()->environment('production')) {
            throw new RuntimeException('WA_OTP_HMAC_SECRET must be set in production.');
        }

        return hash('sha256', 'wa-otp|'.config('app.key'));
    }

    private function audit(string $event, string $tenant, ?string $phone, string $outcome, array $extra = []): void
    {
        Log::info('wa_otp.'.$event, [
            'tenant' => $tenant,
            'phone' => $phone ? PhoneNumber::mask($phone) : null,
            'outcome' => $outcome,
            'correlation_id' => request()?->header('X-Request-Id'),
        ] + $extra);
    }
}
