<?php

namespace App\Modules\Access\Resources;

use App\Support\Authorization\Permissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $systemRoles = array_keys(Permissions::defaultRoles());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => (string) Str::of($this->name)->replace('_', ' ')->headline(),
            'is_system' => in_array($this->name, $systemRoles, true),
            'is_owner' => $this->name === 'owner',
            'user_count' => (int) ($this->users_count ?? 0),
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions->pluck('name')->sort()->values(),
            ),
        ];
    }
}
