<x-mail::message>
<x-slot:preheader>
Use the link inside to choose a new {{ config('mail.brand.product') }} password. It expires in {{ $expiresInMinutes }} minutes.
</x-slot:preheader>

# Reset your password

Hi {{ $name }},

We received a request to reset the password for your {{ config('mail.brand.product') }} account. Choose a new one using the button below.

<x-mail::button :url="$resetUrl" color="primary">
Choose a new password
</x-mail::button>

<x-mail::details :rows="[
    'Account' => $email,
    'Link valid for' => $expiresInMinutes.' minutes',
]" />

<x-mail::panel color="warning">
**Didn't ask for this?** You can ignore this email — your password stays as it is, and the link above expires on its own. If you keep receiving these, contact us at {{ config('mail.brand.support_email') }}.
</x-mail::panel>

Regards,<br>
The {{ config('mail.brand.product') }} Team

<x-slot:subcopy>
If the button above doesn't work, copy and paste this link into your browser: <span class="break-all">[{{ $resetUrl }}]({{ $resetUrl }})</span>

This link can only be used once, and only from this email.
</x-slot:subcopy>
</x-mail::message>
