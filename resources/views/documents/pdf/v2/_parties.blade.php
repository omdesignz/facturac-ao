{{--
    The two parties where the AGT sheet puts them: the customer in a box on
    the left, the issuing company opposite.
--}}
<table class="layout">
    <tr>
        <td style="width: 56%; padding-right: 16pt;">
            <div class="label" style="margin-bottom: 4pt;">Cliente</div>
            <table class="party-box">
                <tr>
                    <td>
                        <span class="party-name">{{ $document['customer']['name'] }}</span><br>
                        <span class="muted">NIF</span> {{ $document['customer']['tax_identification_number'] }}<br>
                        @if ($document['customer']['address_line'])
                            {{ $document['customer']['address_line'] }}<br>
                        @endif
                        @if ($document['customer']['country_code'])
                            <span class="muted">{{ $document['customer']['country_code'] }}</span>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 44%; text-align: right; line-height: 1.5;">
            <div class="label" style="margin-bottom: 4pt;">Emitente</div>
            <span class="party-name">{{ $document['company']['legal_name'] }}</span><br>
            <span class="muted">Nº de Contribuinte</span> {{ $document['company']['tax_identification_number'] }}<br>
            @if ($document['company']['address_line'])
                {{ $document['company']['address_line'] }}<br>
            @endif
            @if ($document['company']['municipality']){{ $document['company']['municipality'] }} · @endif{{ $document['company']['province_code'] }}<br>
            <span class="muted">{{ $document['company']['establishment'] }}</span>
        </td>
    </tr>
</table>
