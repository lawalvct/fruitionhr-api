<x-mail::layout>
{{-- Preheader: the grey preview snippet inboxes show next to the subject. --}}
@isset($preheader)
<x-slot:preheader>
{{ $preheader }}
</x-slot:preheader>
@endisset

{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('mail.brand.app_url')">
{{ config('mail.brand.product') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
This is an automated message from {{ config('mail.brand.product') }}. Please do not reply to this email &mdash; reach us at [{{ config('mail.brand.support_email') }}](mailto:{{ config('mail.brand.support_email') }}) instead.

&copy; {{ date('Y') }} {{ config('mail.brand.company') }}. {{ config('mail.brand.address') }}. All rights reserved.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
