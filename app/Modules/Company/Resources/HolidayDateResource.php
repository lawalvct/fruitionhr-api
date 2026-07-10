<?php

namespace App\Modules\Company\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HolidayDateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'holiday_calendar_id' => $this->holiday_calendar_id,
            'date' => $this->date?->format('Y-m-d'),
            'name' => $this->name,
            'is_recurring' => $this->is_recurring,
        ];
    }
}
