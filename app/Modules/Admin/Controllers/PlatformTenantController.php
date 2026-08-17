<?php

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Requests\ActivateRequest;
use App\Modules\Admin\Requests\ListTenantsRequest;
use App\Modules\Admin\Requests\ReasonRequest;
use App\Modules\Admin\Requests\UpdateTenantRequest;
use App\Modules\Admin\Resources\PlatformTenantResource;
use App\Modules\Admin\Services\PlatformActivityService;
use App\Modules\Admin\Services\PlatformCustomerSnapshot;
use App\Modules\Tenancy\Services\PlatformTenantService;
use App\Support\Authorization\PlatformAbilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function show(
        Request $request,
        int $tenant,
        PlatformTenantService $service,
        PlatformCustomerSnapshot $snapshot,
    ): JsonResponse {
        $company = $service->find($tenant);

        // Merged into `data` rather than sitting beside it, so the detail page
        // reads one object. Money inside the snapshot is gated on the revenue
        // ability — administering a company is not the same permission as
        // seeing what it pays us.
        return response()->json([
            'data' => (new PlatformTenantResource($company))->resolve($request)
                + $snapshot->for($company, $request->user()->hasPlatformAbility(PlatformAbilities::REVENUE)),
        ]);
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
