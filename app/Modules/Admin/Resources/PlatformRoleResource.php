<?php

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Models\PlatformRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlatformRole */
class PlatformRoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            // grantedAbilities() rather than the stored column: Owner tracks
            // the catalogue, and retired abilities are filtered out.
            'abilities' => $this->grantedAbilities(),
            'is_system' => $this->is_system,
            'is_owner' => $this->isOwner(),
            'administrators_count' => $this->whenCounted('administrators'),
        ];
    }
}
