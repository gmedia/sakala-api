<x-mail::message :title="$subject ?? 'Sakala'" :preheader="$preheader ?? null" :headline="$headline ?? null" :reason="$reason ?? null">
@if (filled($greeting ?? null))
{{ $greeting }}

@endif
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
</x-mail::message>
