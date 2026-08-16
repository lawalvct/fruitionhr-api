<?php

namespace App\Modules\Admin\Requests;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTenantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([
                Tenant::STATUS_ACTIVE,
                Tenant::STATUS_SUSPENDED,
                Tenant::STATUS_CANCELLED,
            ])],
            'onboarding_status' => ['nullable', Rule::in([
                Tenant::ONBOARDING_NOT_STARTED,
                Tenant::ONBOARDING_IN_PROGRESS,
                Tenant::ONBOARDING_COMPLETED,
                Tenant::ONBOARDING_SKIPPED,
            ])],
            'sort' => ['nullable', Rule::in([
                'name', '-name',
                'created_at', '-created_at',
                'trial_ends_at', '-trial_ends_at',
                'status', '-status',
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
