<?php

namespace App\Modules\Admin\Resources;

use App\Modules\Recruitment\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Application */
class PlatformApplicationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stage' => $this->stage,
            'source' => $this->source,
            'applied_at' => $this->applied_at?->toIso8601String(),
            'hired_at' => $this->hired_at?->toIso8601String(),
            'applicant' => $this->whenLoaded('applicant', fn () => $this->applicant === null ? null : [
                'id' => $this->applicant->id,
                'name' => $this->applicant->full_name,
                'email' => $this->applicant->email,
                'phone' => $this->applicant->phone,
            ]),
            'vacancy' => $this->whenLoaded('vacancy', fn () => $this->vacancy === null ? null : [
                'id' => $this->vacancy->id,
                'title' => $this->vacancy->title,
            ]),
            'company' => $this->whenLoaded('tenant', fn () => [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'slug' => $this->tenant->slug,
            ]),
        ];
    }
}
