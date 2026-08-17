<?php

use App\Models\User;
use App\Modules\Admin\Models\PlatformRole;
use App\Modules\Admin\Services\PlatformAdminNotifier;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Services\SupportTicketService;
use App\Modules\Tenancy\Actions\RegisterTenant;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Authorization\PlatformAbilities;
use App\Support\Tenancy\CurrentTenant;

/**
 * What the FruitionHR team gets told, and who gets told.
 *
 * Routing is by ability rather than seniority: the point of the bell is that
 * it is worth looking at, which stops being true the moment a content editor
 * is paged about a failed payment they cannot act on.
 */

function staffWith(array $abilities): User
{
    $role = PlatformRole::factory()->granting($abilities)->create();

    return User::factory()->platformStaff($role)->create();
}

test('a new support ticket reaches the support team and nobody else', function (): void {
    $agent = staffWith([PlatformAbilities::SUPPORT]);
    $editor = staffWith([PlatformAbilities::BLOG]);
    $owner = User::factory()->platformAdministrator()->create();

    $tenant = Tenant::factory()->create(['name' => 'Alpha Foods Ltd']);
    app(CurrentTenant::class)->set($tenant);
    $customer = User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE]);

    app(SupportTicketService::class)->open($customer, $tenant->id, [
        'subject' => 'Payroll will not run',
        'body' => 'It fails every time we try.',
    ]);

    expect($agent->refresh()->unreadNotifications)->toHaveCount(1)
        // An owner holds every ability, so they hear about it too.
        ->and($owner->refresh()->unreadNotifications)->toHaveCount(1)
        // The blog editor cannot even open the support queue.
        ->and($editor->refresh()->unreadNotifications)->toHaveCount(0);

    $notification = $agent->unreadNotifications->first();
    expect($notification->data['title'])->toBe('New support ticket')
        ->and($notification->data['body'])->toContain('Alpha Foods Ltd')
        ->and($notification->data['body'])->toContain('Payroll will not run')
        ->and($notification->data['action_url'])->toBe('/support');
});

test('a customer reply puts the ticket back in front of the team', function (): void {
    $agent = staffWith([PlatformAbilities::SUPPORT]);

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $customer = User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE]);

    $service = app(SupportTicketService::class);
    $ticket = $service->open($customer, $tenant->id, ['subject' => 'Still stuck', 'body' => 'Opening message.']);

    $service->reply($ticket, $customer, 'Any update on this?', 'customer');

    // One for the ticket, one for the reply — both need somebody to pick up.
    // Asserted as a set: the two share a timestamp, so their relative order is
    // a tie-break and not something worth pinning a test to.
    $titles = $agent->refresh()->unreadNotifications->pluck('data.title');

    expect($titles)->toHaveCount(2)
        ->and($titles)->toContain('New support ticket')
        ->and($titles)->toContain('Customer replied');
});

test('an internal note does not page the team about their own aside', function (): void {
    $agent = staffWith([PlatformAbilities::SUPPORT]);

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $customer = User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE]);

    $service = app(SupportTicketService::class);
    $ticket = $service->open($customer, $tenant->id, ['subject' => 'Question', 'body' => 'Opening message.']);
    $service->reply($ticket, $agent, 'Checking with payroll.', 'agent', internal: true);

    expect($agent->refresh()->unreadNotifications)->toHaveCount(1);
});

test('a new company sign-up reaches whoever manages companies', function (): void {
    $companyAdmin = staffWith([PlatformAbilities::TENANTS]);
    $agent = staffWith([PlatformAbilities::SUPPORT]);

    app(RegisterTenant::class)->execute([
        'company_name' => 'Beta Logistics Ltd',
        'name' => 'Chidi Okafor',
        'email' => 'chidi@beta.test',
        'password' => 'Sup3r-Secret!',
    ]);

    expect($companyAdmin->refresh()->unreadNotifications)->toHaveCount(1)
        ->and($agent->refresh()->unreadNotifications)->toHaveCount(0);

    $notification = $companyAdmin->unreadNotifications->first();
    expect($notification->data['body'])->toContain('Beta Logistics Ltd')
        ->and($notification->data['type'])->toBe('success');
});

test('notifying ourselves never breaks the thing that happened', function (): void {
    // No administrator can reach billing at all, so there is nobody to tell.
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $customer = User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE]);

    // A company must still be able to raise a ticket when the platform has no
    // support staff configured — their request cannot depend on our staffing.
    $ticket = app(SupportTicketService::class)->open($customer, $tenant->id, [
        'subject' => 'Nobody home',
        'body' => 'Is anyone there?',
    ]);

    expect($ticket->exists)->toBeTrue()
        ->and($ticket->status)->toBe(SupportTicket::STATUS_OPEN);
});

test('a disabled administrator stops being told anything', function (): void {
    $agent = staffWith([PlatformAbilities::SUPPORT]);
    $agent->forceFill(['status' => User::STATUS_DISABLED])->save();

    app(PlatformAdminNotifier::class)->notify(
        PlatformAbilities::SUPPORT,
        'Something happened',
        'Details here.',
    );

    expect($agent->refresh()->unreadNotifications)->toHaveCount(0);
});

test('a tenant user is never treated as platform staff', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE]);

    app(PlatformAdminNotifier::class)->notify(
        PlatformAbilities::SUPPORT,
        'Internal only',
        'Not for customers.',
    );

    expect($user->refresh()->unreadNotifications)->toHaveCount(0);
});
