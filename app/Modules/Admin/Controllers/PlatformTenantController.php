<?php

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Requests\ActivateRequest;
use App\Modules\Admin\Requests\ListTenantsRequest;
use App\Modules\Admin\Requests\ReasonRequest;
use App\Modules\Admin\Requests\UpdateTenantRequest;
use App\Modules\Admin\Resources\PlatformTenantResource;
use App\Modules\Admin\Services\PlatformActivityService;
use App\Modules\Tenancy\Services\PlatformTenantService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class PlatformTenantController extends Controller
{
    public function index(
        ListTenantsRequest $request,
        PlatformTenantService $service,
    ): AnonymousResourceCollection {
        return PlatformTenantResource::collection(
            $service->paginate($request->validated())
        );
    }

    public function show(int $tenant, PlatformTenantService $service): PlatformTenantResource
    {
        return new PlatformTenantResource($service->find($tenant));
    }

    public function update(
        UpdateTenantRequest $request,
        int $tenant,
        PlatformTenantService $service,
        PlatformActivityService $activity,
    ): PlatformTenantResource {
        $result = DB::transaction(function () use ($request, $tenant, $service, $activity): array {
            $result = $service->update($tenant, $request->validated());
            $activity->record(
                request: $request,
                action: 'tenant.updated',
                subjectType: 'tenant',
                subjectId: $result['tenant']->id,
                subjectLabel: $result['tenant']->name,
                before: $result['before'],
                after: $result['after'],
            );

            return $result;
        });

        return (new PlatformTenantResource($result['tenant']))
            ->additional(['message' => 'Company details updated.']);
    }

    public function suspend(
        ReasonRequest $request,
        int $tenant,
        PlatformTenantService $service,
        PlatformActivityService $activity,
    ): PlatformTenantResource {
        $result = DB::transaction(function () use ($request, $tenant, $service, $activity): array {
            $result = $service->suspend($tenant);
            $activity->record(
                request: $request,
                action: 'tenant.suspended',
                subjectType: 'tenant',
                subjectId: $result['tenant']->id,
                subjectLabel: $result['tenant']->name,
                before: $result['before'],
                after: $result['after'],
                reason: $request->validated('reason'),
            );

            return $result;
        });

        return (new PlatformTenantResource($result['tenant']))
            ->additional(['message' => 'Company suspended.']);
    }

    public function activate(
        ActivateRequest $request,
        int $tenant,
        PlatformTenantService $service,
        PlatformActivityService $activity,
    ): PlatformTenantResource {
        $result = DB::transaction(function () use ($request, $tenant, $service, $activity): array {
            $result = $service->activate($tenant);
            $activity->record(
                request: $request,
                action: 'tenant.activated',
                subjectType: 'tenant',
                subjectId: $result['tenant']->id,
                subjectLabel: $result['tenant']->name,
                before: $result['before'],
                after: $result['after'],
                reason: $request->validated('reason'),
            );

            return $result;
        });

        return (new PlatformTenantResource($result['tenant']))
            ->additional(['message' => 'Company activated.']);
    }
}
