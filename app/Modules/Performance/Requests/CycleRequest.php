<?php

namespace App\Modules\Performance\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class CycleRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::PERFORMANCE_MANAGE) ?? false; }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date_format:Y-m-d'],
            'ends_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_at'],
            'review_starts_at' => ['nullable', 'date_format:Y-m-d'],
            'review_ends_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:review_starts_at'],
        ];
    }
}
