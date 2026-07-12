<?php

namespace App\Modules\Attendance\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::ATTENDANCE_MANAGE) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'employee_id' => [
                'required', 'integer',
                Rule::exists('employees', 'id')->where('tenant_id', $tenantId),
            ],
            'date' => ['required', 'date_format:Y-m-d'],
            'clock_in' => ['nullable', 'date_format:H:i'],
            'clock_out' => [
                'nullable',
                'date_format:H:i',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && $value === $this->input('clock_in')) {
                        $fail('The clock out time must be different from the clock in time.');
                    }
                },
            ],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
