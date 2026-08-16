<?php

namespace App\Modules\Billing\Controllers;

use App\Modules\Admin\Services\PlatformActivityService;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Requests\StorePlanRequest;
use App\Modules\Billing\Requests\UpdatePlanRequest;
use App\Modules\Billing\Resources\PlanResource;
use App\Modules\Billing\Services\GatewaySettings;
use App\Support\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/** Platform-side billing: the price list, and revenue across every tenant. */
class AdminBillingController extends Controller
{
    public function plans(): AnonymousResourceCollection
    {
        return PlanResource::collection(
            Plan::query()
                // Subscription is tenant-scoped and the scope fails closed, so
                // counting without dropping it returns 0 for every plan.
                ->withCount(['subscriptions' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)])
                ->orderBy('sort_order')
                ->get()
        );
    }

    public function storePlan(StorePlanRequest $request, PlatformActivityService $activity): JsonResponse
    {
        // The slug is derived and uniqueness-checked in StorePlanRequest, so it
        // is already safe to persist here.
        $plan = Plan::query()->create($request->validated());

        $activity->record(
            request: $request,
            action: 'plan.created',
            subjectType: 'plan',
            subjectId: $plan->id,
            subjectLabel: $plan->name,
            before: [],
            after: ['price_per_employee' => $plan->price_per_employee],
        );

        return (new PlanResource($plan))->response()->setStatusCode(201);
    }

    public function updatePlan(UpdatePlanRequest $request, int $plan, PlatformActivityService $activity): PlanResource
    {
        $model = Plan::query()->findOrFail($plan);
        $before = ['price_per_employee' => $model->price_per_employee, 'is_active' => $model->is_active];

        $model->update($request->validated());

        $activity->record(
            request: $request,
            action: 'plan.updated',
            subjectType: 'plan',
            subjectId: $model->id,
            subjectLabel: $model->name,
            before: $before,
            after: ['price_per_employee' => $model->price_per_employee, 'is_active' => $model->is_active],
        );

        return new PlanResource($model->refresh());
    }

    /** Which gateways are switched on, and which have credentials. */
    public function gateways(GatewaySettings $settings): JsonResponse
    {
        return response()->json([
            'data' => $settings->overview(),
            'meta' => ['default' => $settings->default()],
        ]);
    }

    public function updateGateways(
        Request $request,
        GatewaySettings $settings,
        PlatformActivityService $activity,
    ): JsonResponse {
        $validated = $request->validate([
            'enabled' => ['required', 'array', 'min:1'],
            'enabled.*' => ['string', 'max:40'],
            'default' => ['nullable', 'string', 'max:40'],
        ]);

        $before = $settings->overview();
        $settings->update($validated['enabled'], $validated['default'] ?? null);

        $activity->record(
            request: $request,
            action: 'billing.gateways_updated',
            subjectType: 'billing',
            subjectId: null,
            subjectLabel: 'Payment gateways',
            before: ['enabled' => collect($before)->where('enabled', true)->pluck('slug')->all()],
            after: ['enabled' => $validated['enabled'], 'default' => $settings->default()],
        );

        return response()->json([
            'data' => $settings->overview(),
            'meta' => ['default' => $settings->default()],
            'message' => 'Payment methods updated.',
        ]);
    }

    /** Every tenant's subscription, across the platform. */
    public function subscriptions(Request $request): JsonResponse
    {
        $query = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with(['plan', 'tenantRecord:id,name,slug']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $subscriptions = $query->latest('id')->paginate(20)->appends($request->query());

        return response()->json([
            'data' => $subscriptions->getCollection()->map(fn (Subscription $s): array => [
                'id' => $s->id,
                'status' => $s->status,
                'on_trial' => $s->onTrial(),
                'employee_count' => $s->employee_count,
                'amount' => $s->amount,
                'current_period_end' => $s->current_period_end?->toIso8601String(),
                'plan' => $s->plan === null ? null : ['id' => $s->plan->id, 'name' => $s->plan->name],
                'company' => $s->tenantRecord === null ? null : [
                    'id' => $s->tenantRecord->id,
                    'name' => $s->tenantRecord->name,
                ],
            ])->all(),
            'meta' => [
                'current_page' => $subscriptions->currentPage(),
                'last_page' => $subscriptions->lastPage(),
                'per_page' => $subscriptions->perPage(),
                'total' => $subscriptions->total(),
                'from' => $subscriptions->firstItem(),
                'to' => $subscriptions->lastItem(),
            ],
            'summary' => $this->summary(),
        ]);
    }

    /** @return array<string, int> */
    private function summary(): array
    {
        $subs = fn () => Subscription::query()->withoutGlobalScope(TenantScope::class);

        return [
            'active' => $subs()->where('status', Subscription::STATUS_ACTIVE)->count(),
            'trialing' => $subs()->where('status', Subscription::STATUS_TRIALING)->count(),
            'past_due' => $subs()->where('status', Subscription::STATUS_PAST_DUE)->count(),
            'cancelled' => $subs()->where('status', Subscription::STATUS_CANCELLED)->count(),
            // Money actually collected, in kobo.
            'collected' => (int) Payment::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('status', Payment::STATUS_SUCCESSFUL)
                ->sum('amount'),
            'billable_employees' => (int) Subscription::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
                ->sum('employee_count'),
        ];
    }
}
