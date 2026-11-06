<x-mail::message>
# {{ $heading }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
<x-mail::button :url="$url">
{{ $button }}
</x-mail::button>

Recebe este aviso por estar na equipa da obra {{ $projectLabel }} ({{ $companyName }}).

{{ config('app.name') }}
</x-mail::message>
