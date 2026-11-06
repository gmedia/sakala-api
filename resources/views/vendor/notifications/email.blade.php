<x-mail::message>
# {{ $greeting }}

@foreach ($introLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<x-mail::button :url="$actionUrl" color="primary">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<x-slot:subcopy>
Kalau tombolnya tidak bisa diklik, salin tautan ini ke browser: <a href="{{ $actionUrl }}" style="word-break: break-all;">{{ $displayableActionUrl }}</a>
</x-slot:subcopy>
@endisset
</x-mail::message>
