<x-mail::message>
<x-slot:preheader>
Your {{ config('mail.brand.product') }} password was reset by our support team. Sign in with the temporary password inside.
</x-slot:preheader>

# Your password has been reset

Hi {{ $name }},

Someone on the {{ config('mail.brand.product') }} support team reset the password for **{{ $email }}** at your request. Use the temporary password below to sign in.

<x-mail::code label="Temporary password" size="sm" expiry="Change it as soon as you are back in">
{{ $password }}
</x-mail::code>

<x-mail::button :url="config('mail.brand.app_url').'/login'" color="primary">
Sign in
</x-mail::button>

<x-mail::panel color="warning">
**Change this password once you are signed in.** Go to your profile settings and set something only you know — a temporary password sent by email should never be your long-term one.
</x-mail::panel>

Did not ask for this? Contact us straight away at [{{ config('mail.brand.support_email') }}](mailto:{{ config('mail.brand.support_email') }}) — someone may have requested it on your behalf.

Regards,<br>
The {{ config('mail.brand.product') }} Team

<x-slot:subcopy>
For your security you have been signed out everywhere. Anyone still using your old password on another device will need to sign in again.
</x-slot:subcopy>
</x-mail::message>
