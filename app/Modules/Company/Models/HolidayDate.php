<?php

namespace App\Modules\Company\Models;

use Database\Factories\HolidayDateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['holiday_calendar_id', 'date', 'name', 'is_recurring', 'created_by'])]
class HolidayDate extends CompanyModel
{
    protected static string $factory = HolidayDateFactory::class;

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'is_recurring' => 'boolean',
        ];
    }

    public function holidayCalendar(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendar::class);
    }
}
