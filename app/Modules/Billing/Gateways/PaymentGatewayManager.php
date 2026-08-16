<?php

namespace App\Modules\Billing\Gateways;

use InvalidArgumentException;

/**
 * Resolves gateway drivers.
 *
 * FruitionHR bills with its own platform keys only — tenants never bring their
 * own credentials, because this is SaaS subscription billing rather than a
 * marketplace where money lands in a merchant's account.
 */
class PaymentGatewayManager
{
    public const PAYSTACK = 'paystack';

    public const NOMBA = 'nomba';

    public function driver(?string $provider = null): PaymentGateway
    {
        $provider = $provider ?: (string) config('services.billing.default_gateway', self::PAYSTACK);

        return match ($provider) {
            self::PAYSTACK => new PaystackGateway,
            self::NOMBA => new NombaGateway,
            default => throw new InvalidArgumentException("Unknown payment gateway [{$provider}]."),
        };
    }

    /** @return list<string> */
    public function available(): array
    {
        return collect([self::PAYSTACK, self::NOMBA])
            ->filter(fn (string $name): bool => $this->driver($name)->isConfigured())
            ->values()
            ->all();
    }

    public function supports(string $provider): bool
    {
        return in_array($provider, [self::PAYSTACK, self::NOMBA], true);
    }
}
