@props([
    'label' => 'Verification code',
    'expiry' => null,
])
<table class="code-block" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="code-block-cell" align="center">
<p class="code-label">{{ $label }}</p>
<p class="code-value">{{ trim($slot) }}</p>
@if (! empty($expiry))
<p class="code-expiry">{{ $expiry }}</p>
@endif
</td>
</tr>
</table>
