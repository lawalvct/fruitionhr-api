<?php

namespace App\Modules\Billing\Requests;

use App\Modules\Billing\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlanRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'price_per_employee' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'billing_interval' => ['sometimes', Rule::in([Plan::INTERVAL_MONTHLY, Plan::INTERVAL_YEARLY])],
            'min_employees' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'max_employees' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'features' => ['nullable', 'array', 'max:30'],
            'features.*' => ['string', 'max:120'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
