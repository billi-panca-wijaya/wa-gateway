<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WhatsAppOtp\OtpException;
use App\Services\WhatsAppOtp\OtpService;
use App\Services\WhatsAppOtp\TenantResolver;
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

        $payload = $this->otp->request(
            $this->tenants->resolve($request),
            $data['phone'],
            $this->idempotencyKey($request, $data),
            (string) $request->ip(),
        );

        return response()->json($payload);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string', 'max:64'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        $payload = $this->otp->resend(
            $this->tenants->resolve($request),
            $data['challenge_id'],
            $this->idempotencyKey($request, $data),
            (string) $request->ip(),
        );

        return response()->json($payload);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $codeLength = (int) config('whatsapp_otp.code_length', 6);
        $data = $request->validate([
            'challenge_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'regex:/^\d{' . $codeLength . '}$/'],
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
