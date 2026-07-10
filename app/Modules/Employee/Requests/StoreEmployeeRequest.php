<?php

namespace App\Modules\Employee\Requests;

use App\Modules\Employee\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    use EmployeeRequestHelpers;

    public function authorize(): bool
    {
        return $this->user()?->can('employees.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_number' => ['sometimes', 'string', 'max:50', $this->tenantUnique('employees', 'employee_number')],
            'user_id' => ['nullable', 'integer', $this->tenantExists('users')],
            'first_name' => ['required', 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'official_email' => ['nullable', 'email', 'max:255'],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:80'],
            'gender' => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date'],
            'marital_status' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'photo_path' => ['nullable', 'string', 'max:500'],
            'employment_status' => ['sometimes', Rule::in([
                Employee::STATUS_ACTIVE,
                Employee::STATUS_ON_LEAVE,
                Employee::STATUS_SUSPENDED,
                Employee::STATUS_EXITED,
            ])],
            'hired_at' => ['required', 'date'],
            'exited_at' => ['nullable', 'date', 'after_or_equal:hired_at'],
            ...$this->assignmentRules('assignment.'),
            ...$this->contactRules(),
            ...$this->bankRules(),
            ...$this->statutoryRules(),
        ];
    }

    protected function assignmentRules(string $prefix = ''): array
    {
        return [
            $prefix.'branch_id' => ['nullable', 'integer', $this->tenantExists('branches')],
            $prefix.'department_id' => ['nullable', 'integer', $this->tenantExists('departments')],
            $prefix.'position_id' => ['nullable', 'integer', $this->tenantExists('positions')],
            $prefix.'job_grade_id' => ['nullable', 'integer', $this->tenantExists('job_grades')],
            $prefix.'employment_type_id' => ['nullable', 'integer', $this->tenantExists('employment_types')],
            $prefix.'supervisor_id' => ['nullable', 'integer', $this->tenantExists('employees')],
            $prefix.'effective_from' => ['nullable', 'date'],
        ];
    }

    private function contactRules(): array
    {
        return [
            'contacts' => ['sometimes', 'array'],
            'contacts.*.type' => ['required_with:contacts', Rule::in(['emergency', 'next_of_kin'])],
            'contacts.*.name' => ['required_with:contacts', 'string', 'max:255'],
            'contacts.*.relationship' => ['nullable', 'string', 'max:120'],
            'contacts.*.phone' => ['nullable', 'string', 'max:80'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.address' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function bankRules(): array
    {
        return [
            'bank_accounts' => ['sometimes', 'array'],
            'bank_accounts.*.bank_name' => ['required_with:bank_accounts', 'string', 'max:255'],
            'bank_accounts.*.bank_code' => ['nullable', 'string', 'max:20'],
            'bank_accounts.*.account_number' => ['required_with:bank_accounts', 'string', 'max:40'],
            'bank_accounts.*.account_name' => ['required_with:bank_accounts', 'string', 'max:255'],
            'bank_accounts.*.is_primary' => ['sometimes', 'boolean'],
        ];
    }

    private function statutoryRules(): array
    {
        return [
            'statutory' => ['sometimes', 'array'],
            'statutory.tax_id' => ['nullable', 'string', 'max:120'],
            'statutory.pension_pin' => ['nullable', 'string', 'max:120'],
            'statutory.pension_fund_administrator' => ['nullable', 'string', 'max:255'],
            'statutory.nhf_number' => ['nullable', 'string', 'max:120'],
        ];
    }
}
