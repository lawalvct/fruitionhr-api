@props([
    'label' => 'Verification code',
    'expiry' => null,
])
{{ strtoupper($label) }}: {{ trim($slot) }}
@if (! empty($expiry))
{{ $expiry }}
@endif
