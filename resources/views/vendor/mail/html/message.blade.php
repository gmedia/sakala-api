<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('sakala.console_url')">
Sakala
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
Email ini dikirim otomatis oleh Sakala, jadi tidak perlu dibalas.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
