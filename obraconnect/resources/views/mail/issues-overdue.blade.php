<x-mail::message>
# Prazo ultrapassado

@if (count($issues) === 1)
Esta ocorrência da empresa **{{ $companyName }}** passou o prazo sem estar resolvida.
@else
Estas ocorrências da empresa **{{ $companyName }}** passaram o prazo sem estarem resolvidas.
@endif

@foreach ($issues as $issue)
**{{ $issue['reference'] }} · {{ \App\Mail\ProjectNoticeMail::escape($issue['title']) }}**<br>
{{ $issue['project'] }}<br>
Prazo: {{ $issue['due_on'] }} · Responsável: {{ $issue['mine'] ? 'você' : ($issue['assignee'] ?? 'ninguém atribuído') }}<br>
[Abrir {{ $issue['reference'] }}]({{ $issue['url'] }})

@endforeach

Recebe este aviso por ser o responsável ou o diretor de obra. Para não voltar a recebê-lo, resolva a ocorrência ou combine um novo prazo.

{{ config('app.name') }}
</x-mail::message>
