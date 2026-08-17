<?php

namespace App\Modules\Admin\Requests;

use App\Support\Authorization\PlatformAbilities;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlatformRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'abilities' => ['sometimes', 'required', 'array', 'min:1'],
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
