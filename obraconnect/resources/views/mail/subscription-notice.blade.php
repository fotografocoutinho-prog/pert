<x-mail::message>
# {{ $heading }}

@foreach ($lines as $line)
{{ \App\Mail\ProjectNoticeMail::escape($line) }}

@endforeach
<x-mail::button :url="$url">
{{ $button }}
</x-mail::button>

{{ \App\Mail\ProjectNoticeMail::escape($footer) }}

{{ config('app.name') }}
</x-mail::message>
