<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppOtp\OpenWaSessionClient;
use App\Services\WhatsAppOtp\OtpException;
use App\Services\WhatsAppOtp\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Website-admin view of the OTP sender number (OpenWA session). Admin roles only.
 * Customers never reach these routes; the gateway API key never leaves the server.
 */
class WhatsAppGatewayController extends Controller
{
    public function __construct(private readonly OpenWaSessionClient $gateway) {}

    public function status(): JsonResponse
    {
        $data = [
            'transport' => config('whatsapp_otp.transport'),
            'configured' => $this->gateway->configured(),
            'reachable' => false,
            'session' => null,
            'error' => null,
        ];

        if ($data['configured']) {
            try {
                $data['session'] = $this->gateway->status();
                $data['reachable'] = true;
            } catch (OtpException $e) {
                $data['reachable'] = $e->errorCode !== 'GATEWAY_UNREACHABLE';
                $data['error'] = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            }
        }

        return response()->json(['data' => $data]);
    }

    public function start(): JsonResponse
    {
        return response()->json(['data' => $this->gateway->start()]);
    }

    public function stop(): JsonResponse
    {
        return response()->json(['data' => $this->gateway->stop()]);
    }

    public function logout(): JsonResponse
    {
        return response()->json(['data' => $this->gateway->logout()]);
    }

    public function qr(): JsonResponse
    {
        return response()->json(['data' => $this->gateway->qr()]);
    }

    public function pairingCode(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        $e164 = PhoneNumber::normalize($data['phone'], (string) config('whatsapp_otp.default_region', 'ID'));

        return response()->json(['data' => $this->gateway->pairingCode(ltrim($e164, '+'))]);
    }

    /**
     * nginx auth_request subrequest guarding /gateway-api and /gateway-socket (embedded OpenWA
     * dashboard). Route middleware already enforces session + admin role. Because nginx then injects
     * the gateway key, cross-site requests are refused: a foreign Origin (CSRF / cross-site WebSocket)
     * or a state-changing call without the XHR header the embedded dashboard always sends.
     */
    public function authorizeDashboard(Request $request): \Illuminate\Http\Response
    {
        // Same list Sanctum trusts for cookie sessions (host[:port]); Host may be rewritten by Apache.
        $origin = $request->headers->get('Origin');
        if ($origin !== null && ! Str::is(config('sanctum.stateful', []), preg_replace('#^https?://#', '', $origin))) {
            abort(403);
        }

        $method = strtoupper((string) $request->headers->get('X-Original-Method', 'GET'));
        if (! in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)
            && $request->headers->get('X-Requested-With') !== 'XMLHttpRequest') {
            abort(403);
        }

        return response()->noContent();
    }
}
