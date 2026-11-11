@props(['title' => 'Sakala', 'preheader' => null])
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="id">
<head>
<title>{{ $title }}</title>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="x-apple-disable-message-reformatting" />
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no" />
<meta name="color-scheme" content="light dark" />
<meta name="supported-color-schemes" content="light dark" />
<!--[if mso]>
<noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
<style>body, table, td, p, a, h1 { font-family: 'Segoe UI', Arial, sans-serif !important; }</style>
<![endif]-->
<style>
@media only screen and (max-width: 480px) {
.page-cell { padding: 16px 8px !important; }
.card { padding: 24px 20px !important; }
.action-table { width: 100% !important; }
.button { display: block !important; }
}
</style>
<style>
:root { color-scheme: light dark; supported-color-schemes: light dark; }
@media (prefers-color-scheme: dark) {
body, .wrapper, .page-cell { background-color: #0d1411 !important; }
.card { background-color: #17211b !important; border-color: #2c3a31 !important; }
h1, p, li { color: #e6ede8 !important; }
a, .wordmark { color: #5fbfb5 !important; }
.fallback, .footer p { color: #a3b0a7 !important; }
.footer a, .fallback a { color: #5fbfb5 !important; }
.button-cell { background-color: #0f766e !important; }
.button { background-color: #0f766e !important; border-color: #0f766e !important; color: #ffffff !important; }
}
</style>
<style>
[data-ogsb] body, [data-ogsb] .wrapper, [data-ogsb] .page-cell { background-color: #0d1411 !important; }
[data-ogsb] .card { background-color: #17211b !important; border-color: #2c3a31 !important; }
[data-ogsc] h1, [data-ogsc] p { color: #e6ede8 !important; }
[data-ogsc] a, [data-ogsc] .wordmark { color: #5fbfb5 !important; }
[data-ogsc] .fallback, [data-ogsc] .footer p { color: #a3b0a7 !important; }
[data-ogsc] .button { color: #ffffff !important; }
</style>
{!! $head ?? '' !!}
</head>
<body>
@if (filled($preheader))
<div class="preheader" style="display: none; max-height: 0; max-width: 0; overflow: hidden; mso-hide: all; font-size: 1px; line-height: 1px; opacity: 0;">{{ $preheader }}{!! str_repeat('&#8199;&#65279;&#847; ', 60) !!}</div>
@endif
<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="page-cell" align="center">
<!--[if mso]><table role="presentation" align="center" width="600" cellpadding="0" cellspacing="0"><tr><td><![endif]-->
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}
<tr>
<td class="card">
{!! Illuminate\Mail\Markdown::parse($slot) !!}
{!! $subcopy ?? '' !!}
</td>
</tr>
{!! $footer ?? '' !!}
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td>
</tr>
</table>
</body>
</html>
