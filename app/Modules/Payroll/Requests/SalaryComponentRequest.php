<?php

namespace App\Modules\Payroll\Requests;

use App\Modules\Payroll\Models\SalaryComponent;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'type' => ['required', Rule::in([
                SalaryComponent::TYPE_EARNING,
                SalaryComponent::TYPE_DEDUCTION,
                SalaryComponent::TYPE_EMPLOYER_CONTRIBUTOR,
                SalaryComponent::TYPE_FRINGE_BENEFIT,
            ])],
            'calc_type' => ['required', Rule::in([SalaryComponent::CALC_FIXED, SalaryComponent::CALC_PERCENT])],
            'percent' => ['nullable', 'integer', 'min:0', 'max:100', 'required_if:calc_type,'.SalaryComponent::CALC_PERCENT],
            'is_taxable' => ['sometimes', 'boolean'],
            'is_pensionable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('type') === SalaryComponent::TYPE_EMPLOYER_CONTRIBUTOR) {
            $this->merge([
                'is_taxable' => false,
                'is_pensionable' => false,
            ]);
        }

        if ($this->input('type') === SalaryComponent::TYPE_FRINGE_BENEFIT) {
            $this->merge([
                'is_taxable' => true,
                'is_pensionable' => false,
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! SalaryComponent::isReservedBasicSalary($this->input('name'), $this->input('code'))) {
                return;
            }

            $existing = $this->route('salaryComponent');
            if ($existing?->isReservedBasicSalaryComponent()) {
                return; // Allow legacy records to be deactivated or renamed.
            }

            $validator->errors()->add(
                'name',
                'Basic Salary is entered per employee in Compensation and cannot be created as a salary component.',
            );
        });
    }
}
