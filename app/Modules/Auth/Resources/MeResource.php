<?php

namespace App\Modules\Auth\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isTenantUser = $this->tenant_id !== null;

        if ($isTenantUser) {
            setPermissionsTeamId($this->tenant_id);
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_super_admin' => $this->isSuperAdmin(),
            'status' => $this->status,
            'tenant' => $this->when($isTenantUser, fn () => [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'slug' => $this->tenant->slug,
                'status' => $this->tenant->status,
            ]),
            'roles' => $this->when($isTenantUser, fn () => $this->getRoleNames()),
            'permissions' => $this->when($isTenantUser, fn () => $this->getAllPermissions()->pluck('name')),
        ];
    }
}
