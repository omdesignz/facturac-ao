{{--
    An orçamento: the lines, the taxes and the total, and until when it holds.
    Not a fiscal document: no series number, no signature, no QR.
--}}
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    @include('business-documents.pdf.v1._styles')
</head>
<body>
@include('business-documents.pdf.v1._header', [
    'title' => 'Orçamento',
    'subtitle' => $quote['reference'],
])

<table class="layout">
    <tr>
        <td style="width: 60%; padding-right: 10pt;">
            <table class="party-box">
                <tr>
                    <td>
                        <div class="label">Para</div>
                        <div class="party-name">{{ $customer['name'] }}</div>
                        @if ($customer['tax_identification_number'])
                            <div>NIF {{ $customer['tax_identification_number'] }}</div>
                        @endif
                        @if ($customer['address_line'])
                            <div class="muted">{{ $customer['address_line'] }}</div>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 40%;">
            <table class="party-box">
                <tr>
                    <td>
                        <div class="label">Data</div>
                        <div>{{ $quote['issue_date']->format('d/m/Y') }}</div>
                        <div class="label" style="margin-top: 5pt;">Válido até</div>
                        <div class="strong">{{ $quote['valid_until']->format('d/m/Y') }}</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th style="width: 40%;">Descrição</th>
            <th class="right" style="width: 12%;">Qtd.</th>
            <th class="right" style="width: 16%;">Preço ({{ $quote['currency'] }})</th>
            <th class="right" style="width: 12%;">IVA</th>
            <th class="right" style="width: 20%;">Total ({{ $quote['currency'] }})</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr>
                <td>
                    <span class="strong">{{ $line['description'] }}</span>
                    @if ($line['code'])
                        <div class="line-note">{{ $line['code'] }}</div>
                    @endif
                    @if ($line['discount'])
                        <div class="line-note">Desconto {{ $percent($line['discount']) }}</div>
                    @endif
                    @if ($line['exemption'])
                        <div class="line-note">Isento · {{ $line['exemption'] }}</div>
                    @endif
                </td>
                <td class="figure-cell">{{ $number($line['quantity']) }} {{ $line['unit'] }}</td>
                <td class="figure-cell">{{ $money($line['unit_price_minor']) }}</td>
                <td class="figure-cell">{{ $percent($line['tax']) }}</td>
                <td class="figure-cell strong">{{ $money($line['gross_minor']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="layout">
    <tr>
        <td style="width: 54%;"></td>
        <td style="width: 46%;">
            <table class="totals" style="width: 100%;">
                <tr><td>Subtotal</td><td class="right nowrap">{{ $money($quote['net_total_minor']) }}</td></tr>
                <tr><td>Imposto</td><td class="right nowrap">{{ $money($quote['tax_total_minor']) }}</td></tr>
                <tr class="grand"><td>Total</td><td class="right nowrap">{{ $money($quote['gross_total_minor']) }} {{ $quote['currency'] }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="notice">
    Orçamento sem valor fiscal, válido até {{ $quote['valid_until']->format('d/m/Y') }}.
    A factura é emitida quando o orçamento for aceite.
</div>
</body>
</html>
