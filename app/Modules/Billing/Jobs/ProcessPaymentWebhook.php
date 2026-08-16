<?php

namespace App\Modules\Billing\Jobs;

use App\Modules\Billing\Services\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-verifies a payment named by a webhook.
 *
 * Deliberately carries only the reference, never the webhook body: the payload
 * is attacker-shaped even after the signature check, so the amount and status
 * are read back from the gateway rather than trusted from the wire.
 *
 * No TenantAware trait here — PaymentService resolves the payment without the
 * tenant scope and takes the tenant from the stored row.
 */
class ProcessPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $reference) {}

    public function handle(PaymentService $payments): void
    {
        try {
            $payments->verify($this->reference);
        } catch (Throwable $e) {
            // An unknown reference means the webhook was for something we did
            // not create. Log and drop rather than retrying forever.
            Log::warning('Payment webhook could not be processed', [
                'reference' => $this->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
