<?php

namespace App\Modules\Recruitment\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InterviewRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::RECRUITMENT_MANAGE) ?? false; }

    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'type' => ['required', Rule::in(['interview', 'second_interview'])],
            'scheduled_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'meeting_url' => ['nullable', 'url', 'max:500'],
            'panel_user_ids' => ['nullable', 'array'],
            'panel_user_ids.*' => ['integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
