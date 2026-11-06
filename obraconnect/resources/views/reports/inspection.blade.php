<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8">
<title>Inspeção n.º {{ $inspection->number }} — {{ $project['code'] }}</title>
<style>
    @page { margin: 22mm 15mm 18mm 15mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1e293b; line-height: 1.35; }
    h1 { font-size: 15pt; margin: 0 0 2mm 0; }
    h2 { font-size: 10.5pt; margin: 6mm 0 2mm 0; padding-bottom: 1mm; border-bottom: 0.4mm solid #cbd5e1; page-break-after: avoid; }
    .items tr, .events tr, .box { page-break-inside: avoid; }
    .muted { color: #64748b; }
    .small { font-size: 7.5pt; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 7pt; word-wrap: break-word; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; text-align: left; }
    .header td { padding: 0; }
    .kv td { padding: 1mm 2mm 1mm 0; }
    .kv td.k { width: 38mm; color: #64748b; }
    .items { margin-top: 1mm; }
    .items > tbody > tr > td { border-top: 0.3mm solid #e2e8f0; padding: 2mm 1.5mm; }
    .num { width: 7mm; color: #64748b; font-weight: bold; }
    .res { width: 26mm; }
    .badge { display: inline-block; padding: 0.5mm 1.8mm; border-radius: 1mm; font-size: 7.5pt; font-weight: bold; }
    .ok { background: #dcfce7; color: #166534; }
    .nok { background: #fee2e2; color: #991b1b; }
    .na { background: #f1f5f9; color: #475569; }
    .warn { color: #b91c1c; font-weight: bold; }
    .req { font-size: 7.5pt; color: #0369a1; }
    .photos img { width: 28mm; height: 21mm; margin: 1mm 1mm 0 0; border: 0.2mm solid #cbd5e1; }
    .box { border: 0.4mm solid #cbd5e1; padding: 2.5mm 3mm; margin-top: 2mm; }
    .box.green { border-color: #86efac; background: #f0fdf4; }
    .box.amber { border-color: #fcd34d; background: #fffbeb; }
    .box.red { border-color: #fca5a5; background: #fef2f2; }
    .provisional { color: #b45309; font-weight: bold; }
    .events td { padding: 1.2mm 1.5mm; border-top: 0.2mm solid #e2e8f0; }
    .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7pt; color: #64748b; }
</style>
</head>
<body>

<div class="footer">
    {{ $company['name'] }} · Inspeção n.º {{ $inspection->number }} · {{ $project['code'] }} · gerado a {{ $generated['at'] }} por {{ $generated['by'] }}
</div>

<table class="header">
    <tr>
        <td>
            @if ($company['logo'])
                <img src="{{ $company['logo'] }}" style="max-height: 14mm; max-width: 45mm;" alt="">
            @endif
            <div style="font-weight: bold; margin-top: 1mm;">{{ $company['name'] }}</div>
            <div class="small muted">
                @if ($company['nif'])NIF {{ $company['nif'] }}@endif
                @if ($company['nif'] && $company['address']) · @endif
                {{ $company['address'] }}
            </div>
        </td>
        <td style="text-align: right;">
            <div class="small muted">RELATÓRIO DE INSPEÇÃO</div>
            <div style="font-size: 13pt; font-weight: bold;">N.º {{ $inspection->number }}</div>
            <div>{{ $project['code'] }} · {{ $project['name'] }}</div>
            @if ($project['address'])<div class="small muted">{{ $project['address'] }}</div>@endif
        </td>
    </tr>
</table>

<h1 style="margin-top: 6mm;">{{ $inspection->title }}</h1>
<div>
    Estado: <strong>{{ $inspection->status->label() }}</strong>
    @if (! $closed)
        · <span class="provisional">Documento provisório: a inspeção ainda não está decidida.</span>
    @endif
</div>

<table class="kv" style="margin-top: 3mm;">
    @if ($place)<tr><td class="k">Onde</td><td>{{ $place }}</td></tr>@endif
    @if ($inspection->specialty)<tr><td class="k">Especialidade</td><td>{{ $inspection->specialty->code }} · {{ $inspection->specialty->name }}</td></tr>@endif
    @if ($inspection->template)<tr><td class="k">Lista de verificação</td><td>{{ $inspection->template->name }}, versão {{ $inspection->template_version }}</td></tr>@endif
    @if ($inspection->reinspectionOf)<tr><td class="k">Reinspeção da</td><td>inspeção n.º {{ $inspection->reinspectionOf->number }}</td></tr>@endif
    <tr><td class="k">Tipo</td><td>{{ $inspection->remote ? 'Remota (por fotografias)' : 'Presencial' }}@if ($inspection->hold_point) · ponto de paragem @endif @if ($inspection->approvals_required > 1) · {{ $inspection->approvals_required }} aprovações necessárias @endif</td></tr>
    @foreach ($dates as $label => $value)
        <tr><td class="k">{{ $label }}</td><td>{{ $value }}</td></tr>
    @endforeach
</table>

@if ($inspection->description)
    <p style="white-space: pre-line;">{{ $inspection->description }}</p>
@endif

@if ($inspection->decided_at)
    @php($tone = match ($inspection->status->value) { 'approved' => 'green', 'rejected' => 'red', default => 'amber' })
    <div class="box {{ $tone }}">
        <strong>{{ $inspection->status->label() }}</strong> por {{ $inspection->decidedBy?->name ?? '—' }} a {{ $inspection->decided_at->copy()->timezone('Europe/Lisbon')->format('d/m/Y H:i') }}
        @if ($inspection->decision_note)
            <div style="white-space: pre-line; margin-top: 1mm;">{{ $inspection->decision_note }}</div>
        @endif
    </div>
@endif

@if (count($approvals) > 0 && $inspection->approvals_required > 1)
    <h2>Aprovações ({{ count($approvals) }} de {{ $inspection->approvals_required }})</h2>
    <table class="kv">
        @foreach ($approvals as $a)
            <tr>
                <td class="k">{{ $a['when'] }}</td>
                <td><strong>{{ $a['who'] }}</strong> — {{ $a['reservations'] ? 'aprovou com reservas' : 'aprovou' }}@if ($a['reason']): {{ $a['reason'] }}@endif</td>
            </tr>
        @endforeach
    </table>
@endif

<h2>Evidências: índice {{ $evidence['score'] ?? '—' }}{{ $evidence['score'] !== null ? '%' : '' }}</h2>
<table class="kv">
    @foreach ($evidence['criteria'] as $c)
        <tr>
            <td class="k">{{ $c['met'] }}/{{ $c['total'] }}</td>
            <td>
                {{ $c['label'] }} <span class="muted">({{ $c['required'] ? 'obrigatório' : 'recomendado' }})</span>
                @if (count($c['missing']))<span class="warn"> — {{ count($c['missing']) === 1 ? 'ponto' : 'pontos' }} {{ implode(', ', $c['missing']) }}</span>@endif
            </td>
        </tr>
    @endforeach
</table>
<p class="small muted">O índice mede se as evidências exigidas e recomendadas estão presentes. Não substitui a decisão de quem aprova.</p>

<h2>Pontos verificados</h2>
<table class="items">
    <tbody>
    @foreach ($items as $item)
        <tr>
            <td class="num">{{ $item['number'] }}.</td>
            <td>
                <strong>{{ $item['text'] }}</strong>
                @if ($item['guidance'])<div class="small muted">{{ $item['guidance'] }}</div>@endif
                @if (count($item['requirements']))<div class="req">Exige: {{ implode(' · ', $item['requirements']) }}</div>@endif
                @if ($item['measure'])
                    <div>Medido: <strong>{{ $item['measure'] }}</strong>@if ($item['out_of_tolerance']) <span class="warn">fora dos limites</span>@endif</div>
                @endif
                @if ($item['notes'])<div style="white-space: pre-line;">{{ $item['notes'] }}</div>@endif
                @if (count($item['issues']))<div class="warn">Não conformidade: {{ implode(', ', $item['issues']) }}</div>@endif
                @if (count($item['photos']))
                    <div class="photos">
                        @foreach ($item['photos'] as $src)<img src="{{ $src }}" alt="">@endforeach
                    </div>
                @endif
                @if ($item['photos_total'] > count($item['photos']))
                    <div class="small muted">{{ $item['photos_total'] }} fotografias no total (originais guardados na aplicação).</div>
                @endif
                @if ($item['answered'])<div class="small muted">{{ $item['answered'] }}</div>@endif
            </td>
            <td class="res"><span class="badge {{ $item['result_class'] }}">{{ $item['result_label'] }}</span></td>
        </tr>
    @endforeach
    </tbody>
</table>

<h2>Histórico</h2>
<table class="events">
    @foreach ($events as $e)
        <tr>
            <td style="width: 28mm;" class="small">{{ $e['when'] }}</td>
            <td>
                <strong>{{ $e['label'] }}</strong> — {{ $e['who'] }}
                @if ($e['reason'])<div style="white-space: pre-line;">“{{ $e['reason'] }}”</div>@endif
                <div class="mono muted">{{ $e['hash'] }}</div>
            </td>
        </tr>
    @endforeach
</table>

<div class="box {{ $chain['valid'] ? 'green' : 'red' }}" style="margin-top: 4mm;">
    @if ($chain['valid'])
        <strong>Cadeia íntegra.</strong> Cada passo do histórico está selado com o SHA-256 do anterior; nenhum foi alterado depois de registado.
    @else
        <strong>A cadeia não bate a partir do passo {{ $chain['broken_at'] }}:</strong> o histórico foi alterado fora da aplicação.
    @endif
    @if ($chain['last_hash'])
        <div class="small" style="margin-top: 1mm;">Código de verificação (hash do último passo):</div>
        <div class="mono">{{ $chain['last_hash'] }}</div>
    @endif
</div>

</body>
</html>
