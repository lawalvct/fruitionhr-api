<?php

namespace App\Modules\Billing\Controllers;

use App\Models\User;
use App\Modules\Billing\Gateways\PaymentGatewayManager;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Resources\PaymentResource;
use App\Modules\Billing\Resources\PlanResource;
use App\Modules\Billing\Resources\SubscriptionResource;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Services\PaymentService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A company's own billing. Everything here is scoped to the current tenant —
 * a tenant can only ever read or change its own subscription.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly PaymentService $payments,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /** The price list, each plan quoted against this tenant's headcount. */
    public function plans(): AnonymousResourceCollection
    {
        $tenantId = $this->tenantId();

        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return PlanResource::collection(
            $plans->map(fn (Plan $plan) => new PlanResource($plan, $this->billing->quote($plan, $tenantId)))
        )->additional([
            'meta' => [
                'employees' => $this->billing->billableEmployees($tenantId),
                'gateways' => $this->gateways->available(),
                'currency' => 'NGN',
            ],
        ]);
    }

    /** Current standing: plan, period, headcount and what renewal will cost. */
    public function subscription(): JsonResponse
    {
        $tenantId = $this->tenantId();
        $subscription = $this->billing->activeSubscription($tenantId);
        $employees = $this->billing->billableEmployees($tenantId);

        return response()->json([
            'data' => $subscription === null
                ? null
                : (new SubscriptionResource($subscription->loadMissing('plan')))->toArray(request()),
            'meta' => [
                'employees' => $employees,
                // Headcount moves between renewals, so show what the next
                // charge would be rather than only what was last paid.
                'renewal_quote' => $subscription?->plan === null
                    ? null
                    : $this->billing->quote($subscription->plan, $tenantId),
                'gateways' => $this->gateways->available(),
            ],
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
        ]);

        $tenant = app(CurrentTenant::class)->get();
        $plan = Plan::query()->findOrFail($validated['plan_id']);

        $subscription = $this->billing->subscribe($tenant, $plan);

        return response()->json([
            'data' => (new SubscriptionResource($subscription))->toArray($request),
            'message' => $subscription->onTrial()
                ? 'Your trial has started.'
                : 'Plan updated. Complete payment to activate it.',
        ]);
    }

    /**
     * Start a charge. Returns the gateway URL for the browser to open — this
     * API never redirects, since the caller is a client, not a browser.
     */
    public function pay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['nullable', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
            'gateway' => ['nullable', Rule::in([PaymentGatewayManager::PAYSTACK, PaymentGatewayManager::NOMBA])],
        ]);

        $tenant = app(CurrentTenant::class)->get();
        $tenantId = $this->tenantId();

        $plan = isset($validated['plan_id'])
            ? Plan::query()->findOrFail($validated['plan_id'])
            : $this->billing->activeSubscription($tenantId)?->plan;

        if ($plan === null) {
            throw ValidationException::withMessages([
                'plan_id' => 'Choose a plan before paying.',
            ]);
        }

        /** @var User $user */
        $user = $request->user();

        $result = $this->payments->initialize(
            tenant: $tenant,
            plan: $plan,
            email: $user->email,
            provider: $validated['gateway'] ?? null,
        );

        return response()->json([
            'data' => [
                'payment_url' => $result['payment_url'],
                'reference' => $result['payment']->reference,
                'amount' => $result['payment']->amount,
            ],
            'message' => 'Continue to the payment page to finish.',
        ], 201);
    }

    /**
     * Confirm a charge after the customer returns from the gateway.
     *
     * The reference is re-verified against the gateway server-side — the value
     * in the callback URL is attacker-controlled and is only a lookup key.
     */
    public function verify(string $reference): JsonResponse
    {
        $payment = $this->payments->verify($reference);

        // A tenant may only confirm its own payment.
        abort_unless((int) $payment->tenant_id === $this->tenantId(), 404);

        return response()->json([
            'data' => (new PaymentResource($payment))->toArray(request()),
            'message' => $payment->status === Payment::STATUS_SUCCESSFUL
                ? 'Payment confirmed. Your subscription is active.'
                : 'That payment did not go through.',
        ]);
    }

    public function payments(): AnonymousResourceCollection
    {
        return PaymentResource::collection(
            Payment::query()->latest('id')->paginate(20)
        );
    }

    public function cancel(): JsonResponse
    {
        $subscription = $this->billing->activeSubscription($this->tenantId());

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'subscription' => 'There is no subscription to cancel.',
            ]);
        }

        $cancelled = $this->billing->cancel($subscription);

        return response()->json([
            'data' => (new SubscriptionResource($cancelled))->toArray(request()),
            'message' => 'Cancelled. You keep access until the end of the period you have paid for.',
        ]);
    }

    private function tenantId(): int
    {
        return (int) app(CurrentTenant::class)->id();
    }
}
