<?php

namespace App\Modules\Company\Models;

use Database\Factories\HolidayCalendarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['year', 'name', 'created_by'])]
class HolidayCalendar extends CompanyModel
{
    protected static string $factory = HolidayCalendarFactory::class;

    protected function casts(): array
    {
        return ['year' => 'integer'];
    }

    public function dates(): HasMany
    {
        return $this->hasMany(HolidayDate::class);
    }
}
