<?php

use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketMessage;
use App\Modules\Support\Notifications\SupportTicketRepliedNotification;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Notification;

/**
 * Two things matter most here: a company only ever sees its own tickets, and
 * internal agent notes never reach a customer.
 */
function supportTenant(): array
{
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id, 'email_verified_at' => now()]);

    return [$tenant, $user];
}

function openTicket(User $user, string $subject = 'Payroll will not run'): int
{
    return test()->actingAs($user)->postJson('/api/v1/support/tickets', [
        'subject' => $subject,
        'body' => 'We tried to run payroll for August and it fails at the approval step.',
        'category' => 'payroll',
        'priority' => 'high',
    ])->assertCreated()->json('data.id');
}

test('a company can raise a ticket and it starts open', function (): void {
    [, $user] = supportTenant();

    $response = $this->actingAs($user)->postJson('/api/v1/support/tickets', [
        'subject' => 'Payroll will not run',
        'body' => 'It fails at the approval step every time.',
        'category' => 'payroll',
        'priority' => 'high',
    ])->assertCreated();

    expect($response->json('data.status'))->toBe(SupportTicket::STATUS_OPEN)
        ->and($response->json('data.reference'))->toStartWith('TKT-')
        // The opening message is part of the thread.
        ->and($response->json('data.messages'))->toHaveCount(1);
});

test('references are sequential and unique', function (): void {
    [, $user] = supportTenant();

    $first = $this->actingAs($user)->postJson('/api/v1/support/tickets', [
        'subject' => 'First issue', 'body' => 'Something is broken here.',
    ])->json('data.reference');

    $second = $this->actingAs($user)->postJson('/api/v1/support/tickets', [
        'subject' => 'Second issue', 'body' => 'Something else is broken.',
    ])->json('data.reference');

    expect($first)->not->toBe($second)
        ->and($first)->toMatch('/^TKT-\d{6}$/');
});

test('a company only sees its own tickets', function (): void {
    [, $alphaUser] = supportTenant();
    openTicket($alphaUser, 'Alpha problem');

    [, $betaUser] = supportTenant();
    openTicket($betaUser, 'Beta problem');

    $subjects = collect(
        $this->actingAs($betaUser)->getJson('/api/v1/support/tickets')->assertOk()->json('data')
    )->pluck('subject');

    expect($subjects)->toContain('Beta problem')->not->toContain('Alpha problem');
});

test('a company cannot open another company ticket by id', function (): void {
    [, $alphaUser] = supportTenant();
    $alphaTicket = openTicket($alphaUser);

    [, $betaUser] = supportTenant();

    $this->actingAs($betaUser)->getJson("/api/v1/support/tickets/{$alphaTicket}")->assertNotFound();
    $this->actingAs($betaUser)
        ->postJson("/api/v1/support/tickets/{$alphaTicket}/messages", ['body' => 'Let me in'])
        ->assertNotFound();
});

test('an internal note never reaches the customer', function (): void {
    Notification::fake();
    [, $user] = supportTenant();
    $ticketId = openTicket($user);

    $this->actingAs(User::factory()->platformAdministrator()->create())
        ->postJson("/api/admin/v1/support/tickets/{$ticketId}/messages", [
            'body' => 'Customer is on the free plan, deprioritise.',
            'internal' => true,
        ])->assertOk();

    $customerThread = $this->actingAs($user)
        ->getJson("/api/v1/support/tickets/{$ticketId}")->assertOk();

    expect(json_encode($customerThread->json()))->not->toContain('deprioritise');
    expect($customerThread->json('data.messages'))->toHaveCount(1);

    // ...but an agent sees it.
    $agentThread = $this->actingAs(User::factory()->platformAdministrator()->create())
        ->getJson("/api/admin/v1/support/tickets/{$ticketId}")->assertOk();

    expect(json_encode($agentThread->json()))->toContain('deprioritise');
});

test('an internal note does not email the customer or move the ticket', function (): void {
    Notification::fake();
    [, $user] = supportTenant();
    $ticketId = openTicket($user);

    $this->actingAs(User::factory()->platformAdministrator()->create())
        ->postJson("/api/admin/v1/support/tickets/{$ticketId}/messages", [
            'body' => 'Checking with engineering.',
            'internal' => true,
        ])->assertOk();

    Notification::assertNothingSent();
    // Still waiting on us, not on them.
    expect(SupportTicket::withoutGlobalScopes()->find($ticketId)->status)->toBe(SupportTicket::STATUS_OPEN);
});

test('an agent reply hands the ticket back to the customer and emails them', function (): void {
    Notification::fake();
    [, $user] = supportTenant();
    $ticketId = openTicket($user);

    $this->actingAs(User::factory()->platformAdministrator()->create())
        ->postJson("/api/admin/v1/support/tickets/{$ticketId}/messages", [
            'body' => 'Please try approving as the payroll owner and let us know.',
        ])->assertOk();

    $ticket = SupportTicket::withoutGlobalScopes()->find($ticketId);

    expect($ticket->status)->toBe(SupportTicket::STATUS_WAITING_ON_CUSTOMER)
        ->and($ticket->first_responded_at)->not->toBeNull();

    Notification::assertSentTo(
        $user,
        SupportTicketRepliedNotification::class,
    );
});

test('a customer reply reopens a ticket that was waiting on them', function (): void {
    Notification::fake();
    [, $user] = supportTenant();
    $ticketId = openTicket($user);

    $this->actingAs(User::factory()->platformAdministrator()->create())
        ->postJson("/api/admin/v1/support/tickets/{$ticketId}/messages", ['body' => 'Any update?'])
        ->assertOk();

    $this->actingAs($user)
        ->postJson("/api/v1/support/tickets/{$ticketId}/messages", ['body' => 'Still failing.'])
        ->assertOk();

    expect(SupportTicket::withoutGlobalScopes()->find($ticketId)->status)
        ->toBe(SupportTicket::STATUS_OPEN);
});

test('a resolved ticket reopens when the customer says it is not fixed', function (): void {
    Notification::fake();
    [, $user] = supportTenant();
    $ticketId = openTicket($user);
    $admin = User::factory()->platformAdministrator()->create();

    $this->actingAs($admin)->postJson("/api/admin/v1/support/tickets/{$ticketId}/status", [
        'status' => SupportTicket::STATUS_RESOLVED,
    ])->assertOk();

    $this->actingAs($user)
        ->postJson("/api/v1/support/tickets/{$ticketId}/messages", ['body' => 'This is still broken.'])
        ->assertOk();

    expect(SupportTicket::withoutGlobalScopes()->find($ticketId)->status)
        ->toBe(SupportTicket::STATUS_OPEN);
});

test('a closed ticket cannot be replied to', function (): void {
    Notification::fake();
    [, $user] = supportTenant();
    $ticketId = openTicket($user);

    $this->actingAs($user)->postJson("/api/v1/support/tickets/{$ticketId}/close")->assertOk();

    $this->actingAs($user)
        ->postJson("/api/v1/support/tickets/{$ticketId}/messages", ['body' => 'One more thing'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});

test('support stays reachable when the workspace is read-only', function (): void {
    // Someone who cannot pay is exactly who needs to reach us.
    [$tenant, $user] = supportTenant();
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => Subscription::STATUS_EXPIRED,
        'current_period_end' => now()->subDay(),
    ]);

    $this->actingAs($user)->postJson('/api/v1/support/tickets', [
        'subject' => 'Cannot access payroll',
        'body' => 'Our subscription lapsed and we need help sorting the payment.',
    ])->assertCreated();
});

test('support is reachable before the email is verified', function (): void {
    [$tenant] = supportTenant();
    $unverified = User::factory()->create(['tenant_id' => $tenant->id, 'email_verified_at' => null]);

    $this->actingAs($unverified)->postJson('/api/v1/support/tickets', [
        'subject' => 'Never received my code',
        'body' => 'The verification email has not arrived after several tries.',
    ])->assertCreated();
});

test('support is closed to guests', function (): void {
    $this->getJson('/api/v1/support/tickets')->assertUnauthorized();
    $this->postJson('/api/v1/support/tickets', [])->assertUnauthorized();
});

test('the platform queue spans every tenant and summarises the workload', function (): void {
    [, $alphaUser] = supportTenant();
    openTicket($alphaUser, 'Alpha issue');

    [, $betaUser] = supportTenant();
    openTicket($betaUser, 'Beta issue');

    $response = $this->actingAs(User::factory()->platformAdministrator()->create())
        ->getJson('/api/admin/v1/support/tickets')->assertOk();

    expect(collect($response->json('data'))->pluck('subject'))
        ->toContain('Alpha issue')->toContain('Beta issue');

    expect($response->json('summary.open'))->toBe(2)
        ->and($response->json('summary.unassigned'))->toBe(2);

    // Rows name the company so an agent knows who they are answering.
    expect(collect($response->json('data'))->pluck('company.name')->filter())->toHaveCount(2);
});

test('assigning a ticket picks it up', function (): void {
    [, $user] = supportTenant();
    $ticketId = openTicket($user);
    $agent = User::factory()->platformAdministrator()->create();

    $this->actingAs($agent)->postJson("/api/admin/v1/support/tickets/{$ticketId}/assign", [
        'assigned_to' => $agent->id,
    ])->assertOk()->assertJsonPath('data.status', SupportTicket::STATUS_IN_PROGRESS);
});

test('a ticket cannot be assigned to a tenant user', function (): void {
    [$tenant, $user] = supportTenant();
    $ticketId = openTicket($user);
    $tenantUser = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs(User::factory()->platformAdministrator()->create())
        ->postJson("/api/admin/v1/support/tickets/{$ticketId}/assign", ['assigned_to' => $tenantUser->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('assigned_to');
});

test('status changes are recorded in the audit log', function (): void {
    [, $user] = supportTenant();
    $ticketId = openTicket($user);

    $this->actingAs(User::factory()->platformAdministrator()->create())
        ->postJson("/api/admin/v1/support/tickets/{$ticketId}/status", [
            'status' => SupportTicket::STATUS_RESOLVED,
        ])->assertOk()->assertJsonPath('data.status', SupportTicket::STATUS_RESOLVED);

    $this->assertDatabaseHas('platform_activities', ['action' => 'support.status_changed']);
});

test('the queue is closed to tenant users', function (): void {
    [, $user] = supportTenant();

    $this->actingAs($user)->getJson('/api/admin/v1/support/tickets')->assertForbidden();
});

test('message counts exclude internal notes', function (): void {
    Notification::fake();
    [, $user] = supportTenant();
    $ticketId = openTicket($user);
    $admin = User::factory()->platformAdministrator()->create();

    $this->actingAs($admin)->postJson("/api/admin/v1/support/tickets/{$ticketId}/messages", [
        'body' => 'Internal only', 'internal' => true,
    ])->assertOk();

    $row = collect($this->actingAs($user)->getJson('/api/v1/support/tickets')->json('data'))->first();

    // Only the customer's opening message counts.
    expect($row['message_count'])->toBe(1);
    expect(SupportTicketMessage::withoutGlobalScopes()->where('support_ticket_id', $ticketId)->count())->toBe(2);
});
