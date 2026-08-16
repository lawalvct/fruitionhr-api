<?php

namespace App\Modules\Admin\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAdministratorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([
                User::STATUS_ACTIVE,
                User::STATUS_INVITED,
                User::STATUS_DISABLED,
            ])],
            'sort' => ['nullable', Rule::in([
                'name', '-name',
                'email', '-email',
                'created_at', '-created_at',
                'status', '-status',
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
