<?php

namespace App\Support\Tenancy;

use App\Modules\Tenancy\Models\Tenant;

/**
 * Container-bound singleton holding the tenant for the current request/job.
 *
 * Always resolve via the container (`app(CurrentTenant::class)` or the
 * `current_tenant()` helper) — never store tenant state statically, so queue
 * workers processing jobs for different tenants can never leak context.
 */
class CurrentTenant
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }
}
