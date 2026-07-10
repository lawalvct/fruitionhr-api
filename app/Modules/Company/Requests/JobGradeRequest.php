<?php

namespace App\Modules\Company\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JobGradeRequest extends FormRequest
{
    use CompanyRequestHelpers;

    public function rules(): array
    {
        $jobGrade = $this->route('jobGrade');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', $this->tenantUnique('job_grades', 'code', $jobGrade?->id)],
            'level' => ['required', 'integer', 'min:1', 'max:999'],
            'min_salary' => ['nullable', 'integer', 'min:0'],
            'max_salary' => ['nullable', 'integer', 'min:0', 'gte:min_salary'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
