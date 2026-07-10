<?php

namespace App\Modules\Employee\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssignmentRequest extends FormRequest
{
    use EmployeeRequestHelpers;

    public function authorize(): bool
    {
        return $this->user()?->can('employees.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', $this->tenantExists('branches')],
            'department_id' => ['nullable', 'integer', $this->tenantExists('departments')],
            'position_id' => ['nullable', 'integer', $this->tenantExists('positions')],
            'job_grade_id' => ['nullable', 'integer', $this->tenantExists('job_grades')],
            'employment_type_id' => ['nullable', 'integer', $this->tenantExists('employment_types')],
            'supervisor_id' => ['nullable', 'integer', $this->tenantExists('employees')],
            'effective_from' => ['required', 'date'],
        ];
    }
}
