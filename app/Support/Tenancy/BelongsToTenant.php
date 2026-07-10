<?php

namespace App\Support\Tenancy;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to every tenant-owned model. Adds the tenant global scope and
 * auto-fills tenant_id on create from the current tenant context.
 *
 * Creating a tenant-owned record without tenant context is a bug — it throws
 * rather than silently writing a NULL/global row.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $tenantId = app(CurrentTenant::class)->id();

            if ($tenantId === null) {
                throw new MissingTenantContextException(
                    sprintf('Cannot create [%s] without a current tenant.', static::class)
                );
            }

            $model->setAttribute('tenant_id', $tenantId);
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
