@props(['url', 'color' => 'primary'])
@php($label = trim(strip_tags((string) $slot)))
{{-- Outlook's Word renderer ignores padding on links, so it gets a VML twin of fixed size; the comment is never re-encoded, hence e(). --}}
@php($vmlWidth = max(200, mb_strlen($label) * 10 + 64))
<table class="action" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="left">
<table class="action-table" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="button-cell" align="center" bgcolor="#0f766e">
<!--[if mso]>
<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{!! e($url) !!}" style="height:48px;v-text-anchor:middle;width:{{ $vmlWidth }}px;" arcsize="17%" stroke="f" fillcolor="#0f766e">
<w:anchorlock/>
<center style="color:#ffffff;font-family:'Segoe UI',Arial,sans-serif;font-size:16px;font-weight:600;">{{ $label }}</center>
</v:roundrect>
<![endif]-->
<!--[if !mso]><!-->
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener">{{ $label }}</a>
<!--<![endif]-->
</td>
</tr>
</table>
</td>
</tr>
</table>
<p class="fallback">Kalau tombolnya tidak bisa diklik, salin tautan ini ke browser:<br /><a href="{{ $url }}" target="_blank" rel="noopener">{{ $url }}</a></p>
