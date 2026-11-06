<?php

namespace BilliPancaWijaya\WhatsAppGateway\Services;

use Illuminate\Http\Request;

/**
 * Resolves the tenant from server configuration (request host mapping), never from untrusted client input.
 */
class TenantResolver
{
    private function defaultTenant(): string
    {
        return (string) config('whatsapp-gateway.default_tenant', config('whatsapp_otp.default_tenant', 'default'));
    }

    private function tenantHosts(): string
    {
        return (string) config('whatsapp-gateway.tenant_hosts', config('whatsapp_otp.tenant_hosts', ''));
    }

    public function forAdmin(Request $request): string
    {
        $tenant = (string) ($request->user()?->tenant_key ?? $this->defaultTenant());
        $requested = $request->input('tenant_key');
        abort_if($requested !== null && (! is_string($requested) || $requested !== $tenant), 403, 'Tenant access denied.');

        return $tenant;
    }

    public function resolve(Request $request): string
    {
        $map = [];
        foreach (array_filter(array_map('trim', explode(',', $this->tenantHosts()))) as $pair) {
            [$host, $tenant] = array_pad(array_map('trim', explode('=', $pair, 2)), 2, '');
            if ($host !== '' && $tenant !== '') {
                $map[strtolower($host)] = $tenant;
            }
        }

        return $map[strtolower($request->getHost())] ?? $this->defaultTenant();
    }
}
