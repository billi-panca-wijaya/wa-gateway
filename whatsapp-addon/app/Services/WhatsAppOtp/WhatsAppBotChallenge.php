<?php

namespace App\Services\WhatsAppOtp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class WhatsAppBotChallenge
{
    public function verify(Request $request, string $action, string $identity): void
    {
        if (! config('whatsapp_otp.turnstile.enabled', true)) {
            if (! app()->environment(['local', 'testing'])) {
                throw new OtpException('BOT_CHECK_UNAVAILABLE', 'Verification is temporarily unavailable. Please try again.', 503);
            }

            return;
        }
        $data = $request->validate(['challenge_token' => ['required', 'string', 'max:2048']]);
        $secret = (string) config('whatsapp_otp.turnstile.secret_key');
        $hosts = array_filter(array_map('trim', explode(',', (string) config('whatsapp_otp.turnstile.hostnames'))));
        if ($secret === '' || $hosts === []) {
            throw new OtpException('BOT_CHECK_UNAVAILABLE', 'Verification is temporarily unavailable. Please try again.', 503);
        }
        $key = 'wa-otp:bot:'.hash('sha256', $data['challenge_token']);
        $binding = hash('sha256', implode('|', [$request->session()->getId(), $request->getHost(), $action, $identity, (string) $request->header('Idempotency-Key', $request->input('idempotency_key', ''))]));
        $lock = Cache::lock($key.':lock', 10);
        if (! $lock->get()) {
            throw new OtpException('REQUEST_IN_PROGRESS', 'Verification is already in progress. Please retry.', 409);
        }
        try {
            $accepted = Cache::get($key);
            if ($accepted !== null) {
                if ((string) $request->header('Idempotency-Key', $request->input('idempotency_key', '')) !== '' && is_string($accepted) && hash_equals($accepted, $binding)) {
                    return;
                }
                throw new OtpException('BOT_CHECK_FAILED', 'Verification expired or was already used. Please try again.', 422);
            }
            try {
                $response = Http::asForm()->connectTimeout(2)->timeout(5)
                    ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                        'secret' => $secret,
                        'response' => $data['challenge_token'],
                    ]);
            } catch (Throwable) {
                throw new OtpException('BOT_CHECK_UNAVAILABLE', 'Verification is temporarily unavailable. Please try again.', 503);
            }
            if (! $response->successful()) {
                throw new OtpException('BOT_CHECK_UNAVAILABLE', 'Verification is temporarily unavailable. Please try again.', 503);
            }
            if ($response->json('success') !== true || $response->json('action') !== $action || ! in_array($response->json('hostname'), $hosts, true)) {
                throw new OtpException('BOT_CHECK_FAILED', 'Verification failed. Please try again.', 422);
            }
            // An exact HTTP retry may reuse its authorization, never a new action or number.
            Cache::put($key, $binding, 300);
        } finally {
            $lock->release();
        }
    }
}
