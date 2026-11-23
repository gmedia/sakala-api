<x-mail::message :title="$subject ?? 'Sakala'" :preheader="$preheader ?? null" :headline="$headline ?? null" :reason="$reason ?? null" :preferences="$preferences ?? null">
@if (filled($greeting ?? null))
{{ $greeting }}

@endif
@foreach ($introLines as $line)
@if ($line instanceof \App\Notifications\Messages\AddressLine)
{{ $line->before }}<x-mail::mailto :address="$line->address" />{{ $line->after }}
@else
{{ $line }}
@endif

@endforeach
@isset($actionText)
<x-mail::button :url="$actionUrl" color="primary">
{{ $actionText }}
</x-mail::button>

@endisset
@foreach ($outroLines as $line)
@if ($line instanceof \App\Notifications\Messages\AddressLine)
{{ $line->before }}<x-mail::mailto :address="$line->address" />{{ $line->after }}
@else
{{ $line }}
@endif

@endforeach
</x-mail::message>
