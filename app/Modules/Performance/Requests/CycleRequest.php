<?php

namespace App\Modules\Performance\Requests;

use App\Modules\Performance\Models\AppraisalCycle;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CycleRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::PERFORMANCE_MANAGE) ?? false; }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'appraisal_type' => ['sometimes', Rule::in(AppraisalCycle::TYPES)],
            'starts_at' => ['required', 'date_format:Y-m-d'],
            'ends_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_at'],
            'review_starts_at' => ['nullable', 'date_format:Y-m-d'],
            'review_ends_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:review_starts_at'],
            'self_review_enabled' => ['sometimes', 'boolean'],
            'calibration_enabled' => ['sometimes', 'boolean'],
            'appeal_window_days' => ['sometimes', 'integer', 'between:1,90'],
        ];
    }
}
