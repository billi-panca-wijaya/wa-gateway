<?php

namespace BilliPancaWijaya\WhatsAppGateway\Http\Controllers;

use BilliPancaWijaya\WhatsAppGateway\Exceptions\OtpException;
use BilliPancaWijaya\WhatsAppGateway\Services\OtpService;
use BilliPancaWijaya\WhatsAppGateway\Services\PhoneNumber;
use BilliPancaWijaya\WhatsAppGateway\Services\TenantResolver;
use BilliPancaWijaya\WhatsAppGateway\Services\WhatsAppBotChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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

        $defaultRegion = (string) config('whatsapp-gateway.default_region', config('whatsapp_otp.default_region', 'ID'));
        $this->authorizeBot($request, 'otp_request', PhoneNumber::normalize($data['phone'], $defaultRegion));

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
        $enabled = (bool) config('whatsapp-gateway.turnstile.enabled', config('whatsapp_otp.turnstile.enabled', true));
        if (! $enabled && ! app()->environment(['local', 'testing'])) {
            throw new OtpException('BOT_CHECK_UNAVAILABLE', 'Verification is temporarily unavailable. Please try again.', 503);
        }

        $siteKey = config('whatsapp-gateway.turnstile.site_key', config('whatsapp_otp.turnstile.site_key'));

        return response()->json([
            'enabled' => $enabled,
            'site_key' => $enabled ? $siteKey : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function deliveryStatus(Request $request, string $id): JsonResponse
    {
        abort_unless($request->hasSession() && in_array($id, $request->session()->get('whatsapp_otp_challenges', []), true), 404);

        return response()->json($this->otp->status($this->tenants->resolve($request), $id))->header('Cache-Control', 'no-store');
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $codeLength = (int) config('whatsapp-gateway.code_length', config('whatsapp_otp.code_length', 4));
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

        $guard = config('whatsapp-gateway.guard', 'web');
        Auth::guard($guard)->login($result['user']);
        $request->session()->regenerate();

        $user = $result['user'];
        $onboarding = (bool) ($user->onboarding_required ?? false);

        return response()->json([
            'authenticated' => true,
            'user' => self::presentUser($user),
            'onboarding_required' => $onboarding,
            'next' => $onboarding ? '/onboarding' : '/dashboard',
        ]);
    }

    public static function presentUser(mixed $user): array
    {
        return [
            'id' => $user->uuid ?? $user->id,
            'login_id' => $user->login_id ?? $user->phone_e164 ?? ($user->email ?? null),
            'phone_e164' => $user->phone_e164 ?? null,
            'name' => $user->name ?? null,
            'onboarding_required' => (bool) ($user->onboarding_required ?? false),
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
