<?php

namespace App\Modules\Performance\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class GoalCheckinRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::GOALS_MANAGE) ?? false; }
    public function rules(): array
    {
        return [
            'progress' => ['required', 'integer', 'between:0,100'],
            'current_value' => ['nullable', 'integer'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
