<?php

use App\Core\Notifications\SystemNotification;
use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;

/**
 * The notification bell lives in the shared AppShell, so it renders on the
 * tenant dashboard and the admin console alike. Its endpoint must therefore
 * answer for both kinds of user.
 *
 * It used to sit in the tenant route group, which meant every admin page load
 * fired a 403 ("This account is not attached to a company") once a minute,
 * behind a bell that silently showed zero.
 */

test('a platform administrator can read their notification inbox', function (): void {
    $admin = User::factory()->platformAdministrator()->create();

    $this->actingAs($admin)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0)
        ->assertJsonPath('data.notifications', []);
});

test('an administrator sees notifications addressed to them', function (): void {
    $admin = User::factory()->platformAdministrator()->create();
    $admin->notify(new SystemNotification('Heads up', 'A company needs attention.', null, 'warning'));

    $body = $this->actingAs($admin)->getJson('/api/v1/notifications')->assertOk()->json('data');

    expect($body['unread_count'])->toBe(1)
        ->and($body['notifications'][0]['title'])->toBe('Heads up')
        ->and($body['notifications'][0]['type'])->toBe('warning');
});

test('an administrator can clear their unread badge', function (): void {
    $admin = User::factory()->platformAdministrator()->create();
    $admin->notify(new SystemNotification('Heads up', 'A company needs attention.'));

    $this->actingAs($admin)->postJson('/api/v1/notifications/read-all')->assertOk();

    expect($this->actingAs($admin)->getJson('/api/v1/notifications')->json('data.unread_count'))->toBe(0);
});

test('a tenant user still reads their own inbox', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE]);
    $user->notify(new SystemNotification('Leave approved', 'Your request was approved.'));

    $this->actingAs($user)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 1);
});

test('one user never reads another user notifications', function (): void {
    $tenant = Tenant::factory()->create();
    $mine = User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE]);
    $theirs = User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE]);

    $theirs->notify(new SystemNotification('Private', 'Not for anyone else.'));

    // The inbox is keyed on the notifiable, not on the tenant — moving these
    // routes out of the tenant group must not have widened what is visible.
    $this->actingAs($mine)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0)
        ->assertJsonPath('data.notifications', []);
});

test('the inbox is still closed to guests', function (): void {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
    $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
});
