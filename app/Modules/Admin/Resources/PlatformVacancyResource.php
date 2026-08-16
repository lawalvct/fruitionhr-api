<?php

namespace App\Modules\Admin\Resources;

use App\Modules\Recruitment\Models\Vacancy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Vacancy */
class PlatformVacancyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'visibility' => $this->visibility,
            'location' => $this->location,
            'positions_available' => $this->positions_available,
            'public_slug' => $this->public_slug,
            'opens_at' => $this->opens_at?->toDateString(),
            'closes_at' => $this->closes_at?->toDateString(),
            'applications_count' => (int) ($this->applications_count ?? 0),
            'employment_type' => $this->whenLoaded('employmentType', fn () => $this->employmentType?->name),
            'company' => $this->whenLoaded('tenant', fn () => [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'slug' => $this->tenant->slug,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
