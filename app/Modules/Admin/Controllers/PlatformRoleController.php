<?php

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Requests\StorePlatformRoleRequest;
use App\Modules\Admin\Requests\UpdatePlatformRoleRequest;
use App\Modules\Admin\Resources\PlatformRoleResource;
use App\Modules\Admin\Services\PlatformActivityService;
use App\Modules\Admin\Services\PlatformRoleService;
use App\Support\Authorization\PlatformAbilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Managing the named jobs platform staff can hold.
 *
 * Owners only — the route group enforces it. See routes/modules/admin.php.
 */
class PlatformRoleController extends Controller
{
    /**
     * Roles, plus the catalogue of sections a role can be given — the admin UI
     * needs both to draw the ability picker, and they are always read together.
     */
    public function index(PlatformRoleService $service): JsonResponse
    {
        return response()->json([
            'data' => PlatformRoleResource::collection($service->all())->resolve(),
            'meta' => [
                'abilities' => array_values(array_filter(
                    PlatformAbilities::catalogue(),
                    static fn (array $ability): bool => $ability['assignable'],
                )),
            ],
        ]);
    }

    public function store(
        StorePlatformRoleRequest $request,
        PlatformRoleService $service,
        PlatformActivityService $activity,
    ): JsonResponse {
        $role = $service->create($request->validated());

        $activity->record(
            request: $request,
            action: 'platform_role.created',
            subjectType: 'platform_role',
            subjectId: $role->id,
            subjectLabel: $role->name,
            before: [],
            after: ['name' => $role->name, 'abilities' => $role->grantedAbilities()],
        );

        return (new PlatformRoleResource($role))
            ->additional(['message' => "{$role->name} created."])
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdatePlatformRoleRequest $request,
        int $role,
        PlatformRoleService $service,
        PlatformActivityService $activity,
    ): PlatformRoleResource {
        $before = $service->all()->firstWhere('id', $role);
        $updated = $service->update($role, $request->validated());

        $activity->record(
            request: $request,
            action: 'platform_role.updated',
            subjectType: 'platform_role',
            subjectId: $updated->id,
            subjectLabel: $updated->name,
            before: ['name' => $before?->name, 'abilities' => $before?->grantedAbilities() ?? []],
            after: ['name' => $updated->name, 'abilities' => $updated->grantedAbilities()],
        );

        return (new PlatformRoleResource($updated))
            ->additional(['message' => "{$updated->name} updated. Anyone holding it sees the change on their next request."]);
    }

    public function destroy(
        Request $request,
        int $role,
        PlatformRoleService $service,
        PlatformActivityService $activity,
    ): JsonResponse {
        $deleted = $service->delete($role);

        $activity->record(
            request: $request,
            action: 'platform_role.deleted',
            subjectType: 'platform_role',
            subjectId: $deleted->id,
            subjectLabel: $deleted->name,
            before: ['name' => $deleted->name, 'abilities' => $deleted->grantedAbilities()],
            after: [],
        );

        return response()->json(['message' => "{$deleted->name} deleted."]);
    }
}
