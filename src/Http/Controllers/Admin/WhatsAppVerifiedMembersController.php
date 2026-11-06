<?php

namespace BilliPancaWijaya\WhatsAppGateway\Http\Controllers\Admin;

use BilliPancaWijaya\WhatsAppGateway\Services\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;

/**
 * Controller for Back-Office integration to pull verified WhatsApp members.
 * Accessible by admins & super-admins via Sanctum auth.
 */
class WhatsAppVerifiedMembersController extends Controller
{
    private function getUserModel(): string
    {
        return (string) config('whatsapp-gateway.user_model', config('auth.providers.users.model', 'App\\Models\\User'));
    }

    public function index(Request $request): JsonResponse
    {
        $userClass = $this->getUserModel();
        $userTable = (new $userClass)->getTable();

        $cols = ['id', 'name', 'email', 'tenant_key', 'phone_e164', 'phone_verified_at', 'created_at'];
        foreach (['uuid', 'last_login_at', 'is_active'] as $optionalCol) {
            if (Schema::hasColumn($userTable, $optionalCol)) {
                $cols[] = $optionalCol;
            }
        }

        $query = $userClass::query()
            ->where('tenant_key', app(TenantResolver::class)->forAdmin($request))
            ->whereNotNull('phone_verified_at')
            ->select($cols);

        // Filter by tenant
        if ($tenant = $request->query('tenant_key')) {
            $query->where('tenant_key', $tenant);
        }

        // Search by phone or name
        if ($search = $request->query('search')) {
            $cleanSearch = trim($search);
            $query->where(function ($q) use ($cleanSearch) {
                $q->where('phone_e164', 'like', "%{$cleanSearch}%")
                    ->orWhere('name', 'like', "%{$cleanSearch}%")
                    ->orWhere('email', 'like', "%{$cleanSearch}%");
            });
        }

        // Date range filters
        if ($dateFrom = $request->query('date_from')) {
            $query->where('phone_verified_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->query('date_to')) {
            $query->where('phone_verified_at', '<=', $dateTo);
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 20)));
        $paginated = $query->orderBy('phone_verified_at', 'desc')->paginate($perPage);

        // Stats summary for the back-office dashboard
        $stats = [
            'total_verified' => (clone $query)->count(),
            'verified_today' => (clone $query)->where('phone_verified_at', '>=', now()->startOfDay())->count(),
            'verified_last_7_days' => (clone $query)->where('phone_verified_at', '>=', now()->subDays(7))->count(),
        ];

        return response()->json([
            'status' => 'success',
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'stats' => $stats,
            ],
            'data' => $paginated->items(),
        ]);
    }

    public function show(Request $request, int|string $id): JsonResponse
    {
        $userClass = $this->getUserModel();
        $userTable = (new $userClass)->getTable();

        $cols = ['id', 'name', 'email', 'tenant_key', 'phone_e164', 'phone_verified_at', 'created_at', 'updated_at'];
        foreach (['uuid', 'last_login_at', 'is_active'] as $optionalCol) {
            if (Schema::hasColumn($userTable, $optionalCol)) {
                $cols[] = $optionalCol;
            }
        }

        $user = $userClass::where('tenant_key', app(TenantResolver::class)->forAdmin($request))
            ->whereNotNull('phone_verified_at')
            ->select($cols)
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $user,
        ]);
    }
}
