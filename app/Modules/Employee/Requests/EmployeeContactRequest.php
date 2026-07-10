<?php

namespace App\Modules\Employee\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['emergency', 'next_of_kin'])],
            'name' => ['required', 'string', 'max:255'],
            'relationship' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
