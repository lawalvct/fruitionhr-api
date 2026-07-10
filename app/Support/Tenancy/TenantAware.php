<?php

namespace App\Support\Tenancy;

use App\Modules\Tenancy\Models\Tenant;

/**
 * Use on every queued job that touches tenant data.
 *
 * Auth-based tenant resolution does not exist inside queue workers, so jobs
 * must carry tenant_id explicitly and re-establish context before running:
 *
 *     public function __construct(public PayrollRun $run)
 *     {
 *         $this->captureTenantContext();
 *     }
 *
 *     public function handle(): void
 *     {
 *         $this->restoreTenantContext();
 *         // ... tenant-scoped work
 *     }
 */
trait TenantAware
{
    public ?int $tenantContextId = null;

    public function captureTenantContext(): void
    {
        $this->tenantContextId = app(CurrentTenant::class)->id();

        if ($this->tenantContextId === null) {
            throw new MissingTenantContextException(
                sprintf('Job [%s] dispatched without tenant context.', static::class)
            );
        }
    }

    public function restoreTenantContext(): void
    {
        if ($this->tenantContextId === null) {
            throw new MissingTenantContextException(
                sprintf('Job [%s] has no captured tenant context to restore.', static::class)
            );
        }

        $tenant = Tenant::query()->findOrFail($this->tenantContextId);

        app(CurrentTenant::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
