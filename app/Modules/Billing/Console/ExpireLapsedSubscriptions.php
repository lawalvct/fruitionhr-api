<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Models\Subscription;
use App\Support\Tenancy\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Moves subscriptions whose window has closed into a lapsed state.
 *
 * Nothing else does this: statuses only change on payment or cancellation, so
 * without this sweep a subscription whose period ended still reads `active`
 * and keeps full write access indefinitely.
 *
 * Transitions, all one-way:
 *   trialing  + trial_ends_at passed        -> past_due
 *   active    + current_period_end passed   -> past_due
 *   cancelled + ends_at passed              -> expired
 *   past_due  for longer than the grace     -> expired
 *
 * past_due and expired are both read-only. They are kept apart so support can
 * tell a customer who lapsed yesterday from one who left months ago.
 */
class ExpireLapsedSubscriptions extends Command
{
    protected $signature = 'billing:expire-lapsed
        {--expire-after=30 : Days a subscription may sit past_due before it is written off as expired}
        {--dry-run : Report what would change without touching anything}';

    protected $description = 'Move subscriptions whose paid period has ended into a lapsed state';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $expireAfter = now()->subDays((int) $this->option('expire-after'));

        $trialsEnded = $this->transition(
            Subscription::query()
                ->where('status', Subscription::STATUS_TRIALING)
                ->whereNotNull('trial_ends_at')
                ->where('trial_ends_at', '<=', now()),
            Subscription::STATUS_PAST_DUE,
            $dryRun,
        );

        $periodsEnded = $this->transition(
            Subscription::query()
                ->where('status', Subscription::STATUS_ACTIVE)
                ->whereNotNull('current_period_end')
                ->where('current_period_end', '<=', now()),
            Subscription::STATUS_PAST_DUE,
            $dryRun,
        );

        $cancellationsRunOut = $this->transition(
            Subscription::query()
                ->where('status', Subscription::STATUS_CANCELLED)
                ->whereNotNull('ends_at')
                ->where('ends_at', '<=', now()),
            Subscription::STATUS_EXPIRED,
            $dryRun,
        );

        // Written off only after sitting unpaid for the whole grace window.
        $writtenOff = $this->transition(
            Subscription::query()
                ->where('status', Subscription::STATUS_PAST_DUE)
                ->where('updated_at', '<=', $expireAfter),
            Subscription::STATUS_EXPIRED,
            $dryRun,
        );

        $this->info(sprintf(
            '%s%d trial(s) ended, %d period(s) lapsed, %d cancellation(s) ran out, %d written off.',
            $dryRun ? '[dry run] ' : '',
            $trialsEnded, $periodsEnded, $cancellationsRunOut, $writtenOff,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  Builder<Subscription>  $query
     */
    private function transition($query, string $status, bool $dryRun): int
    {
        // Subscriptions are tenant-scoped and the scope fails closed, so this
        // console command must drop it to see anything at all.
        $query->withoutGlobalScope(TenantScope::class);

        if ($dryRun) {
            return $query->count();
        }

        return $query->update(['status' => $status, 'updated_at' => now()]);
    }
}
