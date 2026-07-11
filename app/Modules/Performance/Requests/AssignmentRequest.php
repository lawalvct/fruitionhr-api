<?php

namespace App\Modules\Performance\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignmentRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::PERFORMANCE_MANAGE) ?? false; }
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();
        return [
            'appraisal_cycle_id' => ['required', 'integer', Rule::exists('appraisal_cycles', 'id')->where('tenant_id', $tenantId)],
            'appraisal_template_id' => ['required', 'integer', Rule::exists('appraisal_templates', 'id')->where('tenant_id', $tenantId)],
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'reviewers' => ['required', 'array', 'min:1'],
            'reviewers.*.reviewer_user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'reviewers.*.reviewer_type' => ['required', Rule::in(['self', 'manager', 'peer', 'subordinate', 'customer'])],
            'reviewers.*.weight' => ['required', 'integer', 'between:1,100'],
        ];
    }
}
