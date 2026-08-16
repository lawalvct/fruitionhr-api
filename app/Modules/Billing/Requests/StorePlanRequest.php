<?php

namespace App\Modules\Billing\Requests;

use App\Modules\Billing\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'unique:plans,slug'],
            'description' => ['nullable', 'string', 'max:255'],
            // Kobo. Integer only — a float here is a billing bug.
            'price_per_employee' => ['required', 'integer', 'min:0', 'max:100000000'],
            'billing_interval' => ['required', Rule::in([Plan::INTERVAL_MONTHLY, Plan::INTERVAL_YEARLY])],
            'min_employees' => ['required', 'integer', 'min:1', 'max:100000'],
            'max_employees' => ['nullable', 'integer', 'gte:min_employees', 'max:100000'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'features' => ['nullable', 'array', 'max:30'],
            'features.*' => ['string', 'max:120'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
