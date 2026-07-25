<?php

namespace App\Modules\Tenancy\Requests;

use App\Modules\Reference\Models\Country;
use App\Modules\Reference\Models\State;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('owner') === true;
    }

    protected function prepareForValidation(): void
    {
        $companySize = $this->input('company_size');

        if (is_string($companySize)) {
            $this->merge([
                'company_size' => str($companySize)->replaceEnd(' employees', '')->toString(),
            ]);
        }
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
            'country_code' => ['nullable', 'string', 'size:2', 'exists:countries,code'],
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
            'seed_performance_defaults' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $countryCode = $this->string('country_code')->upper()->toString();

            if ($countryCode === '') {
                return;
            }

            $countryId = Country::query()->where('code', $countryCode)->value('id');

            foreach (['state', 'tax_state'] as $field) {
                $state = $this->input($field);

                if ($state !== null && $state !== '' && ! State::query()
                    ->where('country_id', $countryId)
                    ->where('name', $state)
                    ->exists()) {
                    $validator->errors()->add($field, 'Select a valid state for the chosen country.');
                }
            }
        }];
    }
}
