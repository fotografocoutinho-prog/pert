<!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<title>ObraConnect — apresentação</title>
<style>
    @page { margin: 16mm 15mm 16mm 15mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.6pt; color: #1e293b; line-height: 1.32; }
    h1 { font-size: 22pt; margin: 0 0 6pt; color: #0f172a; line-height: 1.15; }
    h2 { font-size: 12.5pt; margin: 12pt 0 5pt; color: #9a3412; page-break-after: avoid; }
    h3 { font-size: 10pt; margin: 0 0 3pt; color: #0f172a; }
    p { margin: 0 0 6pt; }
    .brand { font-size: 15pt; font-weight: bold; color: #0f172a; }
    .brand span { color: #c2410c; }
    .lead { font-size: 10.5pt; color: #334155; }
    .muted { color: #64748b; }
    .band { background: #fff7ed; border: 1px solid #fed7aa; padding: 10pt 12pt; margin: 10pt 0; }
    table { width: 100%; border-collapse: collapse; }
    .cards td { width: 50%; vertical-align: top; padding: 2pt 6pt 4pt 0; }
    tr { page-break-inside: avoid; }
    .card { border: 1px solid #e2e8f0; padding: 6pt 8pt; }
    ul { margin: 2pt 0 0 0; padding-left: 11pt; }
    li { margin-bottom: 1pt; }
    .plans th, .plans td { border: 1px solid #e2e8f0; padding: 5pt 6pt; text-align: left; vertical-align: top; }
    .plans th { background: #f8fafc; }
    .price { font-size: 12pt; font-weight: bold; }
    .hl { background: #fff7ed; }
    .page-break { page-break-before: always; }
    .foot { margin-top: 14pt; border-top: 1px solid #e2e8f0; padding-top: 8pt; }
</style>
</head>
<body>
    <p class="brand">Obra<span>Connect</span></p>
    <h1>{{ $tagline }}</h1>
    <p class="lead">{{ $summary }}</p>
    <div class="band">
        <strong>{{ $trialDays }} dias grátis, sem cartão</strong> — crie a empresa em {{ $site }} e comece com a próxima obra.
        @if ($contactEmail) Para uma demonstração ou uma proposta: {{ $contactEmail }}. @endif
    </div>

    <h2>O que muda no dia a dia</h2>
    <table class="cards">
        @foreach (array_chunk($benefits, 2) as $row)
            <tr>
                @foreach ($row as $b)
                    <td><div class="card"><h3>{{ $b['title'] }}</h3><p class="muted">{{ $b['text'] }}</p></div></td>
                @endforeach
            </tr>
        @endforeach
    </table>

    <h2>Para cada pessoa da equipa</h2>
    <table class="cards">
        @foreach (array_chunk($roles, 2) as $row)
            <tr>
                @foreach ($row as $r)
                    <td><div class="card"><h3>{{ $r['role'] }}</h3><ul>@foreach ($r['items'] as $i)<li>{{ $i }}</li>@endforeach</ul></div></td>
                @endforeach
            </tr>
        @endforeach
    </table>

    <h2>Tudo o que inclui</h2>
    <table class="cards">
        @foreach (array_chunk($modules, 2) as $row)
            <tr>
                @foreach ($row as $m)
                    <td><div class="card"><h3>{{ $m['title'] }}</h3><ul>@foreach ($m['items'] as $i)<li>{{ $i }}</li>@endforeach</ul></div></td>
                @endforeach
            </tr>
        @endforeach
    </table>

    <h2>Os dados da empresa, protegidos</h2>
    <ul>@foreach ($security as $s)<li>{{ $s }}</li>@endforeach</ul>

    <div class="page-break"></div>
    <h2 style="margin-top: 0">Planos e preços</h2>
    <p class="muted">{{ $trialDays }} dias grátis{{ $trialPlan ? ' no plano '.$trialPlan : '' }}, sem cartão; durante esse tempo pode mudar de plano. Faturação mensal ou anual. Valores sem IVA.</p>
    <table class="plans">
        <tr>
            <th></th>
            @foreach ($plans as $p)<th class="{{ $p['highlight'] ? 'hl' : '' }}">{{ $p['name'] }}</th>@endforeach
        </tr>
        <tr>
            <td>Preço</td>
            @foreach ($plans as $p)
                <td class="{{ $p['highlight'] ? 'hl' : '' }}">
                    @if ($p['contact'])<span class="price">Sob consulta</span>
                    @else<span class="price">{{ $money($p['monthly_cents']) }}</span>/mês
                        @if ($p['yearly_cents'] !== null)<br><span class="muted">ou {{ $money($p['yearly_cents']) }}/ano</span>@endif
                    @endif
                </td>
            @endforeach
        </tr>
        @foreach ([0, 1, 2] as $li)
            <tr>
                <td>{{ $plans->first()['limits'][$li]['label'] ?? '' }}</td>
                @foreach ($plans as $p)<td class="{{ $p['highlight'] ? 'hl' : '' }}">{{ $p['limits'][$li]['value'] }}</td>@endforeach
            </tr>
        @endforeach
        <tr>
            <td>Inclui</td>
            @foreach ($plans as $p)
                <td class="{{ $p['highlight'] ? 'hl' : '' }}"><ul>@foreach ($p['features'] as $f)<li>{{ $f }}</li>@endforeach</ul></td>
            @endforeach
        </tr>
    </table>
    <p class="muted" style="margin-top: 6pt">Os subempreiteiros com acesso ao portal não contam como utilizadores. A mudança de plano faz-se na própria aplicação.</p>

    <h2>Começa em três passos</h2>
    <ol>@foreach ($steps as $s)<li><strong>{{ $s['title'] }}.</strong> {{ $s['text'] }}</li>@endforeach</ol>

    <h2>Perguntas frequentes</h2>
    @foreach ($faq as $f)
        <h3>{{ $f['q'] }}</h3>
        <p class="muted">{{ $f['a'] }}</p>
    @endforeach

    <p class="foot muted">ObraConnect · {{ $site }}@if ($contactEmail) · {{ $contactEmail }}@endif · Apresentação gerada a {{ now('Europe/Lisbon')->format('d/m/Y') }}; os preços são os em vigor nessa data.</p>
</body>
</html>
