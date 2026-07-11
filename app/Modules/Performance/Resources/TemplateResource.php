<?php

namespace App\Modules\Performance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'description' => $this->description, 'is_active' => $this->is_active,
            'rating_scale' => $this->whenLoaded('ratingScale', fn () => ['id' => $this->ratingScale->id, 'name' => $this->ratingScale->name]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id, 'weight' => $item->weight,
                'kpi' => ['id' => $item->kpi->id, 'name' => $item->kpi->name, 'category' => $item->kpi->category?->name],
            ])),
        ];
    }
}
