<?php

namespace App\Modules\Payroll\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalaryStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::EMPLOYEES_MANAGE_SALARY) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'components' => ['sometimes', 'array'],
            'components.*.salary_component_id' => [
                'required', 'integer',
                Rule::exists('salary_components', 'id')->where('tenant_id', $tenantId),
            ],
            'components.*.amount' => ['nullable', 'integer', 'min:0'], // kobo
            'components.*.percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }
}
