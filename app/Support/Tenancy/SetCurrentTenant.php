<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from the authenticated user and binds it for the
 * request. Applied to all /api/v1 tenant routes (after auth:sanctum).
 *
 * Rejects: users without a tenant (super admins must use /api/admin/v1),
 * and users of suspended tenants.
 */
class SetCurrentTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->tenant_id === null) {
            abort(403, 'This account is not attached to a company.');
        }

        $tenant = $user->tenant;

        if ($tenant === null || $tenant->status !== 'active') {
            abort(403, 'This company account is suspended. Contact support.');
        }

        app(CurrentTenant::class)->set($tenant);

        setPermissionsTeamId($tenant->id);

        return $next($request);
    }
}
