<?php

namespace App\Modules\Recruitment\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicVacancyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->public_slug,
            'title' => $this->title,
            'description' => $this->description,
            'requirements' => $this->requirements,
            'location' => $this->location,
            'positions_available' => $this->positions_available,
            'opens_at' => $this->opens_at?->toDateString(),
            'closes_at' => $this->closes_at?->toDateString(),
            'published_at' => $this->created_at?->toISOString(),
            'company' => $this->whenLoaded('tenant', fn () => [
                'name' => $this->tenant->name,
                'slug' => $this->tenant->slug,
                'has_logo' => $this->tenant->logo_path !== null,
            ]),
            'employment_type' => $this->whenLoaded('employmentType', fn () => $this->employmentType
                ? ['name' => $this->employmentType->name]
                : null),
            'position' => $this->whenLoaded('requisition', fn () => $this->requisition->position
                ? ['title' => $this->requisition->position->title]
                : null),
            'department' => $this->whenLoaded('requisition', fn () => $this->requisition->department
                ? ['name' => $this->requisition->department->name]
                : null),
        ];
    }
}
