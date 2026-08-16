<?php

namespace App\Modules\Access\Controllers;

use App\Modules\Access\Requests\StoreRoleRequest;
use App\Modules\Access\Requests\SyncUserRolesRequest;
use App\Modules\Access\Requests\UpdateRoleRequest;
use App\Modules\Access\Resources\AccessUserResource;
use App\Modules\Access\Resources\RoleResource;
use App\Modules\Access\Services\AccessControlService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class AccessControlController extends Controller
{
    public function permissions(Request $request, AccessControlService $service): JsonResponse
    {
        $this->authorizeAccess($request);

        return response()->json(['data' => $service->permissionGroups()]);
    }

    public function roles(Request $request, AccessControlService $service): AnonymousResourceCollection
    {
        $this->authorizeAccess($request);

        return RoleResource::collection($service->roles());
    }

    public function storeRole(StoreRoleRequest $request, AccessControlService $service): JsonResponse
    {
        return (new RoleResource($service->createRole($request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function updateRole(UpdateRoleRequest $request, int $roleId, AccessControlService $service): RoleResource
    {
        return new RoleResource($service->updateRole($roleId, $request->validated()));
    }

    public function destroyRole(Request $request, int $roleId, AccessControlService $service): JsonResponse
    {
        $this->authorizeAccess($request);
        $service->deleteRole($roleId);

        return response()->json(null, 204);
    }

    public function users(Request $request, AccessControlService $service): AnonymousResourceCollection
    {
        $this->authorizeAccess($request);

        return AccessUserResource::collection($service->users());
    }

    public function syncUserRoles(
        SyncUserRolesRequest $request,
        int $userId,
        AccessControlService $service,
    ): AccessUserResource {
        return new AccessUserResource(
            $service->syncUserRoles($userId, $request->validated('role_ids'), $request->user()),
        );
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::ROLES_MANAGE), 403);
    }
}
