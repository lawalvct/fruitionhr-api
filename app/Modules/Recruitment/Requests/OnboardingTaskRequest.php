<?php

namespace App\Modules\Recruitment\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OnboardingTaskRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::RECRUITMENT_MANAGE) ?? false; }

    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
