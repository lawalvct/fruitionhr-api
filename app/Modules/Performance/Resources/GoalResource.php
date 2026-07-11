<?php

namespace App\Modules\Performance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'level' => $this->level, 'title' => $this->title, 'description' => $this->description,
            'weight' => $this->weight, 'target_value' => $this->target_value, 'current_value' => $this->current_value,
            'measurement_unit' => $this->measurement_unit, 'progress' => $this->progress, 'status' => $this->status,
            'starts_at' => $this->starts_at?->toDateString(), 'due_at' => $this->due_at?->toDateString(),
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? ['id' => $this->employee->id, 'name' => $this->employee->full_name] : null),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->name] : null),
            'checkins' => $this->whenLoaded('checkins', fn () => $this->checkins->map(fn ($checkin) => [
                'id' => $checkin->id, 'progress' => $checkin->progress, 'current_value' => $checkin->current_value,
                'comment' => $checkin->comment, 'created_at' => $checkin->created_at?->toISOString(),
            ])),
        ];
    }
}
