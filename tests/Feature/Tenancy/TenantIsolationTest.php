<?php

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\CurrentTenant;
use App\Support\Tenancy\MissingTenantContextException;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only tenant-owned model. Every real tenant-owned model uses the same
 * trait, so these tests guard the isolation mechanism itself.
 */
class TenantIsolationFixture extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_isolation_fixtures';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    Schema::create('tenant_isolation_fixtures', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id');
        $table->string('name');
    });
});

test('queries are automatically scoped to the current tenant', function () {
    [$tenantA, $tenantB] = Tenant::factory()->count(2)->create();

    app(CurrentTenant::class)->set($tenantA);
    TenantIsolationFixture::create(['name' => 'a-record']);

    app(CurrentTenant::class)->set($tenantB);
    TenantIsolationFixture::create(['name' => 'b-record']);

    expect(TenantIsolationFixture::all()->pluck('name')->all())->toBe(['b-record']);

    app(CurrentTenant::class)->set($tenantA);
    expect(TenantIsolationFixture::all()->pluck('name')->all())->toBe(['a-record']);
});

test('tenant_id is filled automatically on create', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $record = TenantIsolationFixture::create(['name' => 'auto']);

    expect($record->tenant_id)->toBe($tenant->id);
});

test('creating without tenant context throws instead of writing a global row', function () {
    app(CurrentTenant::class)->forget();

    TenantIsolationFixture::create(['name' => 'orphan']);
})->throws(MissingTenantContextException::class);

test('a record cannot be found by id across tenants', function () {
    [$tenantA, $tenantB] = Tenant::factory()->count(2)->create();

    app(CurrentTenant::class)->set($tenantA);
    $record = TenantIsolationFixture::create(['name' => 'secret']);

    app(CurrentTenant::class)->set($tenantB);
    expect(TenantIsolationFixture::find($record->id))->toBeNull();
});
