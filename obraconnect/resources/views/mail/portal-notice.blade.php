<x-mail::message>
# {{ $heading }}

@foreach ($lines as $line)
{{ \App\Mail\ProjectNoticeMail::escape($line) }}

@endforeach
<x-mail::button :url="$url">
{{ $button }}
</x-mail::button>

Recebe este aviso por ter acesso ao portal de subempreiteiros da {{ \App\Mail\ProjectNoticeMail::escape($companyName) }}.

{{ config('app.name') }}
</x-mail::message>
