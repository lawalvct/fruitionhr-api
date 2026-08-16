@props(['rows' => []])
<table class="details" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="details-cell">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($rows as $label => $value)
<tr>
<td class="details-label">{{ $label }}</td>
<td class="details-value" align="right">{{ $value }}</td>
</tr>
@endforeach
</table>
</td>
</tr>
</table>
