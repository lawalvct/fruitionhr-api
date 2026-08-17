<?php

namespace App\Modules\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreAdministratorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'timezone' => ['nullable', 'timezone'],
            // Required, not optional: an administrator with no role can sign in
            // and see nothing, which reads as a broken account rather than a
            // deliberate one.
            'platform_role_id' => ['required', 'integer', 'exists:platform_roles,id'],
            'password' => [
                'required',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
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
