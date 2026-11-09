<x-mail::message>
# Saúde do ObraConnect

@if (count($failing))
**Passou a falhar:**

@foreach ($failing as $c)
- **{{ \App\Mail\ProjectNoticeMail::escape($c['label']) }}** — {{ \App\Mail\ProjectNoticeMail::escape($c['detail']) }}
@endforeach

@endif
@if (count($still))
**Continua a falhar:**

@foreach ($still as $c)
- **{{ \App\Mail\ProjectNoticeMail::escape($c['label']) }}** — {{ \App\Mail\ProjectNoticeMail::escape($c['detail']) }}
@endforeach

@endif
@if (count($recovered))
**Voltou ao normal:**

@foreach ($recovered as $c)
- {{ \App\Mail\ProjectNoticeMail::escape($c['label']) }}
@endforeach

@endif
<x-mail::button :url="url('/plataforma/sistema')">
Ver a saúde do sistema
</x-mail::button>

Este aviso vai para os super administradores da plataforma (e para MONITORING_EMAILS). Repete-se uma vez por dia enquanto o problema durar.

{{ config('app.name') }}
</x-mail::message>
