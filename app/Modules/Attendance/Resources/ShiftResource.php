<?php

namespace App\Modules\Attendance\Resources;

use App\Modules\Attendance\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Shift
 */
class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_time' => substr((string) $this->start_time, 0, 5),
            'end_time' => substr((string) $this->end_time, 0, 5),
            'grace_minutes' => $this->grace_minutes,
            'working_days' => $this->working_days,
            'is_active' => $this->is_active,
        ];
    }
}
