<x-mail::message>
<x-slot:preheader>
Your {{ config('mail.brand.product') }} verification code is {{ $code }}. It expires in {{ $expiresInMinutes }} minutes.
</x-slot:preheader>

@php($workspace = $company ? 'the '.$company.' workspace' : 'your workspace')
# Confirm your email address

Hi {{ $name }},

Welcome to {{ config('mail.brand.product') }}. Enter the code below to confirm this address and open {{ $workspace }}.

<x-mail::code label="Your verification code" :expiry="'This code expires in '.$expiresInMinutes.' minutes'">
{{ $code }}
</x-mail::code>

Once your email is confirmed you can set up your company profile, add employees and run your first payroll.

<x-mail::panel color="warning">
**Keep this code to yourself.** {{ config('mail.brand.product') }} staff will never call, text or email you asking for it.
</x-mail::panel>

Didn't create a {{ config('mail.brand.product') }} account? You can safely ignore this email — the workspace stays locked until the code is used.

Regards,<br>
The {{ config('mail.brand.product') }} Team

<x-slot:subcopy>
Requested a new code? Only the most recent one works — earlier codes stop working as soon as a new one is sent.
</x-slot:subcopy>
</x-mail::message>
