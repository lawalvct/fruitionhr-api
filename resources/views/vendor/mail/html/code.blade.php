@props([
    'label' => 'Verification code',
    'expiry' => null,
    // 'sm' suits long values such as word-based passwords; the default
    // wide tracking is tuned for short numeric codes.
    'size' => 'lg',
])
<table class="code-block" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="code-block-cell" align="center">
<p class="code-label">{{ $label }}</p>
<p class="code-value code-value-{{ $size }}">{{ trim($slot) }}</p>
@if (! empty($expiry))
<p class="code-expiry">{{ $expiry }}</p>
@endif
</td>
</tr>
</table>
