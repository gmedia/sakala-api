@props(['title' => 'Sakala', 'preheader' => null, 'headline' => null, 'reason' => null, 'preferences' => null])
@php($site = rtrim((string) config('sakala.landing_url'), '/'))
<x-mail::layout :title="$title" :preheader="$preheader">
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('sakala.console_url')" :logo="config('sakala.mail_logo_url')" />
</x-slot:header>

{{-- Body --}}
@if (filled($headline))
# {{ $headline }}

@endif
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer: why this address, who sends it, and the documents behind it. No tracking pixel. --}}
<x-slot:footer>
<x-mail::footer>
@if (filled($reason))
<p>{{ $reason }}</p>
@endif
@if (is_array($preferences))
<p><a href="{{ $preferences['settings_url'] }}" target="_blank" rel="noopener">Atur notifikasi email</a>&nbsp;&nbsp;&middot;&nbsp;&nbsp;<a href="{{ $preferences['unsubscribe_url'] }}" target="_blank" rel="noopener">Berhenti menerima email {{ $preferences['category'] }}</a></p>
@endif
<p>Sakala dikelola oleh Sakala Maintainers, pengelola proyek open-source Sakala. Infrastrukturnya didukung PT Media Sarana Data (GMEDIA) sebagai founding sponsor. Email ini dikirim otomatis, jadi tidak perlu dibalas.</p>
<p><a href="{{ $site }}/privasi" target="_blank" rel="noopener">Pemberitahuan Privasi</a>&nbsp;&nbsp;&middot;&nbsp;&nbsp;<a href="{{ $site }}/syarat" target="_blank" rel="noopener">Syarat Layanan</a></p>
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
