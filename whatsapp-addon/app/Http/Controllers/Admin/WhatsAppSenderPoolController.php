<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppOtp\OtpException;
use App\Services\WhatsAppOtp\PhoneNumber;
use App\Services\WhatsAppOtp\SenderPoolService;
use App\Services\WhatsAppOtp\TenantResolver;
use App\Services\WhatsAppOtp\Transport\DeliveryResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Controller for managing the WhatsApp Sender Pool (multiple sender numbers).
 * Accessible by admins & super-admins.
 */
class WhatsAppSenderPoolController extends Controller
{
    public function __construct(private readonly SenderPoolService $poolService) {}

    public function index(Request $request): JsonResponse
    {
        $tenantKey = app(TenantResolver::class)->forAdmin($request);
        $senders = $this->poolService->listSenders($tenantKey);

        return response()->json([
            'status' => 'success',
            'data' => $senders,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantKey = app(TenantResolver::class)->forAdmin($request);

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:32', 'required_without:session_id'],
            'session_id' => ['nullable', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/', 'required_without:phone'],
            'label' => ['nullable', 'string', 'max:128'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'auto_create_session' => ['nullable', 'boolean'],
        ]);

        $sender = $this->poolService->addSender(
            $tenantKey,
            $data,
            (bool) ($data['auto_create_session'] ?? false)
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Sender number successfully added to the pool.',
            'data' => $sender,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $tenantKey = app(TenantResolver::class)->forAdmin($request);
        $sender = $this->poolService->getSender($tenantKey, $id);

        $liveStatus = null;
        try {
            $liveStatus = $this->poolService->syncSenderStatus($sender);
        } catch (\Throwable $e) {
            $liveStatus = ['error' => $e->getMessage()];
        }

        return response()->json([
            'status' => 'success',
            'data' => array_merge($sender->toArray(), [
                'live_status' => $liveStatus,
            ]),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenantKey = app(TenantResolver::class)->forAdmin($request);

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:32'],
            'label' => ['nullable', 'string', 'max:128'],
            'status' => ['nullable', 'string', Rule::in(['active', 'suspended', 'disconnected'])],
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);

        $sender = $this->poolService->updateSender($tenantKey, $id, $data);

        return response()->json([
            'status' => 'success',
            'message' => 'Sender details successfully updated.',
            'data' => $sender,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantKey = app(TenantResolver::class)->forAdmin($request);
        $deleteInOpenWa = $request->boolean('delete_session', false);

        $this->poolService->deleteSender($tenantKey, $id, $deleteInOpenWa);

        return response()->json([
            'status' => 'success',
            'message' => 'Sender number successfully removed from pool.',
        ]);
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $tenantKey = app(TenantResolver::class)->forAdmin($request);
        $sender = $this->poolService->toggleStatus($tenantKey, $id);

        return response()->json([
            'status' => 'success',
            'message' => "Sender status updated to {$sender->status}.",
            'data' => $sender,
        ]);
    }

    public function sync(Request $request, int $id): JsonResponse
    {
        $tenantKey = app(TenantResolver::class)->forAdmin($request);
        $sender = $this->poolService->getSender($tenantKey, $id);
        $status = $this->poolService->syncSenderStatus($sender);

        return response()->json([
            'status' => 'success',
            'message' => 'Status successfully synchronized with gateway.',
            'data' => [
                'sender' => $sender->fresh(),
                'gateway' => $status,
            ],
        ]);
    }

    public function start(Request $request, int $id): JsonResponse
    {
        $tenantKey = app(TenantResolver::class)->forAdmin($request);
        $sender = $this->poolService->getSender($tenantKey, $id);
        $result = $this->poolService->startSession($sender);

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    public function stop(Request $request, int $id): JsonResponse
    {
        $tenantKey = app(TenantResolver::class)->forAdmin($request);
        $sender = $this->poolService->getSender($tenantKey, $id);
        $result = $this->poolService->stopSession($sender);

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    public function logout(Request $request, int $id): JsonResponse
    {
        $tenantKey = app(TenantResolver::class)->forAdmin($request);
        $sender = $this->poolService->getSender($tenantKey, $id);
        $result = $this->poolService->logoutSession($sender);

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    public function qr(Request $request, int $id): JsonResponse
    {
        $tenantKey = app(TenantResolver::class)->forAdmin($request);
        $sender = $this->poolService->getSender($tenantKey, $id);
        $qr = $this->poolService->getQrCode($sender);

        return response()->json(['status' => 'success', 'data' => $qr]);
    }

    public function pairingCode(Request $request, int $id): JsonResponse
    {
        $tenantKey = app(TenantResolver::class)->forAdmin($request);
        $sender = $this->poolService->getSender($tenantKey, $id);

        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        $rawPhone = trim($data['phone']);
        try {
            $e164 = PhoneNumber::normalize($rawPhone, (string) config('whatsapp_otp.default_region', 'ID'));
        } catch (OtpException $e) {
            $digitsOnly = preg_replace('/\D/', '', $rawPhone);
            if ($digitsOnly !== '' && ! str_starts_with($digitsOnly, '0')) {
                $e164 = PhoneNumber::normalize('+'.$digitsOnly, (string) config('whatsapp_otp.default_region', 'ID'));
            } else {
                throw $e;
            }
        }
        $digits = ltrim($e164, '+');

        $result = $this->poolService->getPairingCode($sender, $digits);

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    public function testSend(Request $request, int $id): JsonResponse
    {
        $tenantKey = app(TenantResolver::class)->forAdmin($request);
        $sender = $this->poolService->getSender($tenantKey, $id);

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $e164 = PhoneNumber::normalize($data['phone'], (string) config('whatsapp_otp.default_region', 'ID'));
        $msg = $data['message'] ?? 'Test message from sender pool: '.$sender->session_id;

        $delivery = $this->poolService->testSend($sender, $e164, $msg);

        if ($delivery->status === DeliveryResult::FAILED) {
            return response()->json([
                'status' => 'error',
                'message' => 'Message delivery failed: '.($delivery->errorCode ?? 'UNKNOWN'),
                'data' => [
                    'delivery_status' => $delivery->status,
                    'error_code' => $delivery->errorCode,
                ],
            ], 502);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Test message sent successfully to gateway.',
            'data' => [
                'delivery_status' => $delivery->status,
                'message_id' => $delivery->providerMessageId,
            ],
        ]);
    }
}
