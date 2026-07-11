<?php

namespace App\Modules\Recruitment\Requests;

use App\Modules\Recruitment\Models\Application;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveApplicationRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::RECRUITMENT_MANAGE) ?? false; }
    public function rules(): array { return ['stage' => ['required', Rule::in(array_values(array_diff(Application::STAGES, ['hired'])))], 'notes' => ['nullable', 'string', 'max:2000']]; }
}
