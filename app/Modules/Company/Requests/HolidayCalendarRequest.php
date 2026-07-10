<?php

namespace App\Modules\Company\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HolidayCalendarRequest extends FormRequest
{
    use CompanyRequestHelpers;

    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'name' => ['required', 'string', 'max:255'],
            'dates' => ['sometimes', 'array'],
            'dates.*.id' => ['sometimes', 'integer', $this->tenantExists('holiday_dates')],
            'dates.*.date' => ['required_with:dates', 'date'],
            'dates.*.name' => ['required_with:dates', 'string', 'max:255'],
            'dates.*.is_recurring' => ['sometimes', 'boolean'],
        ];
    }
}
