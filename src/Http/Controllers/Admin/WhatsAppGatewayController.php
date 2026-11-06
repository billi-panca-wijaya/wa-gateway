<?php

namespace BilliPancaWijaya\WhatsAppGateway\Http\Controllers\Admin;

use BilliPancaWijaya\WhatsAppGateway\Exceptions\OtpException;
use BilliPancaWijaya\WhatsAppGateway\Services\OpenWaSessionClient;
use BilliPancaWijaya\WhatsAppGateway\Services\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
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
            'transport' => config('whatsapp-gateway.transport', config('whatsapp_otp.transport', 'openwa')),
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
        $defaultRegion = (string) config('whatsapp-gateway.default_region', config('whatsapp_otp.default_region', 'ID'));
        $e164 = PhoneNumber::normalize($data['phone'], $defaultRegion);

        return response()->json(['data' => $this->gateway->pairingCode(ltrim($e164, '+'))]);
    }

    /**
     * Nginx auth_request subrequest guarding /gateway-api and /gateway-socket (embedded OpenWA dashboard).
     */
    public function authorizeDashboard(Request $request): Response
    {
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
