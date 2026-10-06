{{--
    The two parties, in the positions the AGT sheet puts them: the customer in
    a bordered box on the left, the issuing company on the right.
--}}
<table style="width: 100%; border-collapse: collapse;">
    <tr>
        <td style="width: 55%; vertical-align: top; padding-right: 14pt;">
            <table class="party-box">
                <tr>
                    <td>
                        <span class="bold">Contribuinte:</span> {{ $document['customer']['name'] }}<br>
                        @if ($document['customer']['address_line'])
                            <span class="bold">Localização:</span> {{ $document['customer']['address_line'] }}<br>
                        @endif
                        <span class="bold">NIF:</span> {{ $document['customer']['tax_identification_number'] }}
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 45%; vertical-align: top; text-align: right;">
            <div class="bold">{{ $document['company']['legal_name'] }}</div>
            <div>Nº de Contribuinte: {{ $document['company']['tax_identification_number'] }}</div>
            <div>{{ $document['company']['address_line'] }}</div>
            <div>
                @if ($document['company']['municipality']){{ $document['company']['municipality'] }} — @endif
                {{ $document['company']['province_code'] }}
            </div>
            <div class="muted">{{ $document['company']['establishment'] }}</div>
        </td>
    </tr>
</table>
