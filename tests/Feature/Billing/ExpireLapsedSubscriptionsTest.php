<?php

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Statuses otherwise only change on payment or cancellation, so this sweep is
 * the only thing that stops a subscription reading `active` forever after its
 * period has quietly ended.
 */
function lapsingSubscription(array $attributes): Subscription
{
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();

    return Subscription::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ], $attributes));
}

test('a trial that has run out becomes past due', function (): void {
    $subscription = lapsingSubscription([
        'status' => Subscription::STATUS_TRIALING,
        'trial_ends_at' => now()->subHour(),
    ]);

    expect($subscription->isUsable())->toBeFalse(); // already unusable...
    $this->artisan('billing:expire-lapsed')->assertSuccessful();

    // ...but the status now says so, which is what reporting and support read.
    expect($subscription->fresh()->status)->toBe(Subscription::STATUS_PAST_DUE);
});

test('a trial still running is left alone', function (): void {
    $subscription = lapsingSubscription([
        'status' => Subscription::STATUS_TRIALING,
        'trial_ends_at' => now()->addDays(5),
    ]);

    $this->artisan('billing:expire-lapsed')->assertSuccessful();

    expect($subscription->fresh()->status)->toBe(Subscription::STATUS_TRIALING);
});

test('an active subscription whose period ended becomes past due', function (): void {
    $subscription = lapsingSubscription([
        'status' => Subscription::STATUS_ACTIVE,
        'current_period_end' => now()->subDay(),
    ]);

    $this->artisan('billing:expire-lapsed')->assertSuccessful();

    $fresh = $subscription->fresh();
    expect($fresh->status)->toBe(Subscription::STATUS_PAST_DUE)
        // past_due is read-only — this is what the middleware keys off.
        ->and($fresh->isUsable())->toBeFalse();
});

test('a paid-up subscription is untouched', function (): void {
    $subscription = lapsingSubscription([
        'status' => Subscription::STATUS_ACTIVE,
        'current_period_end' => now()->addDays(20),
    ]);

    $this->artisan('billing:expire-lapsed')->assertSuccessful();

    expect($subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE);
});

test('a cancellation expires once the paid period runs out', function (): void {
    $stillPaid = lapsingSubscription([
        'status' => Subscription::STATUS_CANCELLED,
        'ends_at' => now()->addDays(5),
    ]);
    $runOut = lapsingSubscription([
        'status' => Subscription::STATUS_CANCELLED,
        'ends_at' => now()->subDay(),
    ]);

    $this->artisan('billing:expire-lapsed')->assertSuccessful();

    // Cancelling should never cut access short of what was paid for.
    expect($stillPaid->fresh()->status)->toBe(Subscription::STATUS_CANCELLED)
        ->and($runOut->fresh()->status)->toBe(Subscription::STATUS_EXPIRED);
});

test('a long-unpaid subscription is eventually written off', function (): void {
    $recent = lapsingSubscription(['status' => Subscription::STATUS_PAST_DUE]);
    $stale = lapsingSubscription(['status' => Subscription::STATUS_PAST_DUE]);

    // Backdate past the grace window without touching the recent one.
    Subscription::withoutGlobalScopes()->whereKey($stale->id)
        ->update(['updated_at' => now()->subDays(45)]);

    $this->artisan('billing:expire-lapsed')->assertSuccessful();

    expect($recent->fresh()->status)->toBe(Subscription::STATUS_PAST_DUE)
        ->and($stale->fresh()->status)->toBe(Subscription::STATUS_EXPIRED);
});

test('the grace window is configurable', function (): void {
    $subscription = lapsingSubscription(['status' => Subscription::STATUS_PAST_DUE]);
    Subscription::withoutGlobalScopes()->whereKey($subscription->id)
        ->update(['updated_at' => now()->subDays(10)]);

    $this->artisan('billing:expire-lapsed', ['--expire-after' => 7])->assertSuccessful();

    expect($subscription->fresh()->status)->toBe(Subscription::STATUS_EXPIRED);
});

test('a dry run reports without changing anything', function (): void {
    $subscription = lapsingSubscription([
        'status' => Subscription::STATUS_ACTIVE,
        'current_period_end' => now()->subDay(),
    ]);

    $this->artisan('billing:expire-lapsed', ['--dry-run' => true])->assertSuccessful();

    expect($subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE);
});

test('the sweep spans every tenant', function (): void {
    // Subscription is tenant-scoped and the scope fails closed, so a console
    // command that forgot to drop it would silently update nothing.
    $first = lapsingSubscription(['status' => Subscription::STATUS_ACTIVE, 'current_period_end' => now()->subDay()]);
    $second = lapsingSubscription(['status' => Subscription::STATUS_ACTIVE, 'current_period_end' => now()->subDay()]);

    $this->artisan('billing:expire-lapsed')->assertSuccessful();

    expect($first->fresh()->status)->toBe(Subscription::STATUS_PAST_DUE)
        ->and($second->fresh()->status)->toBe(Subscription::STATUS_PAST_DUE);
});
