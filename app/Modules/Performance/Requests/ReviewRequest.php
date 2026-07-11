<?php

namespace App\Modules\Performance\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::PERFORMANCE_REVIEW) ?? false; }
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();
        return [
            'comments' => ['nullable', 'string', 'max:10000'],
            'scores' => ['required', 'array', 'min:1'],
            'scores.*.appraisal_template_item_id' => ['required', 'distinct', 'integer', Rule::exists('appraisal_template_items', 'id')->where('tenant_id', $tenantId)],
            'scores.*.score_basis_points' => ['required', 'integer', 'between:0,10000'],
            'scores.*.comments' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
