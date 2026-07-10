<?php

namespace App\Modules\Company\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmploymentTypeRequest extends FormRequest
{
    use CompanyRequestHelpers;

    public function rules(): array
    {
        $employmentType = $this->route('employmentType');

        return [
            'name' => ['required', 'string', 'max:255', $this->tenantUnique('employment_types', 'name', $employmentType?->id)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
