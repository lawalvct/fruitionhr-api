<?php

namespace App\Modules\Performance\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TemplateRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::PERFORMANCE_MANAGE) ?? false; }
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();
        return [
            'rating_scale_id' => ['required', 'integer', Rule::exists('rating_scales', 'id')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.performance_kpi_id' => ['required', 'distinct', 'integer', Rule::exists('performance_kpis', 'id')->where('tenant_id', $tenantId)],
            'items.*.weight' => ['required', 'integer', 'between:1,100'],
        ];
    }
}
