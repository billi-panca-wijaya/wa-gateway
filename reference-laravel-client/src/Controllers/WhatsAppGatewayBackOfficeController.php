<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppGateway\WhatsAppGatewayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Example Back-Office Controller consuming the WhatsApp Gateway & Sender Pool API.
 */
class WhatsAppGatewayBackOfficeController extends Controller
{
    public function __construct(private readonly WhatsAppGatewayClient $gateway) {}

    public function listSenders(Request $request): JsonResponse
    {
        try {
            $senders = $this->gateway->listSenders($request->query('tenant_key'));

            return response()->json(['status' => 'success', 'data' => $senders]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 502);
        }
    }

    public function addSender(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|string|max:128',
            'label' => 'nullable|string|max:128',
            'api_key' => 'nullable|string',
            'auto_create' => 'nullable|boolean',
        ]);

        try {
            $sender = $this->gateway->addSender(
                $validated['session_id'],
                $validated['label'] ?? null,
                $validated['api_key'] ?? null,
                (bool) ($validated['auto_create'] ?? true),
                $request->input('tenant_key')
            );

            return response()->json(['status' => 'success', 'data' => $sender], 201);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function toggleSender(Request $request, int $id): JsonResponse
    {
        try {
            $sender = $this->gateway->toggleSenderStatus($id, $request->input('tenant_key'));

            return response()->json(['status' => 'success', 'data' => $sender]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 502);
        }
    }

    public function getQrCode(Request $request, int $id): JsonResponse
    {
        try {
            $qr = $this->gateway->getQrCode($id, $request->query('tenant_key'));

            return response()->json(['status' => 'success', 'data' => $qr]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 502);
        }
    }

    public function requestPairingCode(Request $request, int $id): JsonResponse
    {
        $request->validate(['phone' => 'required|string']);

        try {
            $result = $this->gateway->requestPairingCode($id, $request->input('phone'), $request->input('tenant_key'));

            return response()->json(['status' => 'success', 'data' => $result]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 502);
        }
    }

    public function testSend(Request $request, int $id): JsonResponse
    {
        $request->validate(['phone' => 'required|string']);

        try {
            $result = $this->gateway->testSend(
                $id,
                $request->input('phone'),
                $request->input('message'),
                $request->input('tenant_key')
            );

            return response()->json(['status' => 'success', 'data' => $result]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 502);
        }
    }

    public function verifiedMembers(Request $request): JsonResponse
    {
        try {
            $page = (int) $request->query('page', 1);
            $perPage = (int) $request->query('per_page', 20);
            $search = $request->query('search');
            $dateFrom = $request->query('date_from');
            $dateTo = $request->query('date_to');

            $data = $this->gateway->getVerifiedMembers(
                $page,
                $perPage,
                $search,
                $dateFrom,
                $dateTo,
                $request->query('tenant_key')
            );

            return response()->json($data);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 502);
        }
    }
}
