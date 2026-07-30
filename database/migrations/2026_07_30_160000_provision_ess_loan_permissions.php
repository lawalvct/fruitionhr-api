<?php

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Tenant::query()->each(fn (Tenant $tenant) => app(TenantRoleProvisioner::class)->provision($tenant));
    }

    public function down(): void
    {
        // Role permission provisioning is intentionally not reversed.
    }
};
