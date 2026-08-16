<?php

namespace App\Modules\Admin\Requests;

use App\Models\User;
use App\Modules\Admin\Services\PlatformUserService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPlatformUsersRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([User::STATUS_ACTIVE, User::STATUS_INVITED, User::STATUS_DISABLED])],
            'type' => ['nullable', Rule::in([PlatformUserService::TYPE_ADMIN, PlatformUserService::TYPE_TENANT])],
            'tenant_id' => ['nullable', 'integer', 'min:1'],
            'verified' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // "verified" arrives as a query string; keep an absent filter absent
        // rather than collapsing it to false.
        if ($this->query('verified') !== null && $this->query('verified') !== '') {
            $this->merge(['verified' => filter_var($this->query('verified'), FILTER_VALIDATE_BOOLEAN)]);
        }
    }
}
