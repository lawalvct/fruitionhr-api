<?php

use App\Models\User;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\ReceiptService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;

/**
 * A receipt is a record of money that changed hands, so it must only ever
 * exist for a settled payment and must never cross a tenant boundary.
 */
/** dompdf writes the naira sign as &#8358;, so decode before asserting. */
function receiptText(string $html): string
{
    return html_entity_decode($html, ENT_QUOTES, 'UTF-8');
}

function receiptSetup(string $status = Payment::STATUS_SUCCESSFUL): array
{
    $tenant = Tenant::factory()->create(['name' => 'Alpha Foods Ltd', 'email' => 'accounts@alpha.test']);
    app(CurrentTenant::class)->set($tenant);

    $plan = Plan::factory()->create(['name' => 'Growth', 'price_per_employee' => 150000]);
    $subscription = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'current_period_start' => now()->subDays(2),
        'current_period_end' => now()->addDays(28),
    ]);

    $payment = new Payment;
    $payment->forceFill([
        'tenant_id' => $tenant->id,
        'subscription_id' => $subscription->id,
        'gateway' => 'paystack',
        'reference' => 'PST_RECEIPT_'.uniqid(),
        'amount' => 1800000, // 12 seats x N1,500
        'currency' => 'NGN',
        'status' => $status,
        'employee_count' => 12,
        'paid_at' => $status === Payment::STATUS_SUCCESSFUL ? now() : null,
    ])->save();

    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'email_verified_at' => now()]);

    return [$tenant, $owner, $payment->refresh()];
}

test('a settled payment can be downloaded as a pdf receipt', function (): void {
    [, $owner, $payment] = receiptSetup();

    $response = $this->actingAs($owner)
        ->get("/api/v1/billing/payments/{$payment->reference}/receipt")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($response->headers->get('content-disposition'))->toContain('RCP-');

    // A real PDF, not an error page rendered with the wrong header.
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

test('the receipt reflects what was charged, not the plan price today', function (): void {
    [, , $payment] = receiptSetup();

    // Repricing the plan afterwards must not rewrite history.
    Plan::query()->update(['price_per_employee' => 999999]);

    $receipts = app(ReceiptService::class);
    $html = receiptText($receipts->document($payment->fresh())->getDomPDF()->outputHtml());

    expect($html)
        ->toContain('₦18,000.00')      // total actually paid
        ->toContain('₦1,500.00')       // rate derived from the payment
        ->not->toContain('₦9,999.99');
});

test('the receipt names the company, plan and period', function (): void {
    [, , $payment] = receiptSetup();

    // Cleared on purpose: the receipt must resolve the plan itself rather than
    // depending on an ambient tenant context that a queued job would not have.
    app(CurrentTenant::class)->forget();

    $html = receiptText(app(ReceiptService::class)->document($payment)->getDomPDF()->outputHtml());

    expect($html)
        ->toContain('Alpha Foods Ltd')
        ->toContain('accounts@alpha.test')
        ->toContain('Growth subscription')
        ->toContain('Paystack')
        ->toContain($payment->reference)
        // The billed period, which is only present when the subscription
        // resolved despite there being no tenant context.
        ->toContain(now()->addDays(28)->format('d M Y'))
        ->not->toContain('Subscription subscription');
});

test('a pending or failed payment has no receipt', function (): void {
    foreach ([Payment::STATUS_PENDING, Payment::STATUS_FAILED] as $status) {
        [, $owner, $payment] = receiptSetup($status);

        $this->actingAs($owner)
            ->get("/api/v1/billing/payments/{$payment->reference}/receipt")
            ->assertNotFound();
    }
});

test('a tenant cannot download another tenant receipt', function (): void {
    [, , $alphaPayment] = receiptSetup();
    [, $betaOwner] = receiptSetup();

    $this->actingAs($betaOwner)
        ->get("/api/v1/billing/payments/{$alphaPayment->reference}/receipt")
        ->assertNotFound();
});

test('receipts stay downloadable while the workspace is read-only', function (): void {
    // Lapsed customers must still be able to retrieve their own records.
    [$tenant, $owner, $payment] = receiptSetup();

    Subscription::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->update(['status' => Subscription::STATUS_EXPIRED, 'current_period_end' => now()->subDay()]);

    $this->actingAs($owner)
        ->get("/api/v1/billing/payments/{$payment->reference}/receipt")
        ->assertOk();
});

test('receipts are closed to guests', function (): void {
    [, , $payment] = receiptSetup();

    $this->getJson("/api/v1/billing/payments/{$payment->reference}/receipt")->assertUnauthorized();
});

test('receipt numbers are stable and padded', function (): void {
    [, , $payment] = receiptSetup();
    $receipts = app(ReceiptService::class);

    expect($receipts->number($payment))->toBe('RCP-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT))
        ->and($receipts->number($payment))->toBe($receipts->number($payment->fresh()));
});
