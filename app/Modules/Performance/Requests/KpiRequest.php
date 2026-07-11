<?php

namespace App\Modules\Performance\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KpiRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::PERFORMANCE_MANAGE) ?? false; }
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();
        return [
            'performance_category_id' => ['required', 'integer', Rule::exists('performance_categories', 'id')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'measurement_unit' => ['nullable', 'string', 'max:100'],
            'target_description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
