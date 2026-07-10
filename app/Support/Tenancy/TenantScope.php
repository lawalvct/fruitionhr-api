<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(CurrentTenant::class)->id();

        if ($tenantId !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenantId);

            return;
        }

        // SECURITY — fail closed: querying a tenant-owned model without a
        // tenant context returns nothing rather than everything. Seeders,
        // jobs and console code must establish context (CurrentTenant::set /
        // TenantAware::restoreTenantContext) before querying.
        $builder->whereRaw('1 = 0');
    }
}
