<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Gateways\PaymentGatewayManager;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Subscription;
use App\Support\Money\Naira;
use App\Support\Tenancy\TenantScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Facades\File;

/**
 * Builds the downloadable receipt for a settled payment.
 *
 * Everything is read from the payment row rather than recalculated, so a
 * receipt stays true to what was actually charged even after the plan is
 * repriced or the company's headcount changes.
 */
class ReceiptService
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    public function document(Payment $payment): PdfDocument
    {
        // Subscription is tenant-scoped and the scope fails closed, so a plain
        // eager load returns null outside a tenant request — and the receipt
        // would silently lose its plan name and period.
        $subscription = $this->subscriptionFor($payment);
        $plan = $subscription?->plan;
        $seats = $payment->employee_count > 0 ? $payment->employee_count : 1;

        // The rate is derived from what was charged, not the plan's price now.
        $unitPrice = (int) round($payment->amount / $seats);

        return Pdf::loadView('billing.receipt', [
            'receiptNumber' => $this->number($payment),
            'reference' => $payment->reference,
            'company' => [
                'name' => $payment->tenant?->name ?? 'Company',
                'email' => $payment->tenant?->email,
            ],
            'brand' => [
                'product' => (string) config('mail.brand.product'),
                'company' => (string) config('mail.brand.company'),
                'address' => (string) config('mail.brand.address'),
                'support_email' => (string) config('mail.brand.support_email'),
                'website_url' => (string) config('mail.brand.website_url'),
                'tagline' => (string) config('mail.brand.tagline'),
            ],
            'logoDataUri' => $this->logoDataUri(),
            // Avoids "Subscription subscription" when the plan is unknown.
            'lineDescription' => $plan === null ? 'Subscription' : $plan->name.' subscription',
            'periodLabel' => $this->periodLabel($subscription),
            'seats' => $seats,
            'unitPriceFormatted' => Naira::format($unitPrice),
            'amountFormatted' => Naira::format($payment->amount),
            'currency' => $payment->currency,
            'gatewayLabel' => $this->gateways->label($payment->gateway),
            'paidAtFormatted' => ($payment->paid_at ?? $payment->created_at)?->format('d M Y, H:i'),
            'generatedAt' => now()->format('d M Y, H:i'),
        ]);
    }

    /** Stable, human-quotable receipt number. */
    public function number(Payment $payment): string
    {
        return 'RCP-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT);
    }

    public function filename(Payment $payment): string
    {
        return $this->number($payment).'.pdf';
    }

    public function findForTenant(string $reference, int $tenantId): Payment
    {
        return Payment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with('tenant')
            ->where('reference', $reference)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
    }

    /** The window the payment bought, when the subscription still knows it. */
    private function periodLabel(?Subscription $subscription): ?string
    {
        if ($subscription?->current_period_start === null || $subscription->current_period_end === null) {
            return null;
        }

        return $subscription->current_period_start->format('d M Y')
            .' – '.$subscription->current_period_end->format('d M Y');
    }

    private function subscriptionFor(Payment $payment): ?Subscription
    {
        if ($payment->subscription_id === null) {
            return null;
        }

        return Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with('plan')
            ->find($payment->subscription_id);
    }

    /**
     * Dompdf cannot fetch remote images, so the logo is inlined. Missing file
     * is not fatal — a receipt without a logo still beats no receipt.
     */
    private function logoDataUri(): ?string
    {
        $path = public_path('images/fruitionhr-logo-email.png');

        if (! File::exists($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode(File::get($path));
    }
}
