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
            'phone' => $this->phone,
            'timezone' => $this->timezone,
            'bio' => $this->bio,
            'avatar_url' => $this->avatar_path ? '/api/v1/profile/avatar' : null,
            'is_email_verified' => $this->hasVerifiedEmail(),
            'is_super_admin' => $this->isSuperAdmin(),
            'status' => $this->status,
            'tenant' => $this->when($isTenantUser, fn () => [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'slug' => $this->tenant->slug,
                'status' => $this->tenant->status,
                'logo_url' => $this->tenant->logo_path ? '/api/v1/company/logo' : null,
                'onboarding_status' => $this->tenant->onboarding_status,
                'onboarding_step' => $this->tenant->onboarding_step,
            ]),
            'employee' => $this->when($isTenantUser && $this->relationLoaded('employee') && $this->employee !== null, fn () => [
                'id' => $this->employee->id,
                'employee_number' => $this->employee->employee_number,
                'name' => $this->employee->full_name,
            ]),
            'roles' => $this->when($isTenantUser, fn () => $this->getRoleNames()),
            'permissions' => $this->when($isTenantUser, fn () => $this->getAllPermissions()->pluck('name')),
            // Platform staff only. The admin sidebar draws itself from this,
            // and the API enforces the same list on every admin route.
            'platform_role' => $this->when(! $isTenantUser, fn (): ?string => $this->loadMissing('platformRole')
                ->getRelation('platformRole')?->name),
            'platform_abilities' => $this->when(! $isTenantUser, fn (): array => $this->platformAbilities()),
        ];
    }
}
