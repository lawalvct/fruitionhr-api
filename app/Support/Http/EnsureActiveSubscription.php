<?php

namespace App\Support\Http;

use App\Modules\Billing\Services\BillingService;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Degrades a lapsed tenant to read-only.
 *
 * Reads are always allowed. A company that has stopped paying must still be
 * able to see and export its own records — payroll data is theirs, and locking
 * them out of payslips would be indefensible. Only writes are refused.
 *
 * Billing routes live in their own group without this middleware, so a lapsed
 * tenant can always reach the payment screen to fix the situation.
 */
class EnsureActiveSubscription
{
    /** Methods that change state. Everything else passes through. */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), self::WRITE_METHODS, true)) {
            return $next($request);
        }

        $tenantId = app(CurrentTenant::class)->id();

        if ($tenantId === null) {
            return $next($request);
        }

        $subscription = app(BillingService::class)->activeSubscription($tenantId);

        // No subscription at all means the tenant has never chosen a plan —
        // registration and onboarding must still work. Enforcement begins once
        // they are on the billing ladder.
        if ($subscription === null || $subscription->isUsable()) {
            return $next($request);
        }

        // 402 rather than 403: this is about payment, not permission, and the
        // frontend keys its paywall banner off this code.
        abort(Response::HTTP_PAYMENT_REQUIRED, 'Your subscription is not active. Renew to make changes — your data stays available to view and export.');
    }
}
