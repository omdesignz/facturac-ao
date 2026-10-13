@if (in_array($document['document_type'], ['FT', 'GF', 'ND'], true)
    && $document['totals']['settled_minor'] < $document['totals']['gross_minor']
    && !empty($document['company']['bank_name'])
    && (!empty($document['company']['bank_iban']) || !empty($document['company']['bank_account_number'])))
    <div class="label" style="margin-top: 12pt;">Dados para pagamento</div>
    <div style="font-size: 8pt; line-height: 1.5;">
        <div class="strong">{{ $document['company']['bank_name'] }}</div>
        <div>{{ $document['company']['bank_account_holder'] }}</div>
        @if (!empty($document['company']['bank_iban']))
            <div>IBAN {{ $document['company']['bank_iban'] }}</div>
        @endif
        @if (!empty($document['company']['bank_account_number']))
            <div>Conta {{ $document['company']['bank_account_number'] }}</div>
        @endif
        <div>Referência: {{ $document['document_no'] }}</div>
    </div>
@endif
