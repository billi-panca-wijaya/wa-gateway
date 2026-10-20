<?php

namespace App\Services\WhatsAppOtp;

use Illuminate\Http\Request;

/**
 * Resolves the tenant from server configuration (request host mapping), never from client input.
 */
class TenantResolver
{
    public function resolve(Request $request): string
    {
        $map = [];
        foreach (array_filter(array_map('trim', explode(',', (string) config('whatsapp_otp.tenant_hosts')))) as $pair) {
            [$host, $tenant] = array_pad(array_map('trim', explode('=', $pair, 2)), 2, '');
            if ($host !== '' && $tenant !== '') {
                $map[strtolower($host)] = $tenant;
            }
        }

        return $map[strtolower($request->getHost())] ?? (string) config('whatsapp_otp.default_tenant', 'default');
    }
}
