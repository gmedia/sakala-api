@props(['title' => 'Sakala', 'preheader' => null, 'headline' => null, 'reason' => null])
@php($site = rtrim((string) config('sakala.landing_url'), '/'))
<x-mail::layout>
<x-slot:header>
Sakala
</x-slot:header>
@if (filled($headline))
{{ $headline }}

@endif
{{ $slot }}
@isset($subcopy)
<x-slot:subcopy>
{{ $subcopy }}
</x-slot:subcopy>
@endisset
<x-slot:footer>
--
@if (filled($reason))
{{ $reason }}

@endif
Sakala dikelola oleh Sakala Maintainers, pengelola proyek open-source Sakala. Infrastrukturnya didukung PT Media Sarana Data (GMEDIA) sebagai founding sponsor. Email ini dikirim otomatis, jadi tidak perlu dibalas.

Pemberitahuan Privasi: {{ $site }}/privasi
Syarat Layanan: {{ $site }}/syarat
</x-slot:footer>
</x-mail::layout>
