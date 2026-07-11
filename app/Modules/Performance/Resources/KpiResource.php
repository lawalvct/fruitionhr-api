<?php

namespace App\Modules\Performance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KpiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'description' => $this->description,
            'measurement_unit' => $this->measurement_unit, 'target_description' => $this->target_description, 'is_active' => $this->is_active,
            'category' => $this->whenLoaded('category', fn () => ['id' => $this->category->id, 'name' => $this->category->name]),
        ];
    }
}
