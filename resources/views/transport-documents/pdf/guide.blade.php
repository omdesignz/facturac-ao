<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="utf-8">
    <title>{{ $document['document_no'] }}</title>
    @include('documents.pdf._styles')
    <style>
        .route-box { border: 0.6pt solid #777; padding: 6pt 8pt; vertical-align: top; }
    </style>
</head>
<body>

<htmlpageheader name="documentHeader">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="width: 48%; vertical-align: middle; padding-right: 8pt;">
                @if ($logo)
                    <img src="{{ $logo }}" style="max-width: {{ config('fiscal.print.logo_max_width_mm') }}mm; max-height: {{ config('fiscal.print.logo_max_height_mm') }}mm;" alt="">
                @else
                    <div class="bold" style="font-size: 12pt;">{{ $document['company']['trade_name'] ?? $document['company']['legal_name'] }}</div>
                @endif
            </td>
            <td style="width: 52%; vertical-align: middle; background: #e6e6e6; padding: 6pt 10pt;">
                <div style="text-align: right; font-size: 19pt; font-weight: bold;">{{ mb_strtoupper($document['document_type_label']) }}</div>
                <div class="right bold">{{ $document['document_no'] }}</div>
            </td>
        </tr>
    </table>
</htmlpageheader>

<htmlpagefooter name="documentFooter">
    <table style="width: 100%; border-collapse: collapse; font-size: 7pt;" class="muted"><tr><td>{{ $document['document_no'] }}</td><td class="right">Pág. {PAGENO}/{nbpg}</td></tr></table>
</htmlpagefooter>


<table style="width: 100%; border-collapse: collapse; margin-top: 4pt;">
    <tr>
        <td style="width: 50%; vertical-align: top; padding-right: 8pt;">
            <table class="section-title"><tr><td>EMISSOR</td></tr></table>
            <table class="party-box" style="border-top: 0;">
                <tr>
                    <td>
                        <span class="bold">{{ $document['company']['legal_name'] }}</span><br>
                        NIF {{ $document['company']['tax_identification_number'] }}<br>
                        {{ $document['establishment']['name'] }} · {{ $document['establishment']['code'] }}<br>
                        {{ $document['company']['address'] }}, {{ $document['company']['city'] }} · {{ $document['company']['province'] }}
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 50%; vertical-align: top; padding-left: 8pt;">
            <table class="section-title"><tr><td>{{ mb_strtoupper($document['party_label']) }}</td></tr></table>
            <table class="party-box" style="border-top: 0;">
                <tr>
                    <td>
                        <span class="bold">{{ $document['recipient']['name'] }}</span><br>
                        NIF {{ $document['recipient']['tax_identification_number'] }}<br>
                        {{ $document['recipient']['address'] }}<br>
                        {{ $document['recipient']['city'] }}@if($document['recipient']['province']), {{ $document['recipient']['province'] }}@endif · {{ $document['recipient']['country_code'] }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table style="width: 100%; border-collapse: collapse; margin-top: 8pt;">
    <tr>
        <td class="route-box" style="width: 50%; padding-right: 8pt;"><div class="bold">ORIGEM / CARGA</div><div>{{ $document['origin']['address'] }}</div><div>{{ $document['origin']['city'] }}@if($document['origin']['province']), {{ $document['origin']['province'] }}@endif · {{ $document['origin']['country_code'] }}</div></td>
        <td class="route-box" style="width: 50%; padding-left: 8pt;"><div class="bold">DESTINO / DESCARGA</div><div>{{ $document['destination']['address'] }}</div><div>{{ $document['destination']['city'] }}@if($document['destination']['province']), {{ $document['destination']['province'] }}@endif · {{ $document['destination']['country_code'] }}</div></td>
    </tr>
</table>

<table class="grid" style="margin-top: 8pt;">
    <tr>
        <th>Data</th><td>{{ \Illuminate\Support\Carbon::parse($document['movement_date'])->format('d/m/Y') }}</td>
        <th>Início</th><td>{{ \Illuminate\Support\Carbon::parse($document['movement_start_at'])->format('d/m/Y H:i') }}</td>
        <th>Fim previsto</th><td>{{ $document['movement_end_at'] ? \Illuminate\Support\Carbon::parse($document['movement_end_at'])->format('d/m/Y H:i') : '—' }}</td>
    </tr>
    <tr>
        <th>Transportador</th><td>{{ $document['transporter']['name'] ?? '—' }}</td>
        <th>NIF / matrícula</th><td>{{ $document['transporter']['tax_identification_number'] ?? '—' }} / {{ $document['transporter']['vehicle_registration'] ?? '—' }}</td>
        <th>Peso / volumes</th><td>{{ $document['gross_weight_kg'] ? $number($document['gross_weight_kg']).' kg' : '—' }} / {{ $document['package_count'] ?? '—' }}</td>
    </tr>
</table>

<table class="grid" style="margin-top: 9pt;">
    <thead><tr><th style="width: 6%;">#</th><th style="width: 15%;">Código</th><th>Mercadoria</th><th style="width: 17%;">Quantidade</th><th style="width: 16%;">Preço unitário</th><th style="width: 16%;">Valor</th></tr></thead>
    <tbody>
        @foreach ($document['lines'] as $index => $line)
            <tr @class(['alt' => $index % 2 === 1])><td class="center">{{ $line['line_number'] }}</td><td>{{ $line['product_code'] }}</td><td>{{ $line['product_description'] }}</td><td class="right nowrap">{{ $number($line['quantity']) }} {{ $line['unit_of_measure'] }}</td><td class="right nowrap">{{ $number($line['unit_price'], 2) }}</td><td class="right nowrap bold">{{ $money($line['net_amount_minor']) }}</td></tr>
        @endforeach
    </tbody>
</table>

<table style="width: 100%; border-collapse: collapse; margin-top: 10pt;"><tr><td style="width: 55%; vertical-align: top;" class="muted">{{ $document['notes'] }}</td><td style="width: 45%; vertical-align: top;"><table class="totals"><tr class="grand"><td class="label bold">Valor da mercadoria</td><td class="right nowrap">{{ $money($document['gross_total_minor']) }} {{ $document['currency_code'] }}</td></tr></table></td></tr></table>

@if ($document['cancellation_reason'])
    <div class="notice" style="margin-top: 12pt; color: #991b1b;">ANULADO · {{ $document['cancellation_reason'] }}</div>
@endif

<div style="margin-top: 15pt; border-top: 0.5pt solid #aaa; padding-top: 6pt; font-size: 7pt;" class="muted">
    @if ($document['authenticity']['software_validation_number'])
        <div>Processado por programa validado n.º {{ $document['authenticity']['software_validation_number'] }}</div>
    @endif
    @if ($document['authenticity']['digest'])
        <div>Integridade {{ $document['authenticity']['digest'] }} · {{ $document['authenticity']['hash_control'] }}</div>
    @endif
    <div>Documento de acompanhamento de mercadorias · SAF-T (AO) MovementOfGoods</div>
</div>

</body>
</html>
