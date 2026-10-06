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
    $issuedOn = \Illuminate\Support\Carbon::parse($document['document_date']);
    $facts = [['Data de emissão', $issuedOn->format('d/m/Y')]];

    if ($document['issued_at']) {
        $facts[] = ['Hora', \Illuminate\Support\Carbon::parse($document['issued_at'])->format('H\\hi')];
    }

    if ($document['due_date']) {
        $facts[] = ['Vencimento', \Illuminate\Support\Carbon::parse($document['due_date'])->format('d/m/Y')];
    }

    $facts[] = ['Moeda', $document['currency_code']];

    if ($document['payment_method_label']) {
        $facts[] = ['Pagamento', $document['payment_method_label']];
    }
@endphp
@include('documents.pdf.v2._facts', ['facts' => $facts])

{{-- The column titles repeat with the table across page breaks. --}}
<table class="items" style="margin-top: 16pt;">
    <thead>
        <tr>
            <th rowspan="2" style="width: 5%; text-align: left;">Tipo</th>
            <th rowspan="2" style="width: 9%; text-align: left;">Código</th>
            <th rowspan="2" style="width: 23%; text-align: left;">Descrição</th>
            <th rowspan="2" style="width: 7%; text-align: right;">Qtd.</th>
            <th rowspan="2" style="width: 9%; text-align: right;">Preço unit.</th>
            <th rowspan="2" style="width: 6%; text-align: right;">Desc.</th>
            <th rowspan="2" style="width: 9%; text-align: right;">Valor</th>
            <th colspan="3" class="group" style="width: 20%; text-align: center;">Impostos</th>
            <th rowspan="2" style="width: 12%; text-align: right;">Total</th>
        </tr>
        <tr>
            <th style="width: 6%; text-align: right;">IEC</th>
            <th style="width: 8%; text-align: right;">IVA</th>
            <th style="width: 6%; text-align: right;">I. Selo</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($document['lines'] as $line)
            <tr>
                <td class="muted">{{ $line['operation_type'] }}</td>
                <td class="muted">{{ $line['product_code'] }}</td>
                <td>
                    <span class="medium">{{ $line['product_description'] }}</span>
                    @if ($line['operation_date'])
                        <div class="line-note">
                            Operação {{ \Illuminate\Support\Carbon::parse($line['operation_date'])->format('d/m/Y') }}
                        </div>
                    @endif
                    @if ($line['tax_exemption_code'])
                        <div class="line-note">Isento · {{ $line['tax_exemption_code'] }}</div>
                    @endif
                </td>
                <td class="figure">{{ $number($line['quantity']) }} <span class="muted">{{ $line['unit_of_measure'] }}</span></td>
                <td class="figure">{{ $money($line['unit_price_minor']) }}</td>
                <td class="figure muted">{{ $percent($line['discount_rate']) }}</td>
                <td class="figure">{{ $money($line['net_amount_minor']) }}</td>
                <td class="figure muted">{{ $money($line['taxes_by_type']['IEC']) }}</td>
                <td class="figure">{{ $money($line['taxes_by_type']['IVA']) }}</td>
                <td class="figure muted">{{ $money($line['taxes_by_type']['IS']) }}</td>
                <td class="figure bold">{{ $money($line['gross_amount_minor']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

{{-- Summary and verification stay together on one page: half a summary is no summary. --}}
<table class="layout" style="margin-top: 18pt; page-break-inside: avoid;">
    <tr>
        <td style="width: 54%; padding-right: 22pt;">
            <div class="label" style="margin-bottom: 3pt;">Resumo de impostos</div>
            <table class="summary">
                <thead>
                    <tr>
                        <th style="text-align: left;">Taxa</th>
                        <th style="text-align: right;">Incidência</th>
                        <th style="text-align: right;">Imposto</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($document['tax_summary'] as $row)
                        <tr>
                            <td>
                                {{ $percent($row['rate']) }}
                                @if ($row['exemption_code'])
                                    <span class="muted">· {{ $row['exemption_code'] }}</span>
                                @endif
                            </td>
                            <td class="right nowrap">{{ $money($row['base_minor']) }}</td>
                            <td class="right nowrap">{{ $money($row['tax_minor']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if (count($document['withholdings']) > 0)
                <div class="label">Totais retidos na fonte ou cativados pelo adquirente</div>
                <div class="faint" style="font-size: 6.5pt; margin-bottom: 3pt;">
                    Valores informativos, não integrados no total do documento.
                </div>
                <table class="summary">
                    <thead>
                        <tr>
                            <th style="text-align: left;">Tipo</th>
                            <th style="text-align: left;">Imposto</th>
                            <th style="text-align: right;">Taxa</th>
                            <th style="text-align: right;">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($document['withholdings'] as $withheld)
                            <tr>
                                <td>{{ $withheld['type'] }}</td>
                                <td>{{ $withheld['tax'] }}</td>
                                <td class="right nowrap">{{ $percent($withheld['rate']) }}</td>
                                <td class="right nowrap">{{ $money($withheld['amount_minor']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

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
                @if ($document['totals']['settled_minor'] > 0)
                    <tr>
                        <td class="muted">Pago</td>
                        <td class="amount">{{ $money($document['totals']['settled_minor']) }}</td>
                    </tr>
                    <tr>
                        <td class="strong">Em dívida</td>
                        <td class="amount bold">
                            {{ $money($document['totals']['gross_minor'] - $document['totals']['settled_minor']) }}
                        </td>
                    </tr>
                @endif
                @if ($document['is_foreign_currency'])
                    <tr>
                        <td class="muted" style="padding-top: 6pt;">
                            Câmbio {{ $document['exchange_rate'] }} {{ $document['base_currency_code'] }}/{{ $document['currency_code'] }}
                        </td>
                        <td class="amount" style="padding-top: 6pt;">
                            {{ $money($document['totals']['gross_base_minor']) }} {{ $document['base_currency_code'] }}
                        </td>
                    </tr>
                @endif
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
