<x-mail::message>
# Portal de subempreiteiros

{{ \App\Mail\ProjectNoticeMail::escape($inviterName) }}, da **{{ \App\Mail\ProjectNoticeMail::escape($companyName) }}**, deu-lhe acesso ao portal de subempreiteiros do ObraConnect em nome de **{{ \App\Mail\ProjectNoticeMail::escape($partnerName) }}**.

No portal vê os contratos de subempreitada com a {{ \App\Mail\ProjectNoticeMail::escape($companyName) }}, o que já foi autado e aprovado, propõe os autos de cada período e envia documentos (seguro, alvará, declarações de não dívida).

<x-mail::button :url="$url">
Aceitar o acesso
</x-mail::button>

O convite é válido até {{ $expiresAt->timezone('Europe/Lisbon')->format('d/m/Y H:i') }}. Se ainda não tem conta no ObraConnect, cria-a ao aceitar.

Se não esperava este email, pode ignorá-lo.

{{ config('app.name') }}
</x-mail::message>
