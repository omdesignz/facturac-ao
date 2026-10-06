<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="utf-8">
    <title>{{ $document['document_no'] }}</title>
    @include('documents.pdf.v2._styles')
</head>
<body>

@include('documents.pdf.v2._header')

@include('documents.pdf.v2._parties')

@php
    $facts = [['Data de emissão', \Illuminate\Support\Carbon::parse($document['document_date'])->format('d/m/Y')]];

    if ($document['issued_at']) {
        $facts[] = ['Hora', \Illuminate\Support\Carbon::parse($document['issued_at'])->format('H\\hi')];
    }

    $facts[] = ['Moeda', $document['currency_code']];

    if ($document['payment_method_label']) {
        $facts[] = ['Meio de pagamento', $document['payment_method_label']];
    }
@endphp
@include('documents.pdf.v2._facts', ['facts' => $facts])

{{-- A receipt has no goods on it: the rows are the documents it pays off. --}}
<div class="label" style="margin-top: 16pt; margin-bottom: 1pt;">Documento de cobrança</div>
<table class="items">
    <thead>
        <tr>
            <th rowspan="2" style="width: 18%; text-align: left;">Documento</th>
            <th rowspan="2" style="width: 18%; text-align: left;">Tipo</th>
            <th rowspan="2" style="width: 14%; text-align: right;">Sem imposto e desconto</th>
            <th colspan="3" class="group" style="width: 26%; text-align: center;">Valor de imposto</th>
            <th rowspan="2" style="width: 11%; text-align: right;">Descontos</th>
            <th rowspan="2" style="width: 13%; text-align: right;">Total</th>
        </tr>
        <tr>
            <th style="width: 8%; text-align: right;">IEC</th>
            <th style="width: 10%; text-align: right;">IVA</th>
            <th style="width: 8%; text-align: right;">I. Selo</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($settlements as $row)
            <tr>
                <td class="medium nowrap">{{ $row['document_no'] }}</td>
                <td class="muted">{{ $row['document_type_label'] }}</td>
                <td class="figure">{{ $money($row['net_minor']) }}</td>
                <td class="figure muted">{{ $money($row['taxes_by_type']['IEC']) }}</td>
                <td class="figure">{{ $money($row['taxes_by_type']['IVA']) }}</td>
                <td class="figure muted">{{ $money($row['taxes_by_type']['IS']) }}</td>
                <td class="figure muted">{{ $money($row['discount_minor']) }}</td>
                <td class="figure bold">{{ $money($row['total_minor']) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="8" class="center muted" style="padding: 10pt 4pt;">
                    Este recibo não liquida documentos específicos.
                </td>
            </tr>
        @endforelse
    </tbody>
</table>

{{-- Totals and verification stay together on one page. --}}
<table class="layout" style="margin-top: 18pt; page-break-inside: avoid;">
    <tr>
        <td style="width: 54%; padding-right: 22pt;">
            <div class="label" style="margin-bottom: 3pt;">Valores totais</div>
            <table class="summary">
                <tr>
                    <td class="muted">Divisas</td>
                    <td class="right">{{ $document['currency_code'] }}</td>
                </tr>
                <tr>
                    <td class="muted">Taxa de câmbio</td>
                    <td class="right nowrap">{{ $document['exchange_rate'] }}</td>
                </tr>
                <tr>
                    <td class="muted">Valor em divisas</td>
                    <td class="right nowrap">{{ $money($document['totals']['gross_minor']) }}</td>
                </tr>
                @if ($document['is_foreign_currency'])
                    <tr>
                        <td class="muted">Valor em {{ $document['base_currency_code'] }}</td>
                        <td class="right nowrap bold">{{ $money($document['totals']['gross_base_minor']) }}</td>
                    </tr>
                @endif
            </table>

            @if ($document['notes'])
                <div class="label">Observações</div>
                <div style="font-size: 7.8pt; margin-top: 2pt;">{{ $document['notes'] }}</div>
            @endif
        </td>

        <td style="width: 46%;">
            <table class="totals">
                <tr>
                    <td class="muted">Totais sem impostos</td>
                    <td class="amount">{{ $money($document['totals']['net_minor']) }}</td>
                </tr>
                <tr>
                    <td class="muted">Valor de descontos</td>
                    <td class="amount">{{ $money($document['discount_total_minor']) }}</td>
                </tr>
                <tr>
                    <td class="muted">Valor de impostos</td>
                    <td class="amount">{{ $money($document['totals']['tax_minor']) }}</td>
                </tr>
                <tr class="grand">
                    <td style="vertical-align: bottom;"><span class="grand-label">Valor total a pagar</span></td>
                    <td class="amount" style="vertical-align: bottom;">
                        <span class="grand-amount">{{ $money($document['totals']['gross_minor']) }}</span>
                        <span class="muted" style="font-size: 8pt;">{{ $document['currency_code'] }}</span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    {{-- In the same unbreakable block, so the page with the total is the
         page with the QR that verifies it. --}}
    <tr>
        <td colspan="2" style="padding-top: 22pt;">
            @include('documents.pdf.v2._authenticity', ['qrImage' => $document['authenticity']['qr_data_uri']])
        </td>
    </tr>
</table>

</body>
</html>
