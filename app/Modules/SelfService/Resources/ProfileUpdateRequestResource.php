<?php

namespace App\Modules\SelfService\Resources;

use App\Modules\SelfService\Models\ProfileUpdateRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProfileUpdateRequest
 */
class ProfileUpdateRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'status' => $this->status,
            'current_values' => $this->current_values,
            'requested_values' => $this->requested_values,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
