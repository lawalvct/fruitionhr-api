<?php

use App\Core\Notifications\SystemNotification;
use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;

test('a user can list notifications and mark all as read', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $user->notify(new SystemNotification('Hello', 'Welcome to FruitionHR', '/dashboard', 'info'));
    $user->notify(new SystemNotification('Reminder', 'Finalize attendance', '/attendance', 'warning'));

    $response = $this->actingAs($user)->getJson('/api/v1/notifications')->assertOk();

    expect($response->json('data.unread_count'))->toBe(2)
        ->and($response->json('data.notifications'))->toHaveCount(2)
        ->and($response->json('data.notifications.0.title'))->toBeString();

    $this->actingAs($user)->postJson('/api/v1/notifications/read-all')->assertOk();

    $after = $this->actingAs($user)->getJson('/api/v1/notifications')->assertOk();
    expect($after->json('data.unread_count'))->toBe(0);
});
