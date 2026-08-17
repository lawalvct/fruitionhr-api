<?php

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates an admin route on a single platform ability.
 *
 * Runs behind {@see EnsureSuperAdmin}, which has already established that the
 * caller is active, verified platform staff. This decides which parts of the
 * platform they actually work on — a support agent has no business suspending
 * a company, and a blog editor none reading the audit log.
 *
 * The sidebar hides what a staff member cannot reach, but that is presentation.
 * This is the check that counts, because a URL can always be typed.
 */
class EnsurePlatformAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->hasPlatformAbility($ability)) {
            abort(403, 'You do not have access to this part of the admin.');
        }

        return $next($request);
    }
}
