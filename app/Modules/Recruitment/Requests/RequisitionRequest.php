<?php

namespace App\Modules\Recruitment\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequisitionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::RECRUITMENT_MANAGE) ?? false; }

    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('tenant_id', $tenantId)],
            'position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')->where('tenant_id', $tenantId)],
            'employment_type_id' => ['nullable', 'integer', Rule::exists('employment_types', 'id')->where('tenant_id', $tenantId)],
            'title' => ['required', 'string', 'max:255'],
            'headcount' => ['required', 'integer', 'min:1', 'max:1000'],
            'target_start_date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }
}
