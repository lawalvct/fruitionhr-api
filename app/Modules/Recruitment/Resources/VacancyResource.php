<?php

namespace App\Modules\Recruitment\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VacancyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'code' => $this->code,
            'public_slug' => $this->public_slug,
            'public_path' => $this->public_slug ? '/careers/'.$this->public_slug : null,
            'description' => $this->description,
            'requirements' => $this->requirements,
            'location' => $this->location,
            'positions_available' => $this->positions_available,
            'opens_at' => $this->opens_at?->toDateString(),
            'closes_at' => $this->closes_at?->toDateString(),
            'status' => $this->status,
            'visibility' => $this->visibility,
            'applications_count' => $this->whenCounted('applications'),
            'requisition' => $this->whenLoaded('requisition', fn () => [
                'id' => $this->requisition->id,
                'title' => $this->requisition->title,
                'position' => $this->requisition->relationLoaded('position') && $this->requisition->position
                    ? ['id' => $this->requisition->position->id, 'title' => $this->requisition->position->title]
                    : null,
            ]),
            'employment_type' => $this->whenLoaded('employmentType', fn () => $this->employmentType ? ['id' => $this->employmentType->id, 'name' => $this->employmentType->name] : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
