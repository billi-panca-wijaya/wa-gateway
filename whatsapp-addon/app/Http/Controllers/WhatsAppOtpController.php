<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\WhatsAppOtp\OtpException;
use App\Services\WhatsAppOtp\OtpService;
use App\Services\WhatsAppOtp\PhoneNumber;
use App\Services\WhatsAppOtp\TenantResolver;
use App\Services\WhatsAppOtp\WhatsAppBotChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WhatsAppOtpController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly TenantResolver $tenants,
    ) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'purpose' => ['sometimes', 'string', 'in:login'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        $this->authorizeBot($request, 'otp_request', PhoneNumber::normalize($data['phone'], (string) config('whatsapp_otp.default_region', 'ID')));
        $payload = $this->otp->request(
            $this->tenants->resolve($request),
            $data['phone'],
            $this->idempotencyKey($request, $data),
            (string) $request->ip(),
        );

        $ids = $request->session()->get('whatsapp_otp_challenges', []);
        $request->session()->put('whatsapp_otp_challenges', array_slice(array_unique([...$ids, $payload['challenge_id']]), -10));

        return response()->json($payload, $payload['delivery_status'] === 'queued' ? 202 : 200);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string', 'max:64'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        $this->authorizeBot($request, 'otp_resend', $data['challenge_id']);
        abort_unless(in_array($data['challenge_id'], $request->session()->get('whatsapp_otp_challenges', []), true), 404);
        $payload = $this->otp->resend(
            $this->tenants->resolve($request),
            $data['challenge_id'],
            $this->idempotencyKey($request, $data),
            (string) $request->ip(),
        );

        $ids = $request->session()->get('whatsapp_otp_challenges', []);
        $request->session()->put('whatsapp_otp_challenges', array_slice(array_unique([...$ids, $payload['challenge_id']]), -10));

        return response()->json($payload, $payload['delivery_status'] === 'queued' ? 202 : 200);
    }

    private function authorizeBot(Request $request, string $action, string $identity): void
    {
        if (! $request->hasSession()) {
            throw new OtpException('STATEFUL_ORIGIN_REQUIRED', 'Request must originate from a registered web application.', 400);
        }
        app(WhatsAppBotChallenge::class)->verify($request, $action, $identity);
    }

    public function challengeConfig(): JsonResponse
    {
        $enabled = (bool) config('whatsapp_otp.turnstile.enabled', true);
        if (! $enabled && ! app()->environment(['local', 'testing'])) {
            throw new OtpException('BOT_CHECK_UNAVAILABLE', 'Verification is temporarily unavailable. Please try again.', 503);
        }

        return response()->json(['enabled' => $enabled, 'site_key' => $enabled ? config('whatsapp_otp.turnstile.site_key') : null])->header('Cache-Control', 'no-store');
    }

    public function deliveryStatus(Request $request, string $id): JsonResponse
    {
        abort_unless($request->hasSession() && in_array($id, $request->session()->get('whatsapp_otp_challenges', []), true), 404);

        return response()->json($this->otp->status($this->tenants->resolve($request), $id))->header('Cache-Control', 'no-store');
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $codeLength = 4;
        $data = $request->validate([
            'challenge_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'regex:/^\d{'.$codeLength.'}$/D'],
        ]);

        if (! $request->hasSession()) {
            throw new OtpException('STATEFUL_ORIGIN_REQUIRED', 'Request must originate from a registered web application.', 400);
        }

        $result = $this->otp->verify(
            $this->tenants->resolve($request),
            $data['challenge_id'],
            $data['code'],
            (string) $request->ip(),
        );

        Auth::guard('web')->login($result['user']);
        $request->session()->regenerate();

        $user = $result['user'];

        return response()->json([
            'authenticated' => true,
            'user' => self::presentUser($user),
            'onboarding_required' => $user->onboarding_required,
            'next' => $user->onboarding_required ? '/onboarding' : '/dashboard',
        ]);
    }

    public static function presentUser(User $user): array
    {
        return [
            'id' => $user->uuid,
            'login_id' => $user->login_id,
            'phone_e164' => $user->phone_e164,
            'name' => $user->name,
            'onboarding_required' => $user->onboarding_required,
        ];
    }

    private function idempotencyKey(Request $request, array $data): ?string
    {
        $key = $request->header('Idempotency-Key') ?? ($data['idempotency_key'] ?? null);
        if ($key !== null && ! preg_match('/^[\x21-\x7E]{1,128}$/', $key)) {
            throw new OtpException('IDEMPOTENCY_KEY_INVALID', 'Invalid idempotency key.', 422);
        }

        return $key;
    }
}
