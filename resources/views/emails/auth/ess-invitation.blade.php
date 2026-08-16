<x-mail::message>
<x-slot:preheader>
{{ $company }} has set up your employee self-service account. Choose a password to activate it.
</x-slot:preheader>

# You've been invited to your employee portal

Hi {{ $name }},

**{{ $company }}** has created an Employee Self-Service account for you on {{ config('mail.brand.product') }}. Choose a password to activate it — that's the only step left.

<x-mail::button :url="$setupUrl" color="primary">
Set my password
</x-mail::button>

<x-mail::details :rows="[
    'Company' => $company,
    'Sign-in email' => $email,
    'Invitation valid for' => $expiresInMinutes.' minutes',
]" />

## What you can do once you're in

- View and download your payslips
- Request leave and follow it through approval
- Clock in and out, and review your attendance
- Keep your personal and next-of-kin details current

<x-mail::panel color="warning">
**This invitation expires in {{ $expiresInMinutes }} minutes.** If the link has already lapsed, ask your HR administrator to resend it — it takes them a moment.
</x-mail::panel>

Regards,<br>
The {{ config('mail.brand.product') }} Team

<x-slot:subcopy>
If the button above doesn't work, copy and paste this link into your browser: <span class="break-all">[{{ $setupUrl }}]({{ $setupUrl }})</span>

Weren't expecting this invitation? Contact your HR administrator before using the link.
</x-slot:subcopy>
</x-mail::message>
