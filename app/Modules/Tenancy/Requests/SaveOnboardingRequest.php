<?php

namespace App\Modules\Tenancy\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('owner') === true;
    }

    public function rules(): array
    {
        return [
            'step' => ['required', 'integer', 'between:1,3'],
            'company_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'industry' => ['nullable', 'string', 'max:100'],
            'company_size' => ['nullable', Rule::in(['1-10', '11-50', '51-200', '201-500', '500+'])],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'timezone'],
            'currency' => ['nullable', Rule::in(['NGN'])],
            'pay_frequency' => ['nullable', Rule::in(['monthly', 'biweekly', 'weekly'])],
            'pay_day' => ['nullable', 'integer', 'between:1,31'],
            'working_days' => ['nullable', 'array', 'min:1'],
            'working_days.*' => ['string', 'distinct', Rule::in([
                'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
            ])],
            'tax_state' => ['nullable', 'string', 'max:100'],
            'tin' => ['nullable', 'string', 'max:100'],
            'rc_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
