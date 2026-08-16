<?php

namespace App\Modules\Billing\Gateways;

/**
 * One shape for every gateway.
 *
 * Amounts crossing this boundary are always **integer kobo**, per the
 * project-wide money rule. Each driver converts to whatever its API expects —
 * Paystack takes kobo as-is, Nomba takes Naira — so no caller ever has to
 * remember which is which. That mismatch is the classic 100x billing bug.
 */
interface PaymentGateway
{
    public function name(): string;

    public function isConfigured(): bool;

    /** @param array<string, mixed> $metadata */
    public function initialize(
        int $amountKobo,
        string $email,
        string $callbackUrl,
        string $reference,
        array $metadata = [],
    ): PaymentInitiation;

    public function verify(string $reference): PaymentVerification;

    /** Always check against the raw request body, never the parsed array. */
    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool;

    /** Extracts the payment reference from a webhook payload. */
    public function referenceFromWebhook(array $payload): ?string;
}
