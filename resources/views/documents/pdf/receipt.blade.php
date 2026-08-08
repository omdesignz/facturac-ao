<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="utf-8">
    <title>{{ $document['document_no'] }}</title>
    @include('documents.pdf._styles')
</head>
<body>

{{--
    The receipt puts its title on the left and the mark on the right, which is
    the other way round from the invoice. Both repeat on every sheet.
--}}
<htmlpageheader name="documentHeader">
    <table style="width: 100%; border-collapse: collapse; padding-bottom: 6pt;">
        <tr>
            <td style="width: 60%; vertical-align: middle;">
                <div style="font-size: 24pt; font-weight: bold; letter-spacing: 1pt;">
                    {{ mb_strtoupper($document['document_type_label']) }}
                </div>
                <div>
                    Data de emissão:
                    {{ \Illuminate\Support\Carbon::parse($document['document_date'])->format('d/m/Y') }}
                    @if ($document['issued_at'])
                        - {{ \Illuminate\Support\Carbon::parse($document['issued_at'])->format('H\\hi') }}
                    @endif
                </div>
            </td>
            <td style="width: 40%; vertical-align: middle; text-align: right;">
                @if ($logo)
                    <img src="{{ $logo }}"
                         style="max-width: {{ config('fiscal.print.logo_max_width_mm') }}mm; max-height: {{ config('fiscal.print.logo_max_height_mm') }}mm;"
                         alt="">
                @else
                    <div class="bold" style="font-size: 12pt;">
                        {{ $document['company']['trade_name'] ?? $document['company']['legal_name'] }}
                    </div>
                @endif
            </td>
        </tr>
    </table>
</htmlpageheader>

<htmlpagefooter name="documentFooter">
    <table style="width: 100%; border-collapse: collapse; font-size: 7pt;" class="muted">
        <tr>
            <td>{{ $document['document_no'] }}</td>
            <td class="right">Pág. {PAGENO}/{nbpg}</td>
        </tr>
    </table>
</htmlpagefooter>

@include('documents.pdf._parties')

<div class="right bold" style="margin-top: 8pt;">Documento de Cobrança</div>

{{-- A receipt has no goods on it: the rows are the documents it pays off. --}}
<table class="grid" style="margin-top: 8pt;">
    <thead>
        <tr>
            <th rowspan="2" style="width: 20%;">Nº Factura ou documento relevante</th>
            <th rowspan="2" style="width: 16%;">Tipo de documento</th>
            <th rowspan="2" style="width: 16%;">Total sem imposto e desconto</th>
            <th colspan="3" style="width: 24%;">Valor de imposto</th>
            <th rowspan="2" style="width: 12%;">Valor de descontos</th>
            <th rowspan="2" style="width: 12%;">Total</th>
        </tr>
        <tr>
            <th style="width: 8%;">IEC</th>
            <th style="width: 8%;">IVA</th>
            <th style="width: 8%;">IS</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($settlements as $index => $row)
            <tr @class(['alt' => $index % 2 === 1])>
                <td class="nowrap">{{ $row['document_no'] }}</td>
                <td>{{ $row['document_type_label'] }}</td>
                <td class="right nowrap">{{ $money($row['net_minor']) }}</td>
                <td class="right nowrap">{{ $money($row['taxes_by_type']['IEC']) }}</td>
                <td class="right nowrap">{{ $money($row['taxes_by_type']['IVA']) }}</td>
                <td class="right nowrap">{{ $money($row['taxes_by_type']['IS']) }}</td>
                <td class="right nowrap">{{ $money($row['discount_minor']) }}</td>
                <td class="right nowrap bold">{{ $money($row['total_minor']) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="8" class="center muted">
                    Este recibo não liquida documentos específicos.
                </td>
            </tr>
        @endforelse
    </tbody>
</table>

<table style="width: 100%; border-collapse: collapse; margin-top: 14pt;">
    <tr>
        <td style="width: 50%; vertical-align: top; padding-right: 12pt;">
            <div class="section-title">Valores totais</div>
            <table class="totals" style="margin-top: 4pt;">
                <tr>
                    <td class="label">Divisas</td>
                    <td class="right nowrap" style="width: 50%;">{{ $document['currency_code'] }}</td>
                </tr>
                <tr>
                    <td class="label">Taxa de câmbio</td>
                    <td class="right nowrap">{{ $document['exchange_rate'] }}</td>
                </tr>
                <tr>
                    <td class="label">Valor em divisas</td>
                    <td class="right nowrap">{{ $money($document['totals']['gross_minor']) }}</td>
                </tr>
                @if ($document['is_foreign_currency'])
                    <tr>
                        <td class="label">Valor em {{ $document['base_currency_code'] }}</td>
                        <td class="right nowrap bold">
                            {{ $money($document['totals']['gross_base_minor']) }}
                        </td>
                    </tr>
                @endif
            </table>

            @if ($document['payment_method_label'])
                <div style="margin-top: 8pt;">
                    <span class="bold">Meio de pagamento:</span>
                    {{ $document['payment_method_label'] }}
                </div>
            @endif

            @if ($document['notes'])
                <div style="margin-top: 8pt; font-size: 7.5pt;" class="muted">
                    {{ $document['notes'] }}
                </div>
            @endif
        </td>

        <td style="width: 50%; vertical-align: top;">
            <div class="bold right">Valores em {{ $document['currency_code'] }}</div>
            <table class="totals" style="margin-top: 3pt;">
                <tr>
                    <td class="label">Totais sem impostos</td>
                    <td class="right nowrap" style="width: 45%;">{{ $money($document['totals']['net_minor']) }}</td>
                </tr>
                <tr>
                    <td class="label">Valor de impostos</td>
                    <td class="right nowrap">{{ $money($document['totals']['tax_minor']) }}</td>
                </tr>
                <tr>
                    <td class="label">Valor de descontos</td>
                    <td class="right nowrap">{{ $money($document['discount_total_minor']) }}</td>
                </tr>
                <tr class="grand">
                    <td class="label bold">Valor total a pagar</td>
                    <td class="right nowrap">{{ $money($document['totals']['gross_minor']) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<div style="margin-top: 18pt;">
    @include('documents.pdf._authenticity', ['qrImage' => $document['authenticity']['qr_data_uri']])
</div>

</body>
</html>
