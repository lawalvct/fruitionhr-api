<?php

namespace App\Modules\Recruitment\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublicApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'linkedin_url' => ['nullable', 'url', 'max:500'],
            'cover_letter' => ['nullable', 'string', 'max:10000'],
            'resume' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
            'privacy_consent' => ['accepted'],
            'website' => ['prohibited'],
        ];
    }
}
