<?php

namespace App\Modules\Performance\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::PERFORMANCE_MANAGE) ?? false; }
    public function rules(): array { return ['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'is_active' => ['sometimes', 'boolean']]; }
}
