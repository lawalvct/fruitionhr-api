<?php

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Signing in without a session is the one failure that looks like success.
 *
 * When SANCTUM_STATEFUL_DOMAINS does not list the caller's origin, Sanctum
 * never starts a session. Auth::guard('web')->login() then holds the user for
 * that request only, the response carries no session cookie, and the client is
 * told everything went fine. The next request is anonymous and answers 401 with
 * nothing in the logs to explain it — which is precisely how a deploy with the
 * wrong stateful domains strands somebody mid-onboarding.
 */

test('registering from the frontend issues a session cookie', function (): void {
    $response = $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/register', [
        'company_name' => 'Olayinka Venture',
        'name' => 'Lawal',
        'email' => 'lawal@olayinka.test',
        'password' => 'Sup3r-Secret!',
        'password_confirmation' => 'Sup3r-Secret!',
    ])->assertCreated();

    $names = collect($response->headers->getCookies())->map->getName();

    expect($names)->toContain(config('session.cookie'));
});

test('registering from an unrecognised origin is reported rather than shrugged off', function (): void {
    Log::spy();

    // No session cookie can be issued here, so the caller is left with an
    // account it cannot use. Silence would make this undiagnosable.
    $response = $this->withHeader('Origin', 'https://not-in-stateful-domains.example.com')
        ->postJson('/api/v1/register', [
            'company_name' => 'Stranded Ltd',
            'name' => 'Lawal',
            'email' => 'lawal@stranded.test',
            'password' => 'Sup3r-Secret!',
            'password_confirmation' => 'Sup3r-Secret!',
        ])->assertCreated();

    expect(collect($response->headers->getCookies())->map->getName())
        ->not->toContain(config('session.cookie'));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'no session was established')
            && $context['flow'] === 'register'
            && $context['origin'] === 'https://not-in-stateful-domains.example.com')
        ->once();
});

test('logging in from an unrecognised origin is reported too', function (): void {
    Log::spy();

    $tenant = Tenant::factory()->create();
    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'someone@example.test',
        'password' => 'Sup3r-Secret!',
        'status' => User::STATUS_ACTIVE,
    ]);

    $this->withHeader('Origin', 'https://not-in-stateful-domains.example.com')
        ->postJson('/api/v1/login', [
            'email' => 'someone@example.test',
            'password' => 'Sup3r-Secret!',
        ])->assertOk();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $context['flow'] === 'login')
        ->once();
});

test('a correctly configured login says nothing', function (): void {
    Log::spy();

    $tenant = Tenant::factory()->create();
    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'fine@example.test',
        'password' => 'Sup3r-Secret!',
        'status' => User::STATUS_ACTIVE,
    ]);

    $this->withHeader('Origin', 'http://localhost:3000')
        ->postJson('/api/v1/login', [
            'email' => 'fine@example.test',
            'password' => 'Sup3r-Secret!',
        ])->assertOk();

    Log::shouldNotHaveReceived('warning');
});
