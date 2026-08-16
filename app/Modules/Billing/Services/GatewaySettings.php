<?php

namespace App\Modules\Billing\Services;

use App\Modules\Admin\Models\PlatformSetting;
use App\Modules\Billing\Gateways\PaymentGatewayManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Which payment gateways FruitionHR is currently accepting money through.
 *
 * Two things gate a gateway, and both must hold:
 *   1. It has credentials (env) — otherwise it cannot work at all.
 *   2. A super admin has switched it on — the business decision.
 *
 * Keeping these separate means a gateway can be configured and staged without
 * being live, and can be switched off in seconds during an outage without a
 * deploy or a key change.
 */
class GatewaySettings
{
    public const SETTING_KEY = 'billing.gateways';

    private const CACHE_KEY = 'billing.gateways.settings';

    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /**
     * Gateways a tenant may actually pay with: enabled by the admin AND
     * configured with working credentials.
     *
     * @return list<string>
     */
    public function usable(): array
    {
        $enabled = $this->enabled();

        return collect($this->gateways->supported())
            ->filter(fn (string $slug): bool => in_array($slug, $enabled, true))
            ->filter(fn (string $slug): bool => $this->gateways->driver($slug)->isConfigured())
            ->values()
            ->all();
    }

    /** The gateway pre-selected for a tenant that does not choose one. */
    public function default(): ?string
    {
        $usable = $this->usable();

        if ($usable === []) {
            return null;
        }

        $preferred = $this->stored()['default'] ?? null;

        return in_array($preferred, $usable, true) ? $preferred : $usable[0];
    }

    /**
     * Everything the admin console needs to render the switches, including
     * gateways that are switched on but missing credentials.
     *
     * @return list<array{slug: string, label: string, enabled: bool, configured: bool, is_default: bool}>
     */
    public function overview(): array
    {
        $enabled = $this->enabled();
        $default = $this->default();

        return collect($this->gateways->supported())
            ->map(fn (string $slug): array => [
                'slug' => $slug,
                'label' => $this->gateways->label($slug),
                'enabled' => in_array($slug, $enabled, true),
                'configured' => $this->gateways->driver($slug)->isConfigured(),
                'is_default' => $slug === $default,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $enabled
     */
    public function update(array $enabled, ?string $default = null): void
    {
        $enabled = collect($enabled)
            ->filter(fn (string $slug): bool => $this->gateways->supports($slug))
            ->unique()
            ->values();

        // Turning everything off would leave customers unable to pay at all,
        // which is a worse failure than a bad gateway choice.
        if ($enabled->isEmpty()) {
            throw ValidationException::withMessages([
                'gateways' => 'At least one payment gateway must stay switched on, otherwise no one can pay.',
            ]);
        }

        $unconfigured = $enabled->first(
            fn (string $slug): bool => ! $this->gateways->driver($slug)->isConfigured()
        );

        if ($unconfigured !== null) {
            throw ValidationException::withMessages([
                'gateways' => $this->gateways->label($unconfigured).' has no API credentials set, so it cannot be switched on yet.',
            ]);
        }

        if ($default !== null && ! $enabled->contains($default)) {
            throw ValidationException::withMessages([
                'default' => 'The default gateway must be one of the gateways you switched on.',
            ]);
        }

        PlatformSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => ['enabled' => $enabled->all(), 'default' => $default ?? $enabled->first()]],
        );

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Enabled slugs as stored. Falls back to everything configured, so a fresh
     * install works before anyone visits the settings screen.
     *
     * @return list<string>
     */
    private function enabled(): array
    {
        $stored = $this->stored();

        if (! isset($stored['enabled'])) {
            return $this->gateways->available();
        }

        return array_values(array_filter(
            (array) $stored['enabled'],
            fn ($slug): bool => is_string($slug) && $this->gateways->supports($slug),
        ));
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(10),
            fn (): array => (array) (PlatformSetting::query()
                ->where('key', self::SETTING_KEY)
                ->value('value') ?? []),
        );
    }
}
