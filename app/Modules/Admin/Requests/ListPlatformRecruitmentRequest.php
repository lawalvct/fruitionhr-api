<?php

namespace App\Modules\Admin\Requests;

use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Vacancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPlatformRecruitmentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([Vacancy::STATUS_DRAFT, Vacancy::STATUS_OPEN, Vacancy::STATUS_CLOSED])],
            'stage' => ['nullable', Rule::in(Application::STAGES)],
            'tenant_id' => ['nullable', 'integer', 'min:1'],
            'vacancy_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
