<?php

namespace App\Modules\SelfService\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSelfLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::ESS_LEAVE_APPLY) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('tenant_id', $tenantId)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
