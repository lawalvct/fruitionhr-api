<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Services\PaymentService;
use App\Support\Tenancy\TenantScope;
use Illuminate\Console\Command;
use Throwable;

/**
 * Safety net for customers who paid and then closed the browser before the
 * callback fired. Callbacks and webhooks both get missed; anything touching
 * money needs a sweep that catches the difference.
 */
class ReconcilePendingPayments extends Command
{
    protected $signature = 'billing:reconcile
        {--minutes=10 : Only look at payments older than this}
        {--abandon-after=1440 : Mark still-pending payments abandoned after this many minutes}';

    protected $description = 'Re-verify pending payments against their gateway';

    public function handle(PaymentService $payments): int
    {
        $olderThan = now()->subMinutes((int) $this->option('minutes'));
        $abandonBefore = now()->subMinutes((int) $this->option('abandon-after'));

        $pending = Payment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('status', Payment::STATUS_PENDING)
            ->where('created_at', '<=', $olderThan)
            ->orderBy('id')
            ->limit(200)
            ->get();

        $confirmed = 0;
        $abandoned = 0;

        foreach ($pending as $payment) {
            // Old enough that the customer is never coming back to it.
            if ($payment->created_at->lt($abandonBefore)) {
                $payment->forceFill(['status' => Payment::STATUS_ABANDONED])->save();
                $abandoned++;

                continue;
            }

            try {
                if ($payments->verify($payment->reference)->status === Payment::STATUS_SUCCESSFUL) {
                    $confirmed++;
                }
            } catch (Throwable $e) {
                $this->warn("Could not verify {$payment->reference}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf(
            'Checked %d pending payment(s): %d confirmed, %d abandoned.',
            $pending->count(), $confirmed, $abandoned,
        ));

        return self::SUCCESS;
    }
}
