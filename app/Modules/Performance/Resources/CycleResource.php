<?php

namespace App\Modules\Performance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CycleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'starts_at' => $this->starts_at->toDateString(), 'ends_at' => $this->ends_at->toDateString(),
            'review_starts_at' => $this->review_starts_at?->toDateString(), 'review_ends_at' => $this->review_ends_at?->toDateString(),
            'status' => $this->status, 'assignments_count' => $this->whenCounted('assignments'),
        ];
    }
}
