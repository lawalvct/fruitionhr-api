<?php

namespace App\Modules\Payroll\Requests;

use App\Modules\Payroll\Models\SalaryComponent;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $componentIds = collect($this->input('components', []))
                ->pluck('salary_component_id')
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique();

            if ($componentIds->isEmpty()) {
                return;
            }

            $hasBasicSalary = SalaryComponent::query()
                ->whereIn('id', $componentIds)
                ->get(['name', 'code'])
                ->contains(fn (SalaryComponent $component) => $component->isReservedBasicSalaryComponent());

            if ($hasBasicSalary) {
                $validator->errors()->add(
                    'components',
                    'Basic Salary cannot be included in a salary structure; enter it per employee in Compensation.',
                );
            }
        });
    }
}
