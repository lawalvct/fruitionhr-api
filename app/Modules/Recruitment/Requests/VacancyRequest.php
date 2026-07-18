<?php

namespace App\Modules\Recruitment\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use App\Modules\Recruitment\Models\Vacancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VacancyRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::RECRUITMENT_MANAGE) ?? false; }

    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();
        $vacancyId = $this->route('vacancy')?->id;

        return [
            'manpower_requisition_id' => ['required', 'integer', Rule::exists('manpower_requisitions', 'id')->where('tenant_id', $tenantId)->where('status', 'approved')],
            'employment_type_id' => ['nullable', 'integer', Rule::exists('employment_types', 'id')->where('tenant_id', $tenantId)],
            'title' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('vacancies', 'code')->where('tenant_id', $tenantId)->ignore($vacancyId)],
            'description' => ['required', 'string', 'max:10000'],
            'requirements' => ['nullable', 'string', 'max:10000'],
            'location' => ['nullable', 'string', 'max:255'],
            'positions_available' => ['required', 'integer', 'min:1', 'max:1000'],
            'opens_at' => ['nullable', 'date_format:Y-m-d'],
            'closes_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:opens_at'],
            'visibility' => ['sometimes', Rule::in([Vacancy::VISIBILITY_PRIVATE, Vacancy::VISIBILITY_PUBLIC])],
        ];
    }
}
