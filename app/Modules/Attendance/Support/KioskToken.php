<?php

namespace App\Modules\Attendance\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Short-lived rotating tokens for the QR kiosk flow. A token maps only to
 * {tenant_id, kiosk_id} — it never carries employee identity. Identity
 * always comes from the scanning phone's own logged-in session; the token
 * just tells the ESS page "you scanned kiosk X" and lets the resulting
 * attendance log record which kiosk was used. This is a UX convenience (a
 * photographed QR goes stale within seconds), not an access-control layer.
 */
class KioskToken
{
    public const TTL_SECONDS = 90;

    public static function mint(int $tenantId, int $kioskId): string
    {
        $token = Str::random(32);
        Cache::put(self::cacheKey($token), ['tenant_id' => $tenantId, 'kiosk_id' => $kioskId], self::TTL_SECONDS);

        return $token;
    }

    /**
     * Validates a shared rotating token for the given tenant.
     * Returns the kiosk_id on success, null on any failure — expired,
     * unknown, or minted under a different tenant.
     */
    public static function consume(string $token, int $tenantId): ?int
    {
        // This is a shared, rotating kiosk code. Keep it valid for its short
        // TTL so multiple employees and a repeat clock-out scan can use it.
        $payload = Cache::get(self::cacheKey($token));

        if ($payload === null || $payload['tenant_id'] !== $tenantId) {
            return null;
        }

        return $payload['kiosk_id'];
    }

    private static function cacheKey(string $token): string
    {
        return 'attendance-kiosk-token:'.$token;
    }
}
