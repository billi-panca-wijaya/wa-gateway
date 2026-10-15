<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controller to receive incoming webhooks when a customer verifies their WhatsApp number.
 */
class WhatsAppMemberWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $rawContent = $request->getContent();
        $secret = config('whatsapp_gateway_client.webhook_secret');

        // 1. Signature Verification
        if (! empty($secret)) {
            $expectedSignature = hash_hmac('sha256', $rawContent, $secret);
            $providedSignature = $request->header('X-Webhook-Signature');

            if (! $providedSignature || ! hash_equals($expectedSignature, $providedSignature)) {
                Log::warning('WhatsApp Webhook rejected: Invalid HMAC signature.');

                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
            }
        }

        $payload = $request->json()->all();
        $event = $payload['event'] ?? null;

        if ($event === 'member.verified') {
            $phoneE164 = $payload['phone_e164'] ?? null;
            $userId = $payload['user_id'] ?? null;
            $uuid = $payload['uuid'] ?? null;
            $tenantKey = $payload['tenant_key'] ?? 'default';
            $verifiedAt = $payload['phone_verified_at'] ?? now()->toIso8601String();

            Log::info("WhatsApp Member Verified Webhook received for {$phoneE164} (UUID: {$uuid})");

            // TODO: Lakukan sinkronisasi atau update member di database Back-Office Agent:
            // Contoh:
            // Member::updateOrCreate(
            //     ['phone_e164' => $phoneE164],
            //     ['wa_verified_at' => $verifiedAt, 'gateway_user_id' => $userId, 'tenant_key' => $tenantKey]
            // );

            return response()->json([
                'status' => 'success',
                'message' => 'Webhook processed successfully',
                'processed_at' => now()->toIso8601String(),
            ]);
        }

        return response()->json(['status' => 'ignored', 'message' => "Unhandled event: {$event}"]);
    }
}
