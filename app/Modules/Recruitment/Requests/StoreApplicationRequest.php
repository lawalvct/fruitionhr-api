<?php

namespace App\Modules\Recruitment\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can(Permissions::RECRUITMENT_MANAGE) ?? false; }

    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'vacancy_id' => ['required', 'integer', Rule::exists('vacancies', 'id')->where('tenant_id', $tenantId)->where('status', 'open')],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'linkedin_url' => ['nullable', 'url:http,https', 'max:500'],
            'source' => ['nullable', 'string', 'max:100'],
            'cover_letter' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
