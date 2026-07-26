<?php

namespace App\Modules\Attendance\Resources;

use App\Modules\Attendance\Models\AttendanceKiosk;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendanceKiosk
 */
class AttendanceKioskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'location' => $this->location,
            'is_active' => $this->is_active,
        ];
    }
}
