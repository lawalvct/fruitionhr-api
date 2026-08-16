<?php

namespace App\Modules\Billing\Requests;

use App\Modules\Billing\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StorePlanRequest extends FormRequest
{
    /**
     * Derive the slug before validation runs.
     *
     * Without this the unique rule only guards a slug the caller sent
     * explicitly — a name that slugs onto an existing plan would sail past
     * validation and fail at the database as a 500 instead of a 422.
     */
    protected function prepareForValidation(): void
    {
        $source = $this->input('slug') ?: $this->input('name');

        if (is_string($source) && trim($source) !== '') {
            $this->merge(['slug' => Str::slug($source)]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.unique' => 'A plan with that name already exists. Edit the existing plan, or pick a different name.',
        ];
    }

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
