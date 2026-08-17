<?php

namespace App\Modules\Admin\Requests;

use App\Support\Authorization\PlatformAbilities;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlatformRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            // Rule::in over the assignable subset, so "administrators" can
            // never be smuggled into a custom role — the only route to owner
            // is being handed the built-in Owner role.
            'abilities.*' => ['string', Rule::in(PlatformAbilities::assignable())],
        ];
    }

    public function messages(): array
    {
        return [
            'abilities.required' => 'Choose at least one section this role can reach.',
            'abilities.*.in' => 'That is not a section a role can be given.',
        ];
    }
}
