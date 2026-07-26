<?php

namespace App\Modules\Attendance\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class AttendanceKioskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::ATTENDANCE_MANAGE) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
