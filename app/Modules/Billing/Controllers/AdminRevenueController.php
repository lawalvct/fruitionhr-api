<?php

namespace App\Modules\Billing\Controllers;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\RevenueService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Platform revenue reporting. Gated on its own ability, separately from
 * billing operations — running the price list and knowing what the business
 * earns are different jobs, and only one of them is the owner's.
 */
class AdminRevenueController extends Controller
{
    public function overview(Request $request, RevenueService $revenue): JsonResponse
    {
        $months = min(24, max(3, (int) $request->integer('months', 12)));

        return response()->json(['data' => $revenue->overview($months)]);
    }

    public function companies(Request $request, RevenueService $revenue): JsonResponse
    {
        $companies = $revenue->byCompany($request->only(['search', 'sort', 'per_page', 'paying_only']));

        return response()->json([
            'data' => $companies->getCollection()->map(function (Tenant $tenant): array {
                $subscription = $tenant->subscriptions->first();

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->status,
                    'customer_since' => $tenant->created_at?->toIso8601String(),
                    // Cash that has actually arrived from this company.
                    'collected' => (int) ($tenant->payments_sum_amount ?? 0),
                    'payments_count' => (int) ($tenant->payments_count ?? 0),
                    'subscription' => $subscription === null ? null : [
                        'status' => $subscription->status,
                        'plan' => $subscription->plan?->name,
                        'employee_count' => $subscription->employee_count,
                        // What they are contracted to pay each period. Zero
                        // while trialing is correct, not missing data.
                        'amount' => (int) $subscription->amount,
                        'is_earning' => $subscription->status === Subscription::STATUS_ACTIVE,
                        'renews_at' => $subscription->current_period_end?->toIso8601String(),
                    ],
                ];
            })->all(),
            'meta' => [
                'current_page' => $companies->currentPage(),
                'last_page' => $companies->lastPage(),
                'per_page' => $companies->perPage(),
                'total' => $companies->total(),
                'from' => $companies->firstItem(),
                'to' => $companies->lastItem(),
            ],
        ]);
    }
}
