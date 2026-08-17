<?php

namespace App\Modules\Recruitment\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Everything here arrives from an anonymous visitor, so each rule is a trust
 * boundary rather than a convenience: the values end up in a recruiter's
 * browser and on a recruiter's disk.
 */
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
            // Protocol-restricted deliberately: the candidate sheet renders
            // this straight into an href, so a bare `url` rule would let an
            // applicant store a `javascript:` link and fire it at whichever
            // recruiter clicks "LinkedIn profile".
            'linkedin_url' => ['nullable', 'url:http,https', 'max:500'],
            'cover_letter' => ['nullable', 'string', 'max:10000'],
            'resume' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
            'privacy_consent' => ['accepted'],
            // Honeypot: hidden from people, filled in by form bots.
            //
            // Deliberately NOT called "website". A honeypot has to be invisible
            // to password managers and browser autofill as well as to humans,
            // and those happily fill a field named website/url/homepage —
            // autocomplete="off" is advisory and widely ignored. This form is
            // otherwise a textbook contact form (name, email, tel, city,
            // state), so an autofiller sweeping it would trip the trap and
            // reject a real candidate. The name must stay inert; keep it in
            // step with the input in fruitionhr-web's public-application-form.
            'referrer_code' => ['prohibited'],
        ];
    }
}
