<?php

namespace App\Modules\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdministratorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore((int) $this->route('administrator')),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'timezone' => ['sometimes', 'nullable', 'timezone'],
            'platform_role_id' => ['sometimes', 'required', 'integer', 'exists:platform_roles,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'platform_role_id.required' => 'Choose what this administrator can access.',
            'platform_role_id.exists' => 'That access level no longer exists.',
        ];
    }
}
