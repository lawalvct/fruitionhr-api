<?php

namespace App\Support\Http;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user === null
            || ! $user->isSuperAdmin()
            || $user->tenant_id !== null
            || $user->status !== User::STATUS_ACTIVE
            || ! $user->hasVerifiedEmail()
        ) {
            abort(403, 'Super admin access required.');
        }

        return $next($request);
    }
}
