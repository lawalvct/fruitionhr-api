<?php

namespace App\Modules\Payroll\Requests;

use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Services\AdvancedSalaryFeature;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SalaryComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $canManageSalary = $this->user()?->can(Permissions::EMPLOYEES_MANAGE_SALARY) ?? false;
        $touchesFormula = $this->input('calc_type') === SalaryComponent::CALC_FORMULA
            || $this->route('salaryComponent')?->calc_type === SalaryComponent::CALC_FORMULA;

        return $canManageSalary && (! $touchesFormula
            || ($this->user()?->can(Permissions::PAYROLL_FORMULAS_MANAGE) ?? false));
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
            'calc_type' => ['required', Rule::in(SalaryComponent::CALC_TYPES)],
            'percent' => [
                'nullable', 'integer', 'min:0', 'max:100',
                Rule::requiredIf(fn (): bool => in_array(
                    $this->input('calc_type'),
                    SalaryComponent::PERCENT_CALC_TYPES,
                    true,
                )),
            ],
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
            if ($this->input('calc_type') === SalaryComponent::CALC_FORMULA) {
                if (! app(AdvancedSalaryFeature::class)->enabled()) {
                    $validator->errors()->add('calc_type', 'Enable advanced salary formulas in Payroll settings first.');
                }

                if ($this->filled('percent')) {
                    $validator->errors()->add('percent', 'Formula components do not use a component percentage.');
                }
            }

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
