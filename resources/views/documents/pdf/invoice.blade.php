<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="utf-8">
    <title>{{ $document['document_no'] }}</title>
    @include('documents.pdf._styles')
</head>
<body>

{{--
    The header and footer repeat on every sheet. mPDF prints these into the
    page margins, which is what keeps a three-page invoice legible: whoever
    picks up page two still sees whose document it is and which one.
--}}
<htmlpageheader name="documentHeader">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="width: 48%; vertical-align: middle; padding-right: 8pt;">
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
            <td style="width: 52%; vertical-align: middle; background: #e6e6e6; padding: 6pt 10pt;">
                <div style="text-align: right; font-size: 24pt; font-weight: bold; letter-spacing: 1pt;">
                    {{ mb_strtoupper($document['document_type_label']) }}
                </div>
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

<div style="margin-top: 10pt;">
    <div class="bold" style="font-size: 10pt;">
        {{ $document['document_type_label'] }} nº {{ $document['document_no'] }}
    </div>
    <div>
        Data de emissão:
        {{ \Illuminate\Support\Carbon::parse($document['document_date'])->format('d/m/Y') }}
        @if ($document['issued_at'])
            - {{ \Illuminate\Support\Carbon::parse($document['issued_at'])->format('H\\hi') }}
        @endif
    </div>
    @if ($document['due_date'])
        <div class="muted">Vencimento:
            {{ \Illuminate\Support\Carbon::parse($document['due_date'])->format('d/m/Y') }}</div>
    @endif
</div>

{{-- The column titles repeat with the table across page breaks. --}}
<table class="grid" style="margin-top: 8pt;">
    <thead>
        <tr>
            <th rowspan="2" style="width: 5%;">Tipo</th>
            <th rowspan="2" style="width: 10%;">Código</th>
            <th rowspan="2" style="width: 18%;">Descrição</th>
            <th rowspan="2" style="width: 7%;">Qt</th>
            <th rowspan="2" style="width: 9%;">Preço Unit</th>
            <th rowspan="2" style="width: 7%;">Desc.</th>
            <th rowspan="2" style="width: 9%;">Valor</th>
            <th colspan="3" style="width: 24%;">Impostos</th>
            <th rowspan="2" style="width: 11%;">Total</th>
        </tr>
        <tr>
            <th style="width: 8%;">IEC</th>
            <th style="width: 8%;">IVA</th>
            <th style="width: 8%;">Iselo</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($document['lines'] as $index => $line)
            <tr @class(['alt' => $index % 2 === 1])>
                <td class="center">{{ $line['operation_type'] }}</td>
                <td>{{ $line['product_code'] }}</td>
                <td>
                    {{ $line['product_description'] }}
                    @if ($line['operation_date'])
                        <div class="muted" style="font-size: 7pt;">
                            Operação: {{ \Illuminate\Support\Carbon::parse($line['operation_date'])->format('d/m/Y') }}
                        </div>
                    @endif
                    @if ($line['tax_exemption_code'])
                        <div class="muted" style="font-size: 7pt;">
                            Isento · {{ $line['tax_exemption_code'] }}
                        </div>
                    @endif
                </td>
                <td class="right nowrap">{{ $line['quantity'] }} {{ $line['unit_of_measure'] }}</td>
                <td class="right nowrap">{{ $money($line['unit_price_minor']) }}</td>
                <td class="right nowrap">{{ $line['discount_rate'] }}%</td>
                <td class="right nowrap">{{ $money($line['net_amount_minor']) }}</td>
                <td class="right nowrap">{{ $money($line['taxes_by_type']['IEC']) }}</td>
                <td class="right nowrap">{{ $money($line['taxes_by_type']['IVA']) }}</td>
                <td class="right nowrap">{{ $money($line['taxes_by_type']['IS']) }}</td>
                <td class="right nowrap bold">{{ $money($line['gross_amount_minor']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table style="width: 100%; border-collapse: collapse; margin-top: 12pt;">
    <tr>
        <td style="width: 52%; vertical-align: top; padding-right: 12pt;">
            @if (count($document['withholdings']) > 0)
                <div class="bold">Totais retidos na fonte ou cativados pelo adquirente</div>
                <div class="muted" style="font-size: 7pt;">
                    (valores informativos não integrados no total do documento)
                </div>
                <table class="grid" style="margin-top: 4pt;">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th style="width: 22%;">Imposto</th>
                            <th style="width: 18%;">Taxa</th>
                            <th style="width: 26%;">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($document['withholdings'] as $withheld)
                            <tr>
                                <td>{{ $withheld['type'] }}</td>
                                <td>{{ $withheld['tax'] }}</td>
                                <td class="right">{{ $withheld['rate'] }}%</td>
                                <td class="right">{{ $money($withheld['amount_minor']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if ($document['notes'])
                <div style="margin-top: 10pt; font-size: 7.5pt;" class="muted">
                    {{ $document['notes'] }}
                </div>
            @endif
        </td>

        <td style="width: 48%; vertical-align: top;">
            <div class="section-title">Totais do documento (valores em {{ $document['currency_code'] }})</div>
            <table class="grid" style="margin-top: 4pt;">
                <thead>
                    <tr>
                        <th>Descrição</th>
                        <th style="width: 40%;">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($document['tax_summary'] as $index => $row)
                        <tr @class(['alt' => $index % 2 === 1])>
                            <td>
                                Incidência a {{ $row['rate'] }}%
                                @if ($row['exemption_code'])
                                    <span class="muted">· {{ $row['exemption_code'] }}</span>
                                @endif
                            </td>
                            <td class="right nowrap">{{ $money($row['base_minor']) }}</td>
                        </tr>
                        <tr @class(['alt' => $index % 2 === 1])>
                            <td>Imposto a {{ $row['rate'] }}%</td>
                            <td class="right nowrap">{{ $money($row['tax_minor']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="bold right" style="margin-top: 10pt;">
                Valores em {{ $document['currency_code'] }}
            </div>
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
                @if ($document['totals']['settled_minor'] > 0)
                    <tr>
                        <td class="label">Pago</td>
                        <td class="right nowrap">{{ $money($document['totals']['settled_minor']) }}</td>
                    </tr>
                    <tr>
                        <td class="label bold">Em dívida</td>
                        <td class="right nowrap bold">
                            {{ $money($document['totals']['gross_minor'] - $document['totals']['settled_minor']) }}
                        </td>
                    </tr>
                @endif
                @if ($document['is_foreign_currency'])
                    <tr>
                        <td class="label">
                            Câmbio {{ $document['exchange_rate'] }}
                            {{ $document['base_currency_code'] }}/{{ $document['currency_code'] }}
                        </td>
                        <td class="right nowrap">
                            {{ $money($document['totals']['gross_base_minor']) }}
                            {{ $document['base_currency_code'] }}
                        </td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<div style="margin-top: 16pt;">
    @include('documents.pdf._authenticity', ['qrImage' => $document['authenticity']['qr_data_uri']])
</div>

</body>
</html>
