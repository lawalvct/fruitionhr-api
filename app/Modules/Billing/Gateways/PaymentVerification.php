<?php

namespace App\Modules\Billing\Gateways;

/** Normalised result of checking a charge, across gateways. */
readonly class PaymentVerification
{
    public function __construct(
        public bool $success,
        public string $status,            // successful | failed | pending | unknown
        public int $amount = 0,           // kobo, as reported by the gateway
        public string $currency = 'NGN',
        public ?string $message = null,
        /** @var array<string, mixed> */
        public array $raw = [],
    ) {}

    public static function failed(string $message, array $raw = []): self
    {
        return new self(success: false, status: 'failed', message: $message, raw: $raw);
    }
}
