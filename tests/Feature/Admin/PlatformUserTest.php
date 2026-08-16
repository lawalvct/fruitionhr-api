<?php

use App\Models\User;
use App\Modules\Auth\Notifications\AdminPasswordResetNotification;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * The platform user directory exists so support can answer "this person
 * cannot sign in". It reads every tenant's users, so the gate matters as much
 * as the search itself.
 */
function platformUserFor(Tenant $tenant, string $name, string $email, array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'name' => $name,
        'email' => $email,
    ], $attributes));
}

test('the directory spans every tenant and names the owning company', function (): void {
    $alpha = Tenant::factory()->create(['name' => 'Alpha Foods Ltd']);
    $beta = Tenant::factory()->create(['name' => 'Beta Logistics Ltd']);

    platformUserFor($alpha, 'Ada Alpha', 'ada@alpha.test');
    platformUserFor($beta, 'Chidi Beta', 'chidi@beta.test');

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $response = $this->getJson('/api/admin/v1/users')->assertOk();
    $emails = collect($response->json('data'))->pluck('email');

    expect($emails)->toContain('ada@alpha.test')->toContain('chidi@beta.test');

    $alphaRow = collect($response->json('data'))->firstWhere('email', 'ada@alpha.test');
    expect($alphaRow['company']['name'])->toBe('Alpha Foods Ltd');
});

test('users can be searched by email, name and company', function (): void {
    $alpha = Tenant::factory()->create(['name' => 'Alpha Foods Ltd']);
    $beta = Tenant::factory()->create(['name' => 'Beta Logistics Ltd']);
    platformUserFor($alpha, 'Ada Alpha', 'ada@alpha.test');
    platformUserFor($beta, 'Chidi Beta', 'chidi@beta.test');

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $byEmail = $this->getJson('/api/admin/v1/users?search=chidi@beta.test')->assertOk();
    expect(collect($byEmail->json('data'))->pluck('email')->all())->toBe(['chidi@beta.test']);

    $byCompany = $this->getJson('/api/admin/v1/users?search=Alpha Foods')->assertOk();
    expect(collect($byCompany->json('data'))->pluck('email')->all())->toBe(['ada@alpha.test']);
});

test('the directory can be filtered to administrators or tenant users', function (): void {
    $tenant = Tenant::factory()->create();
    platformUserFor($tenant, 'Tenant Person', 'person@tenant.test');
    $admin = User::factory()->platformAdministrator()->create(['name' => 'Platform Admin']);

    $this->actingAs($admin);

    $admins = $this->getJson('/api/admin/v1/users?type=administrator')->assertOk();
    expect(collect($admins->json('data'))->pluck('email')->all())->toBe([$admin->email]);

    $tenantUsers = $this->getJson('/api/admin/v1/users?type=tenant')->assertOk();
    expect(collect($tenantUsers->json('data'))->pluck('email')->all())->toBe(['person@tenant.test']);
});

test('unverified users can be isolated, which is the common support case', function (): void {
    $tenant = Tenant::factory()->create();
    platformUserFor($tenant, 'Verified', 'verified@tenant.test');
    platformUserFor($tenant, 'Stuck', 'stuck@tenant.test', ['email_verified_at' => null]);

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $response = $this->getJson('/api/admin/v1/users?verified=0&type=tenant')->assertOk();

    expect(collect($response->json('data'))->pluck('email')->all())->toBe(['stuck@tenant.test']);
});

test('an absent verified filter does not silently mean unverified', function (): void {
    $tenant = Tenant::factory()->create();
    platformUserFor($tenant, 'Verified', 'verified@tenant.test');
    platformUserFor($tenant, 'Stuck', 'stuck@tenant.test', ['email_verified_at' => null]);

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $emails = collect($this->getJson('/api/admin/v1/users?type=tenant')->assertOk()->json('data'))
        ->pluck('email');

    expect($emails)->toContain('verified@tenant.test')->toContain('stuck@tenant.test');
});

test('the summary counts users across the platform', function (): void {
    $tenant = Tenant::factory()->create();
    platformUserFor($tenant, 'One', 'one@tenant.test');
    platformUserFor($tenant, 'Two', 'two@tenant.test', ['email_verified_at' => null]);
    platformUserFor($tenant, 'Three', 'three@tenant.test', ['status' => User::STATUS_INVITED]);

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $response = $this->getJson('/api/admin/v1/users')->assertOk();

    // 3 tenant users + the acting administrator.
    expect($response->json('summary.total'))->toBe(4)
        ->and($response->json('summary.unverified'))->toBe(1)
        ->and($response->json('summary.invited'))->toBe(1);
});

test('support can force-verify a stuck user, and it is written to the audit log', function (): void {
    $tenant = Tenant::factory()->create();
    $stuck = platformUserFor($tenant, 'Stuck', 'stuck@tenant.test', ['email_verified_at' => null]);

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $this->postJson("/api/admin/v1/users/{$stuck->id}/verify-email")
        ->assertOk()
        ->assertJsonPath('data.is_email_verified', true);

    expect($stuck->fresh()->hasVerifiedEmail())->toBeTrue();

    $this->assertDatabaseHas('platform_activities', [
        'action' => 'user.email_verified',
        'subject_type' => 'user',
        'subject_id' => $stuck->id,
    ]);
});

test('force-verifying an already verified user is rejected', function (): void {
    $tenant = Tenant::factory()->create();
    $verified = platformUserFor($tenant, 'Fine', 'fine@tenant.test');

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $this->postJson("/api/admin/v1/users/{$verified->id}/verify-email")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('the directory is closed to tenant users and guests', function (): void {
    $this->getJson('/api/admin/v1/users')->assertUnauthorized();

    $tenantUser = User::factory()->create();
    $this->actingAs($tenantUser);

    $this->getJson('/api/admin/v1/users')->assertForbidden();
    $this->postJson("/api/admin/v1/users/{$tenantUser->id}/verify-email")->assertForbidden();
});

test('an admin can reset a password and the new one is emailed, never returned', function (): void {
    Notification::fake();
    $tenant = Tenant::factory()->create();
    $user = platformUserFor($tenant, 'Locked Out', 'locked@tenant.test');
    $oldHash = $user->password;

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $response = $this->postJson("/api/admin/v1/users/{$user->id}/reset-password")->assertOk();

    // The password changed...
    expect($user->fresh()->password)->not->toBe($oldHash);

    // ...and the plaintext went out by mail only.
    $emailed = null;
    Notification::assertSentTo($user, AdminPasswordResetNotification::class, function ($notification) use (&$emailed): bool {
        $emailed = $notification->password;

        return true;
    });

    // Readable format: lowercase words plus digits, e.g. copper-mango-river-482.
    expect($emailed)->toMatch('/^[a-z]+(-[a-z]+){2}-\d{3}$/');
    expect(Hash::check($emailed, $user->fresh()->password))->toBeTrue();

    // The response body must not carry the credential.
    expect(json_encode($response->json()))->not->toContain($emailed);
});

test('a password reset is refused when the email is not verified', function (): void {
    Notification::fake();
    $tenant = Tenant::factory()->create();
    $unverified = platformUserFor($tenant, 'Unproven', 'unproven@tenant.test', ['email_verified_at' => null]);
    $oldHash = $unverified->password;

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $this->postJson("/api/admin/v1/users/{$unverified->id}/reset-password")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    // Nothing sent, nothing changed.
    Notification::assertNothingSent();
    expect($unverified->fresh()->password)->toBe($oldHash);
});

test('resetting a password signs the user out everywhere', function (): void {
    Notification::fake();
    $tenant = Tenant::factory()->create();
    $user = platformUserFor($tenant, 'Signed In', 'signedin@tenant.test');
    $user->createToken('mobile');

    DB::table('sessions')->insert([
        'id' => 'session-under-test',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs(User::factory()->platformAdministrator()->create());
    $this->postJson("/api/admin/v1/users/{$user->id}/reset-password")->assertOk();

    expect($user->tokens()->count())->toBe(0);
    $this->assertDatabaseMissing('sessions', ['id' => 'session-under-test']);
});

test('an admin cannot reset their own password this way', function (): void {
    Notification::fake();
    $admin = User::factory()->platformAdministrator()->create();
    $this->actingAs($admin);

    $this->postJson("/api/admin/v1/users/{$admin->id}/reset-password")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user');

    Notification::assertNothingSent();
});

test('the reset is recorded without ever storing the password', function (): void {
    Notification::fake();
    $tenant = Tenant::factory()->create();
    $user = platformUserFor($tenant, 'Audited', 'audited@tenant.test');

    $this->actingAs(User::factory()->platformAdministrator()->create());
    $this->postJson("/api/admin/v1/users/{$user->id}/reset-password")->assertOk();

    $emailed = null;
    Notification::assertSentTo($user, AdminPasswordResetNotification::class, function ($notification) use (&$emailed): bool {
        $emailed = $notification->password;

        return true;
    });

    $activity = DB::table('platform_activities')
        ->where('action', 'user.password_reset')
        ->where('subject_id', $user->id)
        ->first();

    expect($activity)->not->toBeNull();
    expect(json_encode($activity))->not->toContain($emailed);
});

test('password reset is closed to tenant users and guests', function (): void {
    $tenant = Tenant::factory()->create();
    $target = platformUserFor($tenant, 'Target', 'target@tenant.test');

    $this->postJson("/api/admin/v1/users/{$target->id}/reset-password")->assertUnauthorized();

    $this->actingAs(User::factory()->create());
    $this->postJson("/api/admin/v1/users/{$target->id}/reset-password")->assertForbidden();
});
