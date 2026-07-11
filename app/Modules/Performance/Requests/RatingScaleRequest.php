<?php

namespace App\Modules\Performance\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class RatingScaleRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::PERFORMANCE_MANAGE) ?? false; }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.label' => ['required', 'string', 'max:100'],
            'options.*.min_score_basis_points' => ['required', 'integer', 'between:0,10000'],
            'options.*.max_score_basis_points' => ['required', 'integer', 'between:0,10000'],
        ];
    }
}
