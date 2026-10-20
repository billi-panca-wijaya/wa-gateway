<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppOtp\OtpException;
use App\Services\WhatsAppOtp\PhoneNumber;
use App\Services\WhatsAppOtp\SenderPoolService;
use App\Services\WhatsAppOtp\Transport\DeliveryResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Controller for managing the WhatsApp Sender Pool (multiple sender numbers).
 * Accessible by admins & super-admins.
 */
class SenderPoolController extends Controller
{
    public function __construct(private readonly SenderPoolService $poolService) {}

    public function index(Request $request): JsonResponse
    {
        $tenantKey = $request->query('tenant_key') ?? config('whatsapp_otp.default_tenant', 'default');
        $senders = $this->poolService->listSenders($tenantKey);

        return response()->json([
            'status' => 'success',
            'data' => $senders,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantKey = $request->input('tenant_key') ?? config('whatsapp_otp.default_tenant', 'default');

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
            'message' => 'Nomor pengirim berhasil ditambahkan ke pool.',
            'data' => $sender,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $tenantKey = $request->query('tenant_key') ?? config('whatsapp_otp.default_tenant', 'default');
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
        $tenantKey = $request->input('tenant_key') ?? config('whatsapp_otp.default_tenant', 'default');

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:32'],
            'label' => ['nullable', 'string', 'max:128'],
            'status' => ['nullable', 'string', Rule::in(['active', 'suspended', 'disconnected'])],
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);

        $sender = $this->poolService->updateSender($tenantKey, $id, $data);

        return response()->json([
            'status' => 'success',
            'message' => 'Data pengirim berhasil diperbarui.',
            'data' => $sender,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantKey = $request->query('tenant_key') ?? config('whatsapp_otp.default_tenant', 'default');
        $deleteInOpenWa = $request->boolean('delete_session', false);

        $this->poolService->deleteSender($tenantKey, $id, $deleteInOpenWa);

        return response()->json([
            'status' => 'success',
            'message' => 'Nomor pengirim berhasil dihapus dari pool.',
        ]);
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $tenantKey = $request->input('tenant_key') ?? config('whatsapp_otp.default_tenant', 'default');
        $sender = $this->poolService->toggleStatus($tenantKey, $id);

        return response()->json([
            'status' => 'success',
            'message' => "Status nomor diubah menjadi {$sender->status}.",
            'data' => $sender,
        ]);
    }

    public function sync(Request $request, int $id): JsonResponse
    {
        $tenantKey = $request->input('tenant_key') ?? config('whatsapp_otp.default_tenant', 'default');
        $sender = $this->poolService->getSender($tenantKey, $id);
        $status = $this->poolService->syncSenderStatus($sender);

        return response()->json([
            'status' => 'success',
            'message' => 'Status berhasil disinkronkan dengan gateway.',
            'data' => [
                'sender' => $sender->fresh(),
                'gateway' => $status,
            ],
        ]);
    }

    public function start(Request $request, int $id): JsonResponse
    {
        $tenantKey = $request->input('tenant_key') ?? config('whatsapp_otp.default_tenant', 'default');
        $sender = $this->poolService->getSender($tenantKey, $id);
        $result = $this->poolService->startSession($sender);

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    public function stop(Request $request, int $id): JsonResponse
    {
        $tenantKey = $request->input('tenant_key') ?? config('whatsapp_otp.default_tenant', 'default');
        $sender = $this->poolService->getSender($tenantKey, $id);
        $result = $this->poolService->stopSession($sender);

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    public function logout(Request $request, int $id): JsonResponse
    {
        $tenantKey = $request->input('tenant_key') ?? config('whatsapp_otp.default_tenant', 'default');
        $sender = $this->poolService->getSender($tenantKey, $id);
        $result = $this->poolService->logoutSession($sender);

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    public function qr(Request $request, int $id): JsonResponse
    {
        $tenantKey = $request->query('tenant_key') ?? config('whatsapp_otp.default_tenant', 'default');
        $sender = $this->poolService->getSender($tenantKey, $id);
        $qr = $this->poolService->getQrCode($sender);

        return response()->json(['status' => 'success', 'data' => $qr]);
    }

    public function pairingCode(Request $request, int $id): JsonResponse
    {
        $tenantKey = $request->input('tenant_key') ?? config('whatsapp_otp.default_tenant', 'default');
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
        $tenantKey = $request->input('tenant_key') ?? config('whatsapp_otp.default_tenant', 'default');
        $sender = $this->poolService->getSender($tenantKey, $id);

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $e164 = PhoneNumber::normalize($data['phone'], (string) config('whatsapp_otp.default_region', 'ID'));
        $msg = $data['message'] ?? 'Tes pengiriman pesan dari pool sender: '.$sender->session_id;

        $delivery = $this->poolService->testSend($sender, $e164, $msg);

        if ($delivery->status === DeliveryResult::FAILED) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pengiriman pesan gagal: '.($delivery->errorCode ?? 'UNKNOWN'),
                'data' => [
                    'delivery_status' => $delivery->status,
                    'error_code' => $delivery->errorCode,
                ],
            ], 502);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Pesan uji berhasil dikirim ke gateway.',
            'data' => [
                'delivery_status' => $delivery->status,
                'message_id' => $delivery->providerMessageId,
            ],
        ]);
    }
}
