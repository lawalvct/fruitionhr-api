<?php

use App\Models\User;
use App\Modules\Auth\Notifications\EmailVerificationCodeNotification;
use Illuminate\Support\Facades\Notification;

test('registration sends a verification code which activates the owner email', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/register', [
        'company_name' => 'Verified Company',
        'name' => 'Ada Owner',
        'email' => 'ada@verified.test',
        'password' => 'Sup3r-Secret!',
        'password_confirmation' => 'Sup3r-Secret!',
    ])->assertCreated()
        ->assertJsonPath('data.is_email_verified', false);

    $user = User::query()->where('email', 'ada@verified.test')->firstOrFail();
    $code = null;

    Notification::assertSentTo(
        $user,
        EmailVerificationCodeNotification::class,
        function (EmailVerificationCodeNotification $notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        },
    );

    $this->postJson('/api/v1/email/verify', ['code' => $code])
        ->assertOk()
        ->assertJsonPath('data.is_email_verified', true);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('an incorrect verification code is rejected and counted', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/register', [
        'company_name' => 'Secure Company',
        'name' => 'Owner',
        'email' => 'owner@secure.test',
        'password' => 'Sup3r-Secret!',
        'password_confirmation' => 'Sup3r-Secret!',
    ])->assertCreated();

    $this->postJson('/api/v1/email/verify', ['code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    $this->assertDatabaseHas('email_verification_codes', [
        'user_id' => User::query()->where('email', 'owner@secure.test')->value('id'),
        'attempts' => 1,
    ]);
});

test('unverified owners cannot access tenant modules', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/register', [
        'company_name' => 'Pending Company',
        'name' => 'Owner',
        'email' => 'owner@pending.test',
        'password' => 'Sup3r-Secret!',
        'password_confirmation' => 'Sup3r-Secret!',
    ])->assertCreated();

    $this->getJson('/api/v1/branches')
        ->assertForbidden()
        ->assertJsonPath('message', 'Verify your email address to continue.');
});
