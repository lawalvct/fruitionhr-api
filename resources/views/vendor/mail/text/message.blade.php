<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('mail.brand.app_url')">
{{ config('mail.brand.product') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{{ $slot }}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ config('mail.brand.product') }} — {{ config('mail.brand.tagline') }}
Dashboard: {{ config('mail.brand.app_url') }}
Support: {{ config('mail.brand.support_email') }}

This is an automated message. Please do not reply to this email.
© {{ date('Y') }} {{ config('mail.brand.company') }}. {{ config('mail.brand.address') }}. All rights reserved.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
