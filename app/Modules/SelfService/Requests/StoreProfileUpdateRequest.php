<?php

namespace App\Modules\SelfService\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProfileUpdateRequest extends FormRequest
{
    public const FIELDS = [
        'personal_email',
        'phone',
        'marital_status',
        'address',
        'city',
        'state',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::ESS_PROFILE_UPDATE) ?? false;
    }

    public function rules(): array
    {
        return [
            'personal_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'marital_status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $submitted = collect(self::FIELDS)
                    ->contains(fn (string $field): bool => $this->exists($field));

                if (! $submitted) {
                    $validator->errors()->add('profile', 'At least one profile field is required.');
                }
            },
        ];
    }
}
