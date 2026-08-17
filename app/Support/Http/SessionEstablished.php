<?php

namespace App\Support\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Guards the one failure that looks like success.
 *
 * Sanctum only starts a session for requests it recognises as coming from the
 * frontend (SANCTUM_STATEFUL_DOMAINS). When the origin is not listed there is
 * no session to write to, so Auth::guard('web')->login() holds the user in
 * memory for that request and nothing else — the response carries no session
 * cookie at all.
 *
 * The caller sees 201/200 and a user object, believes it is signed in, and
 * every request after that is anonymous. Nothing in the logs says why. That is
 * exactly how a deploy with the wrong stateful domains strands somebody
 * mid-onboarding, so signing in without a session is reported rather than
 * shrugged off.
 *
 * Token clients (a future mobile app) legitimately have no session, so this
 * warns rather than throws — the browser flow is broken, but refusing the
 * request outright would break the other kind of client too.
 */
final class SessionEstablished
{
    public static function assert(Request $request, string $flow): void
    {
        if ($request->hasSession()) {
            return;
        }

        Log::warning('Cookie authentication is misconfigured: no session was established.', [
            'flow' => $flow,
            'origin' => $request->headers->get('origin'),
            'referer' => $request->headers->get('referer'),
            'hint' => 'Sanctum did not treat this request as coming from the frontend. '
                .'Add this origin to SANCTUM_STATEFUL_DOMAINS, then run: php artisan auth:doctor --origin=<origin>',
            'stateful_domains' => config('sanctum.stateful'),
        ]);
    }
}
