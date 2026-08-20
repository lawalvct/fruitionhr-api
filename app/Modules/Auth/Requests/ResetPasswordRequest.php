<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'token' => ['required', 'string'],
            // Matches UpdatePasswordRequest so the rules a user meets here are
            // the same ones they meet changing a password from their profile.
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }
}
