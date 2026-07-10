<?php

namespace App\Modules\Employee\Requests;

use App\Modules\Employee\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    use EmployeeRequestHelpers;

    public function authorize(): bool
    {
        return $this->user()?->can('employees.update') ?? false;
    }

    public function rules(): array
    {
        /** @var Employee|null $employee */
        $employee = $this->route('employee');

        return [
            'employee_number' => ['sometimes', 'string', 'max:50', $this->tenantUnique('employees', 'employee_number', $employee?->id)],
            'user_id' => ['nullable', 'integer', $this->tenantExists('users')],
            'first_name' => ['required', 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'official_email' => ['nullable', 'email', 'max:255'],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:80'],
            'gender' => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date'],
            'marital_status' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'photo_path' => ['nullable', 'string', 'max:500'],
            'employment_status' => ['required', Rule::in([
                Employee::STATUS_ACTIVE,
                Employee::STATUS_ON_LEAVE,
                Employee::STATUS_SUSPENDED,
                Employee::STATUS_EXITED,
            ])],
            'hired_at' => ['required', 'date'],
            'exited_at' => ['nullable', 'date', 'after_or_equal:hired_at'],
        ];
    }
}
