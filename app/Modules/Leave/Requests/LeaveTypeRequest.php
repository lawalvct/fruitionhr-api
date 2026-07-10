<?php

namespace App\Modules\Leave\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class LeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::COMPANY_MANAGE) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:20'],
            'is_paid' => ['sometimes', 'boolean'],
            'requires_document' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'days_per_year' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'carry_forward_max' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ];
    }
}
