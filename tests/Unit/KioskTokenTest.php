<?php

use App\Modules\Attendance\Support\KioskToken;

it('mints a token that resolves to the minting kiosk under the same tenant', function () {
    $token = KioskToken::mint(tenantId: 1, kioskId: 7);

    expect(KioskToken::consume($token, tenantId: 1))->toBe(7);
});

it('is single-use', function () {
    $token = KioskToken::mint(tenantId: 1, kioskId: 7);

    KioskToken::consume($token, tenantId: 1);

    expect(KioskToken::consume($token, tenantId: 1))->toBeNull();
});

it('rejects a token minted under a different tenant', function () {
    $token = KioskToken::mint(tenantId: 1, kioskId: 7);

    expect(KioskToken::consume($token, tenantId: 2))->toBeNull();
});

it('rejects an unknown token', function () {
    expect(KioskToken::consume('not-a-real-token', tenantId: 1))->toBeNull();
});
