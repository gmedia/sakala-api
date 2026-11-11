@props(['url', 'logo'])
{{-- A 280 x 118 PNG shown at 140 x 59. When images are blocked the alt text stands in, styled as the teal wordmark. --}}
<tr>
<td class="header">
<a href="{{ $url }}" class="wordmark" target="_blank" rel="noopener"><img src="{{ $logo }}" width="140" height="59" alt="Sakala" class="logo" /></a>
</td>
</tr>
