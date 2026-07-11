<?php

namespace App\Modules\Recruitment\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class OfferRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::RECRUITMENT_MANAGE) ?? false; }

    public function rules(): array
    {
        return [
            'annual_salary' => ['nullable', 'integer', 'min:0'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'terms' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
