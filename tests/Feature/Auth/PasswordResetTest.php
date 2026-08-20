<?php

use App\Models\User;
use App\Modules\Auth\Notifications\PasswordResetNotification;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

function resetUser(array $attributes = []): User
{
    $tenant = Tenant::factory()->create();

    return User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'ada@company.test',
        'password' => 'Old-Passw0rd!',
        ...$attributes,
    ]);
}

test('requesting a reset emails a link carrying a usable token', function () {
    Notification::fake();
    $user = resetUser();

    $this->postJson('/api/v1/forgot-password', ['email' => 'ada@company.test'])->assertOk();

    Notification::assertSentTo($user, PasswordResetNotification::class, function ($notification) use ($user) {
        parse_str((string) parse_url($notification->resetUrl, PHP_URL_QUERY), $query);

        expect($notification->resetUrl)->toStartWith(rtrim((string) config('app.frontend_url'), '/').'/reset-password')
            ->and($query['email'])->toBe($user->email);

        return Password::broker()->tokenExists($user, $query['token']);
    });
});

test('the reset link sets a new password and lets the user sign in', function () {
    $user = resetUser();
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/v1/reset-password', [
        'email' => 'ada@company.test',
        'token' => $token,
        'password' => 'Br@nd-New-Pass1',
        'password_confirmation' => 'Br@nd-New-Pass1',
    ])->assertOk();

    expect(Hash::check('Br@nd-New-Pass1', $user->fresh()->password))->toBeTrue();

    $this->postJson('/api/v1/login', [
        'email' => 'ada@company.test',
        'password' => 'Br@nd-New-Pass1',
    ])->assertOk();
});

test('a reset token cannot be replayed', function () {
    $user = resetUser();
    $token = Password::broker()->createToken($user);
    $payload = [
        'email' => 'ada@company.test',
        'token' => $token,
        'password' => 'Br@nd-New-Pass1',
        'password_confirmation' => 'Br@nd-New-Pass1',
    ];

    $this->postJson('/api/v1/reset-password', $payload)->assertOk();

    // Second use must fail: the broker deletes the token on success.
    $this->postJson('/api/v1/reset-password', [...$payload, 'password' => 'Attacker-Pass1!', 'password_confirmation' => 'Attacker-Pass1!'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('token');

    expect(Hash::check('Br@nd-New-Pass1', $user->fresh()->password))->toBeTrue();
});

test('an unknown email is answered exactly like a known one and sends nothing', function () {
    Notification::fake();
    resetUser();

    $known = $this->postJson('/api/v1/forgot-password', ['email' => 'ada@company.test'])->assertOk();
    $unknown = $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@nowhere.test'])->assertOk();

    // Byte-identical bodies: anything else turns this into an account oracle.
    expect($unknown->json('message'))->toBe($known->json('message'));
    Notification::assertCount(1);
});

test('a disabled account is never sent a reset link', function () {
    Notification::fake();
    resetUser(['status' => User::STATUS_DISABLED]);

    $this->postJson('/api/v1/forgot-password', ['email' => 'ada@company.test'])->assertOk();

    Notification::assertNothingSent();
});

test('resetting activates an invited employee who never opened their invitation', function () {
    $user = resetUser(['status' => User::STATUS_INVITED, 'email_verified_at' => null]);
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/v1/reset-password', [
        'email' => 'ada@company.test',
        'token' => $token,
        'password' => 'Br@nd-New-Pass1',
        'password_confirmation' => 'Br@nd-New-Pass1',
    ])->assertOk();

    $user->refresh();
    expect($user->status)->toBe(User::STATUS_ACTIVE)
        ->and($user->email_verified_at)->not->toBeNull();

    // Otherwise they'd be stuck: login rejects INVITED users.
    $this->postJson('/api/v1/login', [
        'email' => 'ada@company.test',
        'password' => 'Br@nd-New-Pass1',
    ])->assertOk();
});

test('an invalid token is rejected without revealing whether the account exists', function () {
    resetUser();

    $this->postJson('/api/v1/reset-password', [
        'email' => 'ada@company.test',
        'token' => 'not-a-real-token',
        'password' => 'Br@nd-New-Pass1',
        'password_confirmation' => 'Br@nd-New-Pass1',
    ])->assertUnprocessable()->assertJsonValidationErrors('token');

    $this->postJson('/api/v1/reset-password', [
        'email' => 'ghost@nowhere.test',
        'token' => 'not-a-real-token',
        'password' => 'Br@nd-New-Pass1',
        'password_confirmation' => 'Br@nd-New-Pass1',
    ])->assertUnprocessable()->assertJsonValidationErrors('token');
});

test('the new password must be confirmed and long enough', function () {
    $user = resetUser();
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/v1/reset-password', [
        'email' => 'ada@company.test',
        'token' => $token,
        'password' => 'short',
        'password_confirmation' => 'mismatch',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');

    expect(Hash::check('Old-Passw0rd!', $user->fresh()->password))->toBeTrue();
});

test('a second request inside the throttle window does not mint another token', function () {
    Notification::fake();
    resetUser();

    $this->postJson('/api/v1/forgot-password', ['email' => 'ada@company.test'])->assertOk();
    $this->postJson('/api/v1/forgot-password', ['email' => 'ada@company.test'])->assertOk();

    // Broker throttle (auth.passwords.users.throttle) stops inbox flooding,
    // and the caller cannot tell the difference from the response.
    Notification::assertCount(1);
});

test('a super admin reset link points at the admin surface', function () {
    Notification::fake();
    config(['app.admin_url' => 'https://admin.fruitionhr.test']);

    $admin = User::factory()->create([
        'tenant_id' => null,
        'email' => 'ops@fruitionhr.test',
        'is_super_admin' => true,
    ]);

    $this->postJson('/api/v1/forgot-password', ['email' => 'ops@fruitionhr.test'])->assertOk();

    Notification::assertSentTo($admin, PasswordResetNotification::class, fn ($notification) => str_starts_with($notification->resetUrl, 'https://admin.fruitionhr.test/reset-password'));
});
