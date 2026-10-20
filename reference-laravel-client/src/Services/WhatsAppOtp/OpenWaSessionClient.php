<?php

namespace App\Services\WhatsAppOtp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Server-side management of OTP sender sessions on OpenWA for the website admin panel.
 * Supports single session (backward compatible) and multi-session pool.
 * Uses OPERATOR keys scoped to sender sessions; no master key required.
 */
class OpenWaSessionClient
{
    public function __construct(private array $config) {}

    /**
     * Create a clone configured for a specific session and optional API key.
     */
    public function forSession(string $sessionId, ?string $apiKey = null): self
    {
        $cfg = $this->config;
        $cfg['session_id'] = $sessionId;
        if (! empty($apiKey)) {
            $cfg['api_key'] = $apiKey;
        }

        return new self($cfg);
    }

    public function configured(?string $sessionId = null, ?string $apiKey = null): bool
    {
        $base = $this->config['base_url'] ?? null;
        $key = $this->resolveKey($sessionId, $apiKey);
        $session = $sessionId ?? ($this->config['session_id'] ?? null);

        return ! empty($base) && ! empty($key) && ! empty($session);
    }

    /**
     * Picks the best API key: explicit per-sender key first, then the unscoped admin key when
     * managing a non-default pool session, then the default operator key.
     */
    private function resolveKey(?string $sessionId = null, ?string $apiKey = null, bool $preferAdmin = false): ?string
    {
        if (! empty($apiKey)) {
            return $apiKey;
        }
        $defaultSession = $this->config['session_id'] ?? null;
        $adminKey = $this->config['admin_api_key'] ?? null;
        if (($preferAdmin || ($sessionId !== null && $sessionId !== $defaultSession)) && ! empty($adminKey)) {
            return $adminKey;
        }

        return $this->config['api_key'] ?? $adminKey;
    }

    public function status(?string $sessionId = null, ?string $apiKey = null): array
    {
        return $this->session($this->call('get', '', [], 15, $sessionId, $apiKey));
    }

    public function start(?string $sessionId = null, ?string $apiKey = null): array
    {
        return $this->session($this->call('post', '/start', [], 60, $sessionId, $apiKey));
    }

    public function stop(?string $sessionId = null, ?string $apiKey = null): array
    {
        return $this->session($this->call('post', '/stop', [], 60, $sessionId, $apiKey));
    }

    public function logout(?string $sessionId = null, ?string $apiKey = null): array
    {
        return $this->session($this->call('post', '/logout', [], 60, $sessionId, $apiKey));
    }

    public function qr(?string $sessionId = null, ?string $apiKey = null): array
    {
        $json = $this->call('get', '/qr', [], 15, $sessionId, $apiKey)->json();

        return ['qr_code' => $json['qrCode'] ?? null, 'status' => $json['status'] ?? null];
    }

    public function pairingCode(string $phoneDigits, ?string $sessionId = null, ?string $apiKey = null): array
    {
        $json = $this->call('post', '/pairing-code', ['phoneNumber' => $phoneDigits], 30, $sessionId, $apiKey)->json();

        return ['pairing_code' => $json['pairingCode'] ?? null, 'status' => $json['status'] ?? null];
    }

    /**
     * List all sessions from OpenWA engine.
     */
    public function listSessions(?string $apiKey = null): array
    {
        $key = $this->resolveKey(null, $apiKey, true);
        if (empty($this->config['base_url']) || empty($key)) {
            throw new OtpException('GATEWAY_NOT_CONFIGURED', 'OPENWA_BASE_URL dan OPENWA_API_KEY belum diisi.', 503);
        }

        try {
            $response = Http::baseUrl(rtrim($this->config['base_url'], '/'))
                ->acceptJson()
                ->connectTimeout($this->config['connect_timeout'] ?? 3)
                ->timeout(15)
                ->withHeaders(['X-API-Key' => $key])
                ->get('/api/sessions');
        } catch (ConnectionException) {
            throw new OtpException('GATEWAY_UNREACHABLE', 'Gateway WhatsApp tidak dapat dihubungi.', 503);
        }

        if ($response->failed()) {
            $status = $response->status();
            throw new OtpException(
                (string) ($response->json('code') ?: 'GATEWAY_HTTP_'.$status),
                'Gagal mengambil daftar sesi OpenWA.',
                in_array($status, [400, 404, 409], true) ? $status : 502,
            );
        }

        return (array) $response->json();
    }

    /**
     * Create or start a new session on OpenWA engine.
     */
    public function createSession(string $sessionId, ?string $name = null, ?string $apiKey = null): array
    {
        $key = $this->resolveKey($sessionId, $apiKey, true);
        if (empty($this->config['base_url']) || empty($key)) {
            throw new OtpException('GATEWAY_NOT_CONFIGURED', 'OPENWA_BASE_URL dan OPENWA_API_KEY belum diisi.', 503);
        }

        // OpenWA CreateSessionDto only allows letters, numbers, and hyphens (3-50 chars).
        $cleanName = preg_replace('/[^a-zA-Z0-9-]+/', '-', str_replace('_', '-', trim($sessionId))) ?: 'wa-sender';
        $cleanName = substr(trim($cleanName, '-'), 0, 50);
        if (strlen($cleanName) < 3) {
            $cleanName = str_pad($cleanName, 3, '0');
        }

        try {
            $base = Http::baseUrl(rtrim($this->config['base_url'], '/'))
                ->acceptJson()
                ->connectTimeout($this->config['connect_timeout'] ?? 3)
                ->timeout(30)
                ->withHeaders(['X-API-Key' => $key]);

            $response = $base->post('/api/sessions', [
                'name' => $cleanName,
            ]);

            $sessionData = null;
            if ($response->status() === 409) {
                $lookup = $base->get('/api/sessions', ['name' => $cleanName]);
                if ($lookup->successful() && is_array($lookup->json()) && ! empty($lookup->json()[0])) {
                    $s = $lookup->json()[0];
                    $sessionData = [
                        'id' => $s['id'] ?? null,
                        'name' => $s['name'] ?? null,
                        'status' => $s['status'] ?? null,
                        'phone' => $s['phone'] ?? null,
                        'push_name' => $s['pushName'] ?? null,
                    ];
                }
            } elseif ($response->successful()) {
                $sessionData = $this->session($response);
            }

            if ($sessionData && ! empty($sessionData['id'])) {
                $statusNow = strtolower((string) ($sessionData['status'] ?? ''));
                if (in_array($statusNow, ['', 'created', 'disconnected', 'failed', 'stopped'], true)) {
                    $started = $base->post('/api/sessions/'.rawurlencode((string) $sessionData['id']).'/start', (object) []);
                    if ($started->successful()) {
                        $sessionData = $this->session($started);
                    }
                }

                return $sessionData;
            }
        } catch (ConnectionException) {
            throw new OtpException('GATEWAY_UNREACHABLE', 'Gateway WhatsApp tidak dapat dihubungi.', 503);
        }

        if ($response->failed() && $response->status() !== 409) {
            $status = $response->status();
            throw new OtpException(
                (string) ($response->json('code') ?: 'GATEWAY_HTTP_'.$status),
                'Gagal membuat sesi baru di OpenWA: '.($response->json('message') ?? 'error'),
                in_array($status, [400, 404, 409], true) ? $status : 502,
            );
        }

        return $this->session($response);
    }

    /**
     * Delete a session on OpenWA engine.
     */
    public function deleteSession(string $sessionId, ?string $apiKey = null): array
    {
        $key = $this->resolveKey($sessionId, $apiKey, true);
        if (empty($this->config['base_url']) || empty($key)) {
            throw new OtpException('GATEWAY_NOT_CONFIGURED', 'OPENWA_BASE_URL dan OPENWA_API_KEY belum diisi.', 503);
        }

        try {
            $response = Http::baseUrl(rtrim($this->config['base_url'], '/'))
                ->acceptJson()
                ->connectTimeout($this->config['connect_timeout'] ?? 3)
                ->timeout(30)
                ->withHeaders(['X-API-Key' => $key])
                ->delete('/api/sessions/'.rawurlencode($sessionId));
        } catch (ConnectionException) {
            throw new OtpException('GATEWAY_UNREACHABLE', 'Gateway WhatsApp tidak dapat dihubungi.', 503);
        }

        return (array) $response->json();
    }

    private function call(string $method, string $path, array $body = [], int $timeout = 15, ?string $sessionId = null, ?string $apiKey = null): Response
    {
        $session = $sessionId ?? ($this->config['session_id'] ?? null);
        $key = $this->resolveKey($session, $apiKey);

        if (! $this->configured($session, $key)) {
            throw new OtpException('GATEWAY_NOT_CONFIGURED', 'OPENWA_BASE_URL, OPENWA_API_KEY dan OPENWA_SESSION_ID belum diisi.', 503);
        }

        try {
            $request = Http::baseUrl(rtrim($this->config['base_url'], '/'))
                ->acceptJson()
                ->connectTimeout($this->config['connect_timeout'] ?? 3)
                ->timeout($timeout)
                ->withHeaders(['X-API-Key' => $key]);
            $url = '/api/sessions/'.rawurlencode($session).$path;
            $response = $method === 'get' ? $request->get($url) : $request->post($url, $body ?: (object) []);
        } catch (ConnectionException) {
            throw new OtpException('GATEWAY_UNREACHABLE', 'Gateway WhatsApp tidak dapat dihubungi.', 503);
        }

        if ($response->failed()) {
            $message = $response->json('message');
            $message = is_array($message) ? implode(' ', $message) : (string) ($message ?: 'Gateway menolak permintaan.');
            $status = $response->status();
            throw new OtpException(
                (string) ($response->json('code') ?: 'GATEWAY_HTTP_'.$status),
                mb_substr($message, 0, 300),
                in_array($status, [400, 404, 409], true) ? $status : 502,
            );
        }

        return $response;
    }

    private function session(Response $response): array
    {
        $s = $response->json();

        return [
            'id' => $s['id'] ?? null,
            'name' => $s['name'] ?? null,
            'status' => $s['status'] ?? null,
            'phone' => $s['phone'] ?? null,
            'push_name' => $s['pushName'] ?? null,
            'connected_at' => $s['connectedAt'] ?? null,
            'last_error' => $s['lastError'] ?? null,
            'restriction' => $s['restriction'] ?? null,
        ];
    }
}
