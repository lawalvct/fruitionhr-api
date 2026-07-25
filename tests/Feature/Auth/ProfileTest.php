<?php

use App\Models\User;
use App\Modules\Auth\Models\EmailVerificationCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

test('a user can update their display name', function () {
    $user = User::factory()->create(['name' => 'Old Name', 'email' => 'user@profile.test']);
    $this->actingAs($user);

    $this->putJson('/api/v1/profile', [
        'name' => 'New Name',
        'email' => 'user@profile.test',
    ])->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.email', 'user@profile.test');

    expect($user->refresh()->name)->toBe('New Name');
});

test('a user can update phone, timezone, and bio', function () {
    $user = User::factory()->create(['email' => 'fields@profile.test']);
    $this->actingAs($user);

    $this->putJson('/api/v1/profile', [
        'name' => $user->name,
        'email' => 'fields@profile.test',
        'phone' => '+2348012345678',
        'timezone' => 'Africa/Lagos',
        'bio' => 'People operations lead.',
    ])->assertOk()
        ->assertJsonPath('data.phone', '+2348012345678')
        ->assertJsonPath('data.timezone', 'Africa/Lagos')
        ->assertJsonPath('data.bio', 'People operations lead.');

    $user->refresh();
    expect($user->phone)->toBe('+2348012345678')
        ->and($user->timezone)->toBe('Africa/Lagos')
        ->and($user->bio)->toBe('People operations lead.');
});

test('an invalid timezone is rejected', function () {
    $user = User::factory()->create(['email' => 'tz@profile.test']);
    $this->actingAs($user);

    $this->putJson('/api/v1/profile', [
        'name' => $user->name,
        'email' => 'tz@profile.test',
        'timezone' => 'Mars/Olympus',
    ])->assertUnprocessable()->assertJsonValidationErrors('timezone');
});

test('a user can upload, serve, and remove an avatar', function () {
    Storage::fake('local');
    $user = User::factory()->create(['email' => 'avatar@profile.test']);
    $this->actingAs($user);

    $this->postJson('/api/v1/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('me.jpg', 200, 200),
    ])->assertOk()->assertJsonPath('data.avatar_url', '/api/v1/profile/avatar');

    $user->refresh();
    expect($user->avatar_path)->not->toBeNull();
    Storage::disk('local')->assertExists($user->avatar_path);

    $this->get('/api/v1/profile/avatar')->assertOk();

    $this->deleteJson('/api/v1/profile/avatar')
        ->assertOk()
        ->assertJsonPath('data.avatar_url', null);

    expect($user->refresh()->avatar_path)->toBeNull();
});

test('avatar upload rejects non-image files', function () {
    Storage::fake('local');
    $user = User::factory()->create(['email' => 'badavatar@profile.test']);
    $this->actingAs($user);

    $this->postJson('/api/v1/profile/avatar', [
        'avatar' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
    ])->assertUnprocessable()->assertJsonValidationErrors('avatar');
});

test('keeping the same email does not reset verification', function () {
    $user = User::factory()->create(['email' => 'same@profile.test']);
    $this->actingAs($user);

    $this->putJson('/api/v1/profile', [
        'name' => 'Renamed',
        'email' => 'same@profile.test',
    ])->assertOk()->assertJsonPath('data.is_email_verified', true);

    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});

test('changing email resets verification and issues a new code', function () {
    $user = User::factory()->create(['email' => 'old@profile.test']);
    $this->actingAs($user);

    $this->putJson('/api/v1/profile', [
        'name' => $user->name,
        'email' => 'new@profile.test',
    ])->assertOk()
        ->assertJsonPath('data.email', 'new@profile.test')
        ->assertJsonPath('data.is_email_verified', false);

    $user->refresh();
    expect($user->email)->toBe('new@profile.test')
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and(EmailVerificationCode::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('email must be unique across users', function () {
    User::factory()->create(['email' => 'taken@profile.test']);
    $user = User::factory()->create(['email' => 'me@profile.test']);
    $this->actingAs($user);

    $this->putJson('/api/v1/profile', [
        'name' => $user->name,
        'email' => 'taken@profile.test',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

test('a user can change their password with the correct current password', function () {
    $user = User::factory()->create(); // factory password is 'password'
    $this->actingAs($user);

    $this->putJson('/api/v1/profile/password', [
        'current_password' => 'password',
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ])->assertOk();

    expect(Hash::check('brand-new-secret', $user->refresh()->password))->toBeTrue();
});

test('changing password rejects a wrong current password', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->putJson('/api/v1/profile/password', [
        'current_password' => 'not-my-password',
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
});

test('changing password enforces confirmation and minimum length', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->putJson('/api/v1/profile/password', [
        'current_password' => 'password',
        'password' => 'short',
        'password_confirmation' => 'mismatch',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
});

test('profile editing requires authentication', function () {
    $this->putJson('/api/v1/profile', [
        'name' => 'Nobody',
        'email' => 'nobody@profile.test',
    ])->assertUnauthorized();
});
