<?php

namespace App\Modules\Billing\Gateways;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nomba. Two things differ from Paystack and both are easy to get wrong:
 *
 *  - Amounts are **Naira**, not kobo. This class divides by 100 on the way out.
 *  - Every call needs an OAuth token first. It is cached just under its TTL
 *    rather than re-issued per operation.
 *
 * Header casing is also inconsistent in Nomba's API: `AccountId` on the token
 * call, `accountId` on everything else. That is intentional, not a typo.
 */
class NombaGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.nomba.com';

    private const TOKEN_TTL_MINUTES = 50;

    public function __construct(
        private readonly ?string $accountId = null,
        private readonly ?string $clientId = null,
        private readonly ?string $privateKey = null,
    ) {}

    public function name(): string
    {
        return 'nomba';
    }

    public function isConfigured(): bool
    {
        return ! in_array(null, [$this->account(), $this->client(), $this->key()], true)
            && ! in_array('', [$this->account(), $this->client(), $this->key()], true);
    }

    public function initialize(
        int $amountKobo,
        string $email,
        string $callbackUrl,
        string $reference,
        array $metadata = [],
    ): PaymentInitiation {
        if (! $this->isConfigured()) {
            return PaymentInitiation::failed('Nomba is not configured.');
        }

        $token = $this->accessToken();

        if ($token === null) {
            return PaymentInitiation::failed('Could not authenticate with Nomba.');
        }

        try {
            $response = Http::withToken($token)
                ->withHeaders(['accountId' => (string) $this->account()])
                ->acceptJson()
                ->timeout(30)
                ->post(self::BASE_URL.'/v1/checkout/order', [
                    'order' => [
                        'orderReference' => $reference,
                        'callbackUrl' => $callbackUrl,
                        'customerEmail' => $email,
                        // Naira, not kobo — the 100x bug lives here.
                        'amount' => $this->toNaira($amountKobo),
                        'currency' => 'NGN',
                    ],
                    'tokenizeCard' => 'true', // string, not boolean
                ]);

            $body = $response->json() ?? [];

            if (! $response->successful()) {
                return PaymentInitiation::failed(
                    $body['message'] ?? 'Nomba declined the request.'
                );
            }

            return new PaymentInitiation(
                success: true,
                paymentUrl: $body['data']['checkoutLink'] ?? null,
                reference: $body['data']['orderReference'] ?? $reference,
                raw: $body,
            );
        } catch (Throwable $e) {
            Log::warning('Nomba initialize failed', ['error' => $e->getMessage()]);

            return PaymentInitiation::failed('Could not reach Nomba.');
        }
    }

    public function verify(string $reference): PaymentVerification
    {
        if (! $this->isConfigured()) {
            return PaymentVerification::failed('Nomba is not configured.');
        }

        $token = $this->accessToken();

        if ($token === null) {
            return PaymentVerification::failed('Could not authenticate with Nomba.');
        }

        try {
            $response = Http::withToken($token)
                ->withHeaders(['accountId' => (string) $this->account()])
                ->acceptJson()
                ->timeout(30)
                ->get(self::BASE_URL.'/v1/checkout/transaction', [
                    'idType' => 'ORDER_ID',
                    'id' => $reference,
                ]);

            $body = $response->json() ?? [];

            if (! $response->successful()) {
                return PaymentVerification::failed(
                    $body['message'] ?? 'Nomba could not verify the reference.',
                    $body,
                );
            }

            $data = $body['data'] ?? [];

            return new PaymentVerification(
                success: $this->readSuccess($data),
                status: $this->readSuccess($data) ? 'successful' : 'failed',
                // Nomba reports Naira; store kobo.
                amount: $this->toKobo($data['amount'] ?? 0),
                currency: (string) ($data['currency'] ?? 'NGN'),
                raw: $body,
            );
        } catch (Throwable $e) {
            Log::warning('Nomba verify failed', ['error' => $e->getMessage()]);

            return PaymentVerification::failed('Could not reach Nomba.');
        }
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool
    {
        $secret = config('services.nomba.webhook_secret') ?: $this->key();

        if ($signature === null || $secret === null || $secret === '') {
            return false;
        }

        // sha256 for Nomba — Paystack uses sha512.
        return hash_equals(hash_hmac('sha256', $rawPayload, (string) $secret), $signature);
    }

    public function referenceFromWebhook(array $payload): ?string
    {
        return $payload['data']['order']['orderReference']
            ?? $payload['data']['orderReference']
            ?? null;
    }

    /**
     * Nomba's verify response comes back in three different shapes depending on
     * the flow, so try each in turn rather than trusting one key.
     *
     * @param  array<string, mixed>  $data
     */
    private function readSuccess(array $data): bool
    {
        if (isset($data['success'])) {
            return $data['success'] === true;
        }

        if (($data['message'] ?? null) === 'PAYMENT SUCCESSFUL') {
            return true;
        }

        if (isset($data['status'])) {
            return strtolower((string) $data['status']) === 'successful';
        }

        return false;
    }

    private function accessToken(): ?string
    {
        return Cache::remember(
            'nomba.token.'.md5((string) $this->account()),
            now()->addMinutes(self::TOKEN_TTL_MINUTES),
            function (): ?string {
                try {
                    $response = Http::withHeaders(['AccountId' => (string) $this->account()])
                        ->acceptJson()
                        ->timeout(30)
                        ->post(self::BASE_URL.'/v1/auth/token/issue', [
                            'grant_type' => 'client_credentials',
                            'client_id' => $this->client(),
                            'client_secret' => $this->key(),
                        ]);

                    return $response->json('data.access_token');
                } catch (Throwable $e) {
                    Log::warning('Nomba token issue failed', ['error' => $e->getMessage()]);

                    return null;
                }
            },
        );
    }

    private function toNaira(int $kobo): float
    {
        return round($kobo / 100, 2);
    }

    private function toKobo(mixed $naira): int
    {
        return (int) round(((float) $naira) * 100);
    }

    private function account(): ?string
    {
        return $this->accountId ?? config('services.nomba.account_id');
    }

    private function client(): ?string
    {
        return $this->clientId ?? config('services.nomba.client_id');
    }

    private function key(): ?string
    {
        return $this->privateKey ?? config('services.nomba.private_key');
    }
}
