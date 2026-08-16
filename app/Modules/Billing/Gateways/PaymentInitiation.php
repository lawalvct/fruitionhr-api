<?php

namespace App\Modules\Billing\Gateways;

/** Normalised result of starting a charge, across gateways. */
readonly class PaymentInitiation
{
    public function __construct(
        public bool $success,
        public ?string $paymentUrl = null,
        public ?string $reference = null,
        public ?string $message = null,
        /** @var array<string, mixed> */
        public array $raw = [],
    ) {}

    public static function failed(string $message): self
    {
        return new self(success: false, message: $message);
    }
}
