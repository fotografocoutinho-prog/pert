<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8">
<title>Pré-fatura {{ $draft->code() ?? 'rascunho' }}</title>
<style>
    @page { margin: 20mm 15mm 18mm 15mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1e293b; line-height: 1.35; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; text-align: left; }
    .muted { color: #64748b; }
    .small { font-size: 7.5pt; }
    .right { text-align: right; }
    .warning { border: 0.6mm solid #b91c1c; color: #991b1b; background: #fef2f2; padding: 2.5mm 3mm; margin: 4mm 0; font-weight: bold; text-align: center; font-size: 10pt; }
    .box { border: 0.3mm solid #cbd5e1; padding: 2.5mm 3mm; }
    .lines { margin-top: 5mm; }
    .lines th { font-size: 7.5pt; color: #64748b; border-bottom: 0.4mm solid #94a3b8; padding: 1.5mm 1.2mm; }
    .lines td { border-bottom: 0.2mm solid #e2e8f0; padding: 1.5mm 1.2mm; }
    .lines tr { page-break-inside: avoid; }
    .totals td { padding: 1mm 1.2mm; }
    .totals .grand td { font-weight: bold; font-size: 11pt; border-top: 0.4mm solid #1e293b; padding-top: 2mm; }
    .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7pt; color: #64748b; }
</style>
</head>
<body>

<div class="footer">{{ $company['name'] }} · Pré-fatura {{ $draft->code() ?? 'em rascunho' }} · documento interno sem valor fiscal</div>

<table>
    <tr>
        <td style="width: 55%;">
            @if ($company['logo'])
                <img src="{{ $company['logo'] }}" style="max-height: 14mm; max-width: 45mm;" alt="">
            @endif
            <div style="font-weight: bold; margin-top: 1mm;">{{ $company['name'] }}</div>
            <div class="small muted">
                @if ($company['nif'])NIF {{ $company['nif'] }}<br>@endif
                @if ($company['address']){{ $company['address'] }}<br>@endif
                {{ collect([$company['phone'], $company['email']])->filter()->implode(' · ') }}
            </div>
        </td>
        <td class="right">
            <div style="font-size: 15pt; font-weight: bold;">Pré-fatura</div>
            <div style="font-size: 11pt;">{{ $draft->code() ?? 'Rascunho' }}</div>
            <div class="small muted">
                Data: {{ $draft->issue_date?->format('d/m/Y') ?? '—' }}
                @if ($draft->due_date)<br>Vencimento: {{ $draft->due_date->format('d/m/Y') }}@endif
                <br>Estado: {{ $draft->status->label() }}
            </div>
        </td>
    </tr>
</table>

<div class="warning">
    DOCUMENTO INTERNO SEM VALOR FISCAL — NÃO SERVE DE FATURA<br>
    <span style="font-weight: normal; font-size: 8pt;">A fatura é emitida no programa de faturação certificado.</span>
</div>

<table>
    <tr>
        <td style="width: 55%; padding-right: 4mm;">
            <div class="box">
                <div class="small muted">Cliente</div>
                <div style="font-weight: bold;">{{ $draft->client_name }}</div>
                @if ($draft->client_nif)<div>NIF {{ $draft->client_nif }}</div>@endif
                <div class="small">
                    {{ $draft->client_address }}
                    @if ($draft->client_postal_code || $draft->client_city)<br>{{ trim($draft->client_postal_code.' '.$draft->client_city) }}@endif
                    @if ($draft->client_country && $draft->client_country !== 'PT')<br>{{ $draft->client_country }}@endif
                </div>
            </div>
        </td>
        <td>
            <div class="box">
                @if ($draft->project)<div class="small muted">Obra</div><div>{{ $draft->project->code }} · {{ $draft->project->name }}</div>@endif
                @if ($draft->reference)<div class="small muted" style="margin-top: 1mm;">Referência</div><div>{{ $draft->reference }}</div>@endif
                @if ($draft->official_number)
                    <div class="small muted" style="margin-top: 1mm;">Fatura oficial</div>
                    <div style="font-weight: bold;">{{ $draft->official_number }} de {{ $draft->official_date?->format('d/m/Y') }}</div>
                @endif
            </div>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th style="width: 12mm;">Código</th>
            <th>Descrição</th>
            <th style="width: 10mm;">Un.</th>
            <th class="right" style="width: 18mm;">Quant.</th>
            <th class="right" style="width: 20mm;">Preço</th>
            <th class="right" style="width: 11mm;">Desc.</th>
            <th class="right" style="width: 11mm;">IVA</th>
            <th class="right" style="width: 23mm;">Valor</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($draft->lines as $line)
            <tr>
                <td class="small">{{ $line->code }}</td>
                <td>{{ $line->description }}</td>
                <td>{{ $line->unit }}</td>
                <td class="right">{{ $number($line->quantity) }}</td>
                <td class="right">{{ $money($line->unit_price_cents) }}</td>
                <td class="right">{{ (float) $line->discount_pct ? $number($line->discount_pct, 2).'%' : '' }}</td>
                <td class="right">{{ $number($line->vat_rate, 2) }}%</td>
                <td class="right">{{ $money($totals['lines'][$line->id]['net_cents']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table style="margin-top: 4mm;">
    <tr>
        <td style="width: 55%; padding-right: 4mm;">
            <table class="small">
                <tr><td class="muted">Taxa</td><td class="right muted">Incidência</td><td class="right muted">IVA</td></tr>
                @foreach ($totals['by_rate'] as $rate)
                    <tr><td>{{ $number($rate['rate'], 2) }}%</td><td class="right">{{ $money($rate['net_cents']) }}</td><td class="right">{{ $money($rate['vat_cents']) }}</td></tr>
                @endforeach
            </table>
            @if ($draft->vat_exemption_reason)
                <p class="small" style="margin-top: 2mm;">
                    {{ $draft->vat_exemption_reason }}@if ($draft->vat_exemption_code) ({{ $draft->vat_exemption_code }})@endif
                </p>
            @endif
            @if ($draft->notes)<p class="small" style="margin-top: 2mm;">{!! nl2br(e($draft->notes)) !!}</p>@endif
        </td>
        <td>
            <table class="totals">
                <tr><td>Total sem IVA</td><td class="right">{{ $money($totals['net_cents']) }}</td></tr>
                <tr><td>IVA</td><td class="right">{{ $money($totals['vat_cents']) }}</td></tr>
                <tr class="grand"><td>Total</td><td class="right">{{ $money($totals['total_cents']) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>
