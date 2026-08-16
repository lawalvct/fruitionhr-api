<?php

namespace App\Modules\Billing\Gateways;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Paystack. Takes kobo natively, so no conversion is needed here.
 * Webhook signatures are HMAC-**sha512** on the raw body.
 */
class PaystackGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.paystack.co';

    public function __construct(
        private readonly ?string $secretKey = null,
    ) {}

    public function name(): string
    {
        return 'paystack';
    }

    public function isConfigured(): bool
    {
        return $this->secret() !== null && $this->secret() !== '';
    }

    public function initialize(
        int $amountKobo,
        string $email,
        string $callbackUrl,
        string $reference,
        array $metadata = [],
    ): PaymentInitiation {
        if (! $this->isConfigured()) {
            return PaymentInitiation::failed('Paystack is not configured.');
        }

        try {
            $response = Http::withToken($this->secret())
                ->acceptJson()
                ->timeout(30)
                ->post(self::BASE_URL.'/transaction/initialize', [
                    'email' => $email,
                    'amount' => $amountKobo, // Paystack speaks kobo already.
                    'reference' => $reference,
                    'callback_url' => $callbackUrl,
                    'metadata' => $metadata,
                ]);

            $body = $response->json() ?? [];

            if (! $response->successful() || ($body['status'] ?? false) !== true) {
                return PaymentInitiation::failed(
                    $body['message'] ?? 'Paystack declined the request.'
                );
            }

            return new PaymentInitiation(
                success: true,
                paymentUrl: $body['data']['authorization_url'] ?? null,
                reference: $body['data']['reference'] ?? $reference,
                raw: $body,
            );
        } catch (Throwable $e) {
            Log::warning('Paystack initialize failed', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach Paystack.');
        }
    }

    public function verify(string $reference): PaymentVerification
    {
        if (! $this->isConfigured()) {
            return PaymentVerification::failed('Paystack is not configured.');
        }

        try {
            $response = Http::withToken($this->secret())
                ->acceptJson()
                ->timeout(30)
                ->get(self::BASE_URL.'/transaction/verify/'.rawurlencode($reference));

            $body = $response->json() ?? [];

            if (! $response->successful() || ($body['status'] ?? false) !== true) {
                return PaymentVerification::failed(
                    $body['message'] ?? 'Paystack could not verify the reference.',
                    $body,
                );
            }

            $data = $body['data'] ?? [];
            $status = strtolower((string) ($data['status'] ?? 'unknown'));

            return new PaymentVerification(
                success: $status === 'success',
                status: $status === 'success' ? 'successful' : $status,
                // Paystack reports kobo, which is what we store.
                amount: (int) ($data['amount'] ?? 0),
                currency: (string) ($data['currency'] ?? 'NGN'),
                raw: $body,
            );
        } catch (Throwable $e) {
            Log::warning('Paystack verify failed', ['error' => $e->getMessage()]);

            return PaymentVerification::failed('Could not reach Paystack.');
        }
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool
    {
        if ($signature === null || ! $this->isConfigured()) {
            return false;
        }

        // sha512 for Paystack — Nomba uses sha256. Mixing them up silently
        // rejects every webhook.
        $computed = hash_hmac('sha512', $rawPayload, (string) $this->secret());

        return hash_equals($computed, $signature);
    }

    public function referenceFromWebhook(array $payload): ?string
    {
        return $payload['data']['reference'] ?? null;
    }

    private function secret(): ?string
    {
        return $this->secretKey ?? config('services.paystack.secret_key');
    }
}
