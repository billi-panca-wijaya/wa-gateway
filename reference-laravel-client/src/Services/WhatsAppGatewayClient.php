<?php

namespace App\Services\WhatsAppGateway;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Production-ready HTTP Client for communicating with the WhatsApp API Gateway & Sender Pool.
 * Suitable for inclusion in Laravel Back-Office projects.
 */
class WhatsAppGatewayClient
{
    private string $baseUrl;
    private string $token;
    private int $timeout;
    private int $connectTimeout;
    private string $defaultTenant;

    public function __construct(array $config = [])
    {
        $this->baseUrl = rtrim($config['base_url'] ?? (string) config('whatsapp_gateway_client.base_url', 'http://localhost:8000'), '/');
        $this->token = $config['token'] ?? (string) config('whatsapp_gateway_client.token', '');
        $this->timeout = (int) ($config['timeout'] ?? config('whatsapp_gateway_client.timeout', 15));
        $this->connectTimeout = (int) ($config['connect_timeout'] ?? config('whatsapp_gateway_client.connect_timeout', 5));
        $this->defaultTenant = $config['default_tenant'] ?? (string) config('whatsapp_gateway_client.default_tenant', 'default');
    }

    /*
    |--------------------------------------------------------------------------
    | SENDER POOL MANAGEMENT
    |--------------------------------------------------------------------------
    */

    /**
     * List all sender numbers currently in the pool.
     */
    public function listSenders(?string $tenantKey = null): array
    {
        $tenant = $tenantKey ?? $this->defaultTenant;
        $response = $this->call('GET', "/api/v1/admin/sender-pool?tenant_key={$tenant}");

        return $response->json('data') ?? [];
    }

    /**
     * Add a new sender number to the pool.
     */
    public function addSender(string $sessionId, ?string $label = null, ?string $apiKey = null, bool $autoCreate = true, ?string $tenantKey = null): array
    {
        $response = $this->call('POST', '/api/v1/admin/sender-pool', [
            'tenant_key' => $tenantKey ?? $this->defaultTenant,
            'session_id' => $sessionId,
            'label' => $label,
            'api_key' => $apiKey,
            'auto_create_session' => $autoCreate,
        ]);

        return $response->json('data') ?? [];
    }

    /**
     * Get details and live gateway status for a specific sender.
     */
    public function getSender(int $id, ?string $tenantKey = null): array
    {
        $tenant = $tenantKey ?? $this->defaultTenant;
        $response = $this->call('GET', "/api/v1/admin/sender-pool/{$id}?tenant_key={$tenant}");

        return $response->json('data') ?? [];
    }

    /**
     * Update label, status or API key for a sender.
     */
    public function updateSender(int $id, array $data, ?string $tenantKey = null): array
    {
        $data['tenant_key'] = $tenantKey ?? $this->defaultTenant;
        $response = $this->call('PUT', "/api/v1/admin/sender-pool/{$id}", $data);

        return $response->json('data') ?? [];
    }

    /**
     * Remove a sender from the pool.
     */
    public function deleteSender(int $id, bool $deleteInGateway = false, ?string $tenantKey = null): bool
    {
        $tenant = $tenantKey ?? $this->defaultTenant;
        $url = "/api/v1/admin/sender-pool/{$id}?tenant_key={$tenant}&delete_session=".($deleteInGateway ? '1' : '0');
        $response = $this->call('DELETE', $url);

        return $response->successful();
    }

    /**
     * Toggle active <-> suspended status for a sender.
     */
    public function toggleSenderStatus(int $id, ?string $tenantKey = null): array
    {
        $response = $this->call('POST', "/api/v1/admin/sender-pool/{$id}/toggle", [
            'tenant_key' => $tenantKey ?? $this->defaultTenant,
        ]);

        return $response->json('data') ?? [];
    }

    /**
     * Sync sender status and phone number from the gateway.
     */
    public function syncSender(int $id, ?string $tenantKey = null): array
    {
        $response = $this->call('POST', "/api/v1/admin/sender-pool/{$id}/sync", [
            'tenant_key' => $tenantKey ?? $this->defaultTenant,
        ]);

        return $response->json('data') ?? [];
    }

    /**
     * Retrieve QR code data (base64 image) for pairing.
     */
    public function getQrCode(int $id, ?string $tenantKey = null): array
    {
        $tenant = $tenantKey ?? $this->defaultTenant;
        $response = $this->call('GET', "/api/v1/admin/sender-pool/{$id}/qr?tenant_key={$tenant}");

        return $response->json('data') ?? [];
    }

    /**
     * Request 8-digit pairing code for phone number.
     */
    public function requestPairingCode(int $id, string $phoneNumber, ?string $tenantKey = null): array
    {
        $response = $this->call('POST', "/api/v1/admin/sender-pool/{$id}/pairing-code", [
            'tenant_key' => $tenantKey ?? $this->defaultTenant,
            'phone' => $phoneNumber,
        ]);

        return $response->json('data') ?? [];
    }

    /**
     * Send a test text message from a specific sender.
     */
    public function testSend(int $id, string $phone, ?string $message = null, ?string $tenantKey = null): array
    {
        $response = $this->call('POST', "/api/v1/admin/sender-pool/{$id}/test-send", [
            'tenant_key' => $tenantKey ?? $this->defaultTenant,
            'phone' => $phone,
            'message' => $message,
        ]);

        return $response->json('data') ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFIED MEMBERS SYNC
    |--------------------------------------------------------------------------
    */

    /**
     * Fetch paginated list of verified members with optional filters.
     */
    public function getVerifiedMembers(int $page = 1, int $perPage = 20, ?string $search = null, ?string $dateFrom = null, ?string $dateTo = null, ?string $tenantKey = null): array
    {
        $params = array_filter([
            'page' => $page,
            'per_page' => $perPage,
            'search' => $search,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'tenant_key' => $tenantKey ?? $this->defaultTenant,
        ]);

        $query = http_build_query($params);
        $response = $this->call('GET', "/api/v1/admin/verified-members?{$query}");

        return $response->json() ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | INTERNAL HTTP HELPER
    |--------------------------------------------------------------------------
    */

    private function call(string $method, string $path, array $data = []): Response
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        try {
            $req = Http::baseUrl($this->baseUrl)
                ->acceptJson()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout);

            if (! empty($this->token)) {
                $req = $req->withToken($this->token);
            }

            $response = match (strtoupper($method)) {
                'GET' => $req->get($path),
                'POST' => $req->post($path, $data),
                'PUT' => $req->put($path, $data),
                'DELETE' => $req->delete($path, $data),
                default => throw new RuntimeException("Unsupported HTTP method: {$method}"),
            };
        } catch (ConnectionException $e) {
            throw new RuntimeException("Gagal menghubungi WhatsApp Gateway di {$url}: " . $e->getMessage(), 503, $e);
        }

        if ($response->failed()) {
            $msg = $response->json('message') ?? 'Gateway API error: ' . $response->status();
            throw new RuntimeException((string) $msg, $response->status());
        }

        return $response;
    }
}
