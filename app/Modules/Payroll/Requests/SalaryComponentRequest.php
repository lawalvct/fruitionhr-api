<?php

namespace App\Modules\Payroll\Requests;

use App\Modules\Payroll\Models\SalaryComponent;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalaryComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::EMPLOYEES_MANAGE_SALARY) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();
        $componentId = $this->route('salaryComponent')?->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('salary_components', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($componentId),
            ],
            'type' => ['required', Rule::in([SalaryComponent::TYPE_EARNING, SalaryComponent::TYPE_DEDUCTION])],
            'calc_type' => ['required', Rule::in([SalaryComponent::CALC_FIXED, SalaryComponent::CALC_PERCENT])],
            'percent' => ['nullable', 'integer', 'min:0', 'max:100', 'required_if:calc_type,'.SalaryComponent::CALC_PERCENT],
            'is_taxable' => ['sometimes', 'boolean'],
            'is_pensionable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
