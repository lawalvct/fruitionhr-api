<?php

use App\Models\User;
use App\Modules\Admin\Models\PlatformRole;
use App\Support\Authorization\PlatformAbilities;
use App\Support\Http\EnsurePlatformAbility;
use Illuminate\Support\Facades\Route;

/**
 * EnsureSuperAdmin decides who reaches the admin console at all. These tests
 * cover what happens next: which parts of it each member of staff can work on.
 *
 * The sidebar hides what somebody cannot reach, but that is decoration. A URL
 * can always be typed, so every assertion here goes through the API.
 */

/** One representative endpoint per ability, so a leak anywhere shows up. */
function abilityEndpoints(): array
{
    return [
        PlatformAbilities::DASHBOARD => '/api/admin/v1/dashboard',
        PlatformAbilities::TENANTS => '/api/admin/v1/tenants',
        PlatformAbilities::USERS => '/api/admin/v1/users',
        PlatformAbilities::SUPPORT => '/api/admin/v1/support/tickets',
        PlatformAbilities::BILLING => '/api/admin/v1/billing/plans',
        PlatformAbilities::CAREERS => '/api/admin/v1/recruitment/vacancies',
        PlatformAbilities::BLOG => '/api/admin/v1/blog-posts',
        PlatformAbilities::ACTIVITY => '/api/admin/v1/activity',
        PlatformAbilities::ADMINISTRATORS => '/api/admin/v1/administrators',
    ];
}

function staffGranted(array $abilities): User
{
    $role = PlatformRole::factory()->granting($abilities)->create();

    return User::factory()->platformStaff($role)->create();
}

test('a blog-only editor reaches the blog and nothing else', function (): void {
    $this->actingAs(staffGranted([PlatformAbilities::BLOG]));

    foreach (abilityEndpoints() as $ability => $url) {
        $expected = $ability === PlatformAbilities::BLOG ? 200 : 403;
        $this->getJson($url)->assertStatus($expected);
    }
});

test('a support agent works the queue but cannot touch billing or companies', function (): void {
    $this->actingAs(staffGranted([PlatformAbilities::SUPPORT, PlatformAbilities::USERS]));

    $this->getJson('/api/admin/v1/support/tickets')->assertOk();
    $this->getJson('/api/admin/v1/users')->assertOk();

    $this->getJson('/api/admin/v1/billing/plans')->assertForbidden();
    $this->getJson('/api/admin/v1/tenants')->assertForbidden();
    $this->getJson('/api/admin/v1/dashboard')->assertForbidden();
});

test('an ability gates writes as well as reads', function (): void {
    // Read-only leaks are bad; a support agent suspending a customer is worse.
    $this->actingAs(staffGranted([PlatformAbilities::SUPPORT]));

    $this->postJson('/api/admin/v1/tenants/1/suspend', ['reason' => 'testing'])->assertForbidden();
    $this->postJson('/api/admin/v1/blog-posts', ['title' => 'Hello'])->assertForbidden();
    $this->putJson('/api/admin/v1/billing/gateways', [])->assertForbidden();
});

test('an owner reaches every section', function (): void {
    $this->actingAs(User::factory()->platformAdministrator()->create());

    foreach (abilityEndpoints() as $url) {
        $this->getJson($url)->assertOk();
    }
});

test('staff cannot manage administrators or roles however they are granted', function (): void {
    // Every assignable ability at once — still not the keys to the kingdom.
    $this->actingAs(staffGranted(PlatformAbilities::assignable()));

    $this->getJson('/api/admin/v1/administrators')->assertForbidden();
    $this->getJson('/api/admin/v1/platform-roles')->assertForbidden();
    $this->postJson('/api/admin/v1/platform-roles', [
        'name' => 'Self promotion',
        'abilities' => [PlatformAbilities::BLOG],
    ])->assertForbidden();
});

test('a custom role can never carry the administrators ability', function (): void {
    $this->actingAs(User::factory()->platformAdministrator()->create());

    // The one escalation route worth closing: a role that grants the power to
    // hand out power. Owners delegate by giving somebody the Owner role.
    $this->postJson('/api/admin/v1/platform-roles', [
        'name' => 'Shadow owner',
        'abilities' => [PlatformAbilities::BLOG, PlatformAbilities::ADMINISTRATORS],
    ])->assertStatus(422)->assertJsonValidationErrors('abilities.1');
});

test('platform staff with no role at all can reach nothing', function (): void {
    $stranded = User::factory()->platformAdministrator()->create();
    $stranded->forceFill(['platform_role_id' => null])->save();

    $this->actingAs($stranded);

    // Fails closed. A missing role must not read as "unrestricted".
    foreach (abilityEndpoints() as $url) {
        $this->getJson($url)->assertForbidden();
    }
});

test('the console tells each administrator what they can reach', function (): void {
    $this->actingAs(staffGranted([PlatformAbilities::BLOG, PlatformAbilities::SUPPORT]));

    $me = $this->getJson('/api/v1/me')->assertOk();

    expect($me->json('data.platform_abilities'))
        ->toEqualCanonicalizing([PlatformAbilities::BLOG, PlatformAbilities::SUPPORT]);
});

test('the owner role tracks the catalogue rather than a stored list', function (): void {
    $owner = PlatformRole::query()->where('slug', PlatformRole::OWNER_SLUG)->firstOrFail();

    // Simulates a section added after this role was seeded: the stored column
    // is stale, but Owner must still reach everything or a new part of the
    // admin becomes unreachable for everybody.
    $owner->forceFill(['abilities' => [PlatformAbilities::BLOG]])->save();

    expect($owner->refresh()->grantedAbilities())->toEqualCanonicalizing(PlatformAbilities::all());
});

test('the built-in owner role cannot be edited or deleted', function (): void {
    $this->actingAs(User::factory()->platformAdministrator()->create());
    $owner = PlatformRole::query()->where('slug', PlatformRole::OWNER_SLUG)->firstOrFail();

    $this->putJson("/api/admin/v1/platform-roles/{$owner->id}", [
        'abilities' => [PlatformAbilities::BLOG],
    ])->assertStatus(422);

    $this->deleteJson("/api/admin/v1/platform-roles/{$owner->id}")->assertStatus(422);
});

test('a role still held by somebody cannot be deleted', function (): void {
    $this->actingAs(User::factory()->platformAdministrator()->create());

    $role = PlatformRole::factory()->granting([PlatformAbilities::BLOG])->create();
    User::factory()->platformStaff($role)->create();

    $this->deleteJson("/api/admin/v1/platform-roles/{$role->id}")
        ->assertStatus(422)
        ->assertJsonPath('errors.role.0', 'One administrator still has this role. Move them to another one first.');

    $role->administrators()->delete();
    $this->deleteJson("/api/admin/v1/platform-roles/{$role->id}")->assertOk();
});

test('an owner cannot change their own access', function (): void {
    $owner = User::factory()->platformAdministrator()->create();
    $this->actingAs($owner);

    $staffRole = PlatformRole::factory()->granting([PlatformAbilities::BLOG])->create();

    $this->putJson("/api/admin/v1/administrators/{$owner->id}", [
        'platform_role_id' => $staffRole->id,
    ])->assertStatus(422)->assertJsonValidationErrors('platform_role_id');
});

test('the last owner cannot be demoted or disabled', function (): void {
    $owner = User::factory()->platformAdministrator()->create();
    $other = User::factory()->platformAdministrator()->create();
    $this->actingAs($owner);

    $staffRole = PlatformRole::factory()->granting([PlatformAbilities::BLOG])->create();

    // Demoting the second owner is fine — one remains.
    $this->putJson("/api/admin/v1/administrators/{$other->id}", [
        'platform_role_id' => $staffRole->id,
    ])->assertOk();

    // Now $owner is the only one left, and nobody could grant access again.
    $this->postJson("/api/admin/v1/administrators/{$owner->id}/disable", ['reason' => 'testing'])
        ->assertStatus(422);
});

test('changing a role changes what its holders can reach, without touching them', function (): void {
    $role = PlatformRole::factory()->granting([PlatformAbilities::BLOG])->create();
    $editor = User::factory()->platformStaff($role)->create();

    $this->actingAs($editor)->getJson('/api/admin/v1/support/tickets')->assertForbidden();

    $this->actingAs(User::factory()->platformAdministrator()->create())
        ->putJson("/api/admin/v1/platform-roles/{$role->id}", [
            'abilities' => [PlatformAbilities::BLOG, PlatformAbilities::SUPPORT],
        ])->assertOk();

    // fresh(): actingAs reuses the in-memory model, which is still holding the
    // role relation loaded during the first request. A real request resolves
    // the user from the database every time.
    // The whole point of naming roles: one edit moves everyone who holds it.
    $this->actingAs($editor->fresh())->getJson('/api/admin/v1/support/tickets')->assertOk();
});

test('a role must grant something', function (): void {
    $this->actingAs(User::factory()->platformAdministrator()->create());

    $this->postJson('/api/admin/v1/platform-roles', [
        'name' => 'Empty',
        'abilities' => [],
    ])->assertStatus(422)->assertJsonValidationErrors('abilities');
});

test('every admin route is gated on an ability', function (): void {
    // The one failure this design cannot survive quietly: a new admin route
    // added outside a middleware group is reachable by every member of staff,
    // however carefully the sidebar hides it.
    $ungated = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/admin/v1'))
        // Both spellings: routes carry the unresolved alias ("platform:blog"),
        // while anything registered by class name carries the FQCN.
        ->reject(fn ($route): bool => collect($route->gatherMiddleware())
            ->contains(fn ($middleware): bool => is_string($middleware)
                && (str_starts_with($middleware, 'platform:')
                    || str_starts_with($middleware, EnsurePlatformAbility::class))))
        ->map(fn ($route): string => $route->methods()[0].' '.$route->uri())
        ->values()
        ->all();

    expect($ungated)->toBe([], 'These admin routes are not behind any platform ability: '.implode(', ', $ungated));
});
