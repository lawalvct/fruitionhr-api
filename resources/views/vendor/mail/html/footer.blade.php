<tr>
<td>
<table class="footer" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="footer-cell" align="center">
<p class="footer-brand">{{ config('mail.brand.product') }} &mdash; {{ config('mail.brand.tagline') }}</p>
<p class="footer-links">
<a href="{{ config('mail.brand.app_url') }}">Dashboard</a>
&nbsp;&middot;&nbsp;
<a href="{{ config('mail.brand.website_url') }}">Website</a>
&nbsp;&middot;&nbsp;
<a href="mailto:{{ config('mail.brand.support_email') }}">Support</a>
</p>
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>
</td>
</tr>
