<?php

namespace App\Modules\Attendance\Requests;

use App\Support\Authorization\Permissions;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::ATTENDANCE_MANAGE) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => [
                'required',
                'date_format:H:i',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === $this->input('start_time')) {
                        $fail('The end time must be different from the start time.');
                    }
                },
            ],
            'grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['integer', Rule::in([1, 2, 3, 4, 5, 6, 7])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
