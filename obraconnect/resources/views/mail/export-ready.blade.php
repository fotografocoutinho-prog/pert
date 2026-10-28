<x-mail::message>
# A exportação de dados está pronta

A exportação dos dados da empresa **{{ $companyName }}** foi concluída.

<x-mail::button :url="$url">
Abrir exportações
</x-mail::button>

@if ($expiresAt)
O ficheiro fica disponível até {{ $expiresAt->timezone('Europe/Lisbon')->format('d/m/Y H:i') }}. Depois disso é apagado automaticamente.
@endif

Por segurança, o download exige sessão iniciada e permissão de exportação.

{{ config('app.name') }}
</x-mail::message>
