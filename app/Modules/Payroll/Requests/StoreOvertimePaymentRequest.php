<?php

namespace App\Modules\Payroll\Requests;

use App\Modules\Payroll\Models\OvertimePayment;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOvertimePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::OVERTIME_MANAGE) ?? false;
    }

    public function rules(): array
    {
        $multipliers = array_map(fn ($m) => (float) $m, config('payroll.overtime.multipliers', [1, 1.5, 2]));

        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'work_date' => ['nullable', 'date'],
            'pay_type' => ['required', Rule::in([OvertimePayment::PAY_TYPE_HOURLY, OvertimePayment::PAY_TYPE_FIXED])],
            'disbursement_mode' => ['required', Rule::in([OvertimePayment::MODE_IN_PAYROLL, OvertimePayment::MODE_OFF_CYCLE])],
            'reason' => ['nullable', 'string', 'max:255'],

            // Hourly
            'hours' => ['required_if:pay_type,hourly', 'nullable', 'numeric', 'gt:0', 'max:744'],
            'multiplier' => ['required_if:pay_type,hourly', 'nullable', 'numeric', Rule::in($multipliers)],

            // Fixed (amount in kobo)
            'amount' => ['required_if:pay_type,fixed', 'nullable', 'integer', 'gt:0'],
        ];
    }
}
