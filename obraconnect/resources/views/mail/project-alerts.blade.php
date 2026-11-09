<x-mail::message>
# {{ count($alerts) === 1 ? 'Alerta das obras' : 'Alertas das obras' }}

O ObraConnect encontrou {{ count($alerts) === 1 ? 'esta situação' : 'estas situações' }} na empresa **{{ $companyName }}**:

@foreach ($alerts as $alert)
**{{ $alert['level'] === 'danger' ? 'Grave' : 'Aviso' }} · {{ \App\Mail\ProjectNoticeMail::escape($alert['title']) }}**<br>
@if ($alert['project'])
{{ \App\Mail\ProjectNoticeMail::escape($alert['project']) }}<br>
@endif
{{ \App\Mail\ProjectNoticeMail::escape($alert['detail']) }}<br>
[Ver na aplicação]({{ $alert['url'] }})

@endforeach

Cada alerta só é enviado uma vez e fecha-se sozinho quando a situação deixa de se verificar. O que gera alertas, e a partir de quando, está em **Empresa → Alertas automáticos**.

{{ config('app.name') }}
</x-mail::message>
