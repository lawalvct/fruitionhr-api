<?php

namespace App\Modules\Performance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RatingScaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'description' => $this->description, 'is_active' => $this->is_active,
            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn ($option) => [
                'id' => $option->id, 'label' => $option->label,
                'min_score_basis_points' => $option->min_score_basis_points, 'max_score_basis_points' => $option->max_score_basis_points,
            ])),
        ];
    }
}
