<?php

namespace App\Modules\Billing\Controllers;

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Resources\PlanResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * The public price list for the marketing site.
 *
 * Unauthenticated, so it shows only active plans and carries no
 * tenant-specific quote or subscriber counts — there is no tenant here, and
 * subscriber numbers are not the public's business.
 */
class PublicPlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return PlanResource::collection($plans)->additional([
            'meta' => ['currency' => 'NGN'],
        ]);
    }
}
