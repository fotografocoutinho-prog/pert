<x-mail::message>
# Foi convidado para {{ $companyName }}

{{ $inviterName }} convidou-o para trabalhar na empresa **{{ $companyName }}** no ObraConnect, com o perfil **{{ $roleName }}**.

<x-mail::button :url="$url">
Aceitar convite
</x-mail::button>

O convite é válido até {{ $expiresAt->timezone('Europe/Lisbon')->format('d/m/Y H:i') }}.

Se não esperava este convite, pode ignorar este email.

Obrigado,<br>
{{ config('app.name') }}
</x-mail::message>
