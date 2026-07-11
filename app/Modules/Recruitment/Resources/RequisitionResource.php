<?php

namespace App\Modules\Recruitment\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequisitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'headcount' => $this->headcount,
            'reason' => $this->reason,
            'target_start_date' => $this->target_start_date?->toDateString(),
            'status' => $this->status,
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            'position' => $this->whenLoaded('position', fn () => $this->position ? ['id' => $this->position->id, 'title' => $this->position->title] : null),
            'employment_type' => $this->whenLoaded('employmentType', fn () => $this->employmentType ? ['id' => $this->employmentType->id, 'name' => $this->employmentType->name] : null),
            'requester' => $this->whenLoaded('requester', fn () => ['id' => $this->requester->id, 'name' => $this->requester->name]),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
