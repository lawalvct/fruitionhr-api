<?php

namespace App\Modules\Company\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PositionRequest extends FormRequest
{
    use CompanyRequestHelpers;

    public function rules(): array
    {
        $position = $this->route('position');

        return [
            'title' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', $this->tenantUnique('positions', 'code', $position?->id)],
            'department_id' => ['nullable', 'integer', $this->tenantExists('departments')],
            'job_grade_id' => ['nullable', 'integer', $this->tenantExists('job_grades')],
            'description' => ['nullable', 'string', 'max:4000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
