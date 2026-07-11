<?php

namespace App\Modules\Performance\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GoalRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::GOALS_MANAGE) ?? false; }
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();
        return [
            'level' => ['required', Rule::in(['company', 'department', 'individual'])],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('tenant_id', $tenantId)],
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
            'parent_id' => ['nullable', 'integer', Rule::exists('goals', 'id')->where('tenant_id', $tenantId)],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'weight' => ['required', 'integer', 'between:0,100'],
            'target_value' => ['nullable', 'integer'],
            'current_value' => ['nullable', 'integer'],
            'measurement_unit' => ['nullable', 'string', 'max:100'],
            'progress' => ['sometimes', 'integer', 'between:0,100'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'completed', 'cancelled'])],
            'starts_at' => ['nullable', 'date_format:Y-m-d'],
            'due_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_at'],
        ];
    }
}
