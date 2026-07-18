<?php

namespace App\Modules\Payroll\Requests;

use App\Modules\Payroll\Models\StaffLoan;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::LOANS_MANAGE) ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
            'type' => ['required', Rule::in([StaffLoan::TYPE_ADVANCE, StaffLoan::TYPE_LOAN])],
            'principal' => ['required', 'integer', 'gt:0'],           // kobo
            'months' => ['required_if:type,loan', 'nullable', 'integer', 'min:1', 'max:60'],
            'start_period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
