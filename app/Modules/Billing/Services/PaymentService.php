<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Gateways\PaymentGatewayManager;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly BillingService $billing,
        private readonly GatewaySettings $settings,
    ) {}

    /**
     * Start a charge for the tenant's current plan price.
     *
     * @return array{payment: Payment, payment_url: string}
     */
    public function initialize(Tenant $tenant, Plan $plan, string $email, ?string $provider = null): array
    {
        // Fall back to the platform default rather than whatever env says, so
        // the admin switch is what actually decides.
        $provider ??= $this->settings->default();
        $usable = $this->settings->usable();

        if ($provider === null || ! in_array($provider, $usable, true)) {
            throw ValidationException::withMessages([
                'gateway' => 'That payment method is not available right now.',
            ]);
        }

        $gateway = $this->gateways->driver($provider);

        $quote = $this->billing->quote($plan, $tenant->id);

        if ($quote['amount'] <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'There is nothing to pay for this plan yet.',
            ]);
        }

        $subscription = $this->billing->activeSubscription($tenant->id);
        $reference = $this->newReference($gateway->name());

        $payment = new Payment;
        $payment->forceFill([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription?->id,
            'gateway' => $gateway->name(),
            'reference' => $reference,
            'amount' => $quote['amount'],
            'currency' => 'NGN',
            'status' => Payment::STATUS_PENDING,
            'employee_count' => $quote['employees'],
        ])->save();

        $result = $gateway->initialize(
            amountKobo: $quote['amount'],
            email: $email,
            callbackUrl: (string) config('services.billing.callback_url'),
            reference: $reference,
            metadata: [
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'employees' => $quote['employees'],
            ],
        );

        if (! $result->success || $result->paymentUrl === null) {
            $payment->forceFill([
                'status' => Payment::STATUS_FAILED,
                'gateway_response' => $result->raw,
            ])->save();

            throw ValidationException::withMessages([
                'gateway' => $result->message ?? 'Could not start the payment.',
            ]);
        }

        return ['payment' => $payment->refresh(), 'payment_url' => $result->paymentUrl];
    }

    /**
     * Confirm a charge with the gateway and fulfil exactly once.
     *
     * The reference is looked up in our own table first — never trust the one
     * in a callback query string or webhook body on its own. The row lock plus
     * the already-successful short circuit make a webhook and a client verify
     * arriving together safe.
     */
    public function verify(string $reference): Payment
    {
        $payment = Payment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('reference', $reference)
            ->firstOr(fn () => throw ValidationException::withMessages([
                'reference' => 'That payment reference is not recognised.',
            ]));

        return DB::transaction(function () use ($payment): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->first();

            if ($locked->status === Payment::STATUS_SUCCESSFUL) {
                return $locked; // Already fulfilled — do not double-credit.
            }

            $gateway = $this->gateways->driver($locked->gateway);
            $result = $gateway->verify($locked->reference);

            if (! $result->success) {
                $locked->forceFill([
                    'status' => Payment::STATUS_FAILED,
                    'gateway_response' => $result->raw,
                    'verified_at' => now(),
                ])->save();

                return $locked->refresh();
            }

            // Guard against a gateway reporting a different amount than we asked
            // for — an underpayment must not activate a subscription.
            if ($result->amount > 0 && $result->amount < $locked->amount) {
                $locked->forceFill([
                    'status' => Payment::STATUS_FAILED,
                    'gateway_response' => $result->raw + ['amount_mismatch' => true],
                    'verified_at' => now(),
                ])->save();

                return $locked->refresh();
            }

            $locked->forceFill([
                'status' => Payment::STATUS_SUCCESSFUL,
                'gateway_response' => $result->raw,
                'paid_at' => now(),
                'verified_at' => now(),
            ])->save();

            $this->billing->recordSuccessfulPayment($locked);

            return $locked->refresh();
        });
    }

    /**
     * @return array{reference: string, subscription: ?Subscription}
     */
    public function subscriptionFor(Payment $payment): ?Subscription
    {
        return $payment->subscription_id === null
            ? null
            : Subscription::query()
                ->withoutGlobalScope(TenantScope::class)
                ->with('plan')
                ->find($payment->subscription_id);
    }

    private function newReference(string $gateway): string
    {
        $prefix = $gateway === PaymentGatewayManager::NOMBA ? 'NMB' : 'PST';

        return $prefix.'_'.strtoupper(Str::random(10)).'_'.time();
    }
}
