{{--
    A customer's conta corrente: every movement and the balance after it,
    oldest first, with what is owed and overdue on top. Same figures as the
    customer page. Not a fiscal document.
--}}
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    @include('business-documents.pdf.v1._styles')
</head>
<body>
@include('business-documents.pdf.v1._header', [
    'title' => 'Extracto de conta',
    'subtitle' => 'Em '.$generatedOn->format('d/m/Y'),
])

<table class="party-box">
    <tr>
        <td>
            <div class="label">Cliente</div>
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

<table class="figures">
    <tr>
        <td style="width: 33%;">
            <div class="label">Em dívida</div>
            <div class="figure">{{ $money($summary['outstanding_minor']) }} {{ $currency }}</div>
        </td>
        <td style="width: 33%;">
            <div class="label">Vencido</div>
            <div class="figure @if ($summary['overdue_minor'] > 0) critical @endif">{{ $money($summary['overdue_minor']) }} {{ $currency }}</div>
            @if ($summary['overdue_count'] > 0)
                <div class="muted" style="font-size: 8pt;">{{ $summary['overdue_count'] }} {{ $summary['overdue_count'] === 1 ? 'documento' : 'documentos' }}</div>
            @endif
        </td>
        <td style="width: 34%;">
            <div class="label">Pago</div>
            <div class="figure">{{ $money($summary['paid_minor']) }} {{ $currency }}</div>
        </td>
    </tr>
</table>

@if (count($entries) === 0)
    <div class="notice">Ainda não há movimentos com este cliente.</div>
@else
    <table class="items">
        <thead>
            <tr>
                <th style="width: 34%;">Documento</th>
                <th style="width: 16%;">Data</th>
                <th style="width: 16%;">Vencimento</th>
                <th class="right" style="width: 17%;">Movimento ({{ $currency }})</th>
                <th class="right" style="width: 17%;">Saldo ({{ $currency }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($entries as $entry)
                <tr>
                    <td>
                        <span class="strong">{{ $entry['document_no'] ?? '—' }}</span>
                        <div class="line-note">{{ $entry['document_type_label'] }}</div>
                    </td>
                    <td class="nowrap">{{ \Carbon\CarbonImmutable::parse($entry['document_date'])->format('d/m/Y') }}</td>
                    <td class="nowrap">
                        {{ $entry['due_date'] ? \Carbon\CarbonImmutable::parse($entry['due_date'])->format('d/m/Y') : '—' }}
                        @if ($entry['days_past_due'] > 0 && $entry['outstanding_minor'] > 0)
                            <div class="line-note critical">{{ $entry['days_past_due'] }} dias em atraso</div>
                        @endif
                    </td>
                    <td class="figure-cell @if ($entry['movement_minor'] < 0) positive @endif">
                        {{ $entry['movement_minor'] < 0 ? '−' : '+' }}{{ $money(abs($entry['movement_minor'])) }}
                    </td>
                    <td class="figure-cell strong">{{ $money($entry['balance_minor']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<div class="notice">
    Extracto informativo, sem valor fiscal. Os valores são os dos documentos emitidos em {{ $currency }};
    se algum pagamento ainda não aparece, responda com o comprovativo.
</div>
</body>
</html>
