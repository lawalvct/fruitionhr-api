<?php

namespace App\Modules\Company\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DepartmentRequest extends FormRequest
{
    use CompanyRequestHelpers;

    public function rules(): array
    {
        $department = $this->route('department');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', $this->tenantUnique('departments', 'code', $department?->id)],
            'branch_id' => ['nullable', 'integer', $this->tenantExists('branches')],
            'parent_id' => ['nullable', 'integer', $this->tenantExists('departments')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $department = $this->route('department');

            if ($department && (int) $this->input('parent_id') === (int) $department->id) {
                $validator->errors()->add('parent_id', 'A department cannot be its own parent.');
            }
        });
    }
}
