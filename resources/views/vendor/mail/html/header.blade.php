@props(['url'])
<tr>
<td class="header">
<table class="header-card" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
{{-- Four flat cells fake the brand gradient; real CSS gradients are unreliable in mail clients. --}}
<tr>
<td class="brand-bar" bgcolor="#064E3B" width="25%" height="6">&nbsp;</td>
<td class="brand-bar" bgcolor="#047857" width="25%" height="6">&nbsp;</td>
<td class="brand-bar" bgcolor="#16A34A" width="25%" height="6">&nbsp;</td>
<td class="brand-bar" bgcolor="#22C55E" width="25%" height="6">&nbsp;</td>
</tr>
<tr>
<td class="header-logo-cell" colspan="4">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<img src="{{ config('mail.brand.logo_url') }}" class="logo" width="200" alt="{{ trim($slot) !== '' ? trim($slot) : config('mail.brand.product') }}" style="color: #064e3b; font-size: 20px; font-weight: bold;">
</a>
</td>
</tr>
</table>
</td>
</tr>
