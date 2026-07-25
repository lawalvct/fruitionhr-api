<?php

namespace App\Modules\Performance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'department' => $this->department, 'target_role' => $this->target_role,
            'min_passing_basis_points' => $this->min_passing_basis_points,
            'description' => $this->description, 'is_active' => $this->is_active,
            'rating_scale' => $this->whenLoaded('ratingScale', fn () => ['id' => $this->ratingScale->id, 'name' => $this->ratingScale->name]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id, 'weight' => $item->weight, 'is_mandatory' => $item->is_mandatory,
                'kpi' => ['id' => $item->kpi->id, 'name' => $item->kpi->name, 'category' => $item->kpi->category?->name],
            ])),
        ];
    }
}
