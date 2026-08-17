<?php

namespace App\Modules\Admin\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class PlatformAdministratorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'is_super_admin' => $this->isSuperAdmin(),
            'is_email_verified' => $this->hasVerifiedEmail(),
            'platform_role' => $this->whenLoaded('platformRole', fn (): ?array => $this->platformRole === null ? null : [
                'id' => $this->platformRole->id,
                'name' => $this->platformRole->name,
                'is_owner' => $this->platformRole->isOwner(),
            ]),
            'platform_abilities' => $this->platformAbilities(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
