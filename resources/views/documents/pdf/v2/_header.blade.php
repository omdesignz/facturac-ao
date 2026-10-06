{{--
    Printed in the page margins, so every sheet of a long document still says
    whose it is and which one: the mark on the left, the kind and number on
    the right, an ink rule beneath. The footer carries the issuer and the
    page count.
--}}
<htmlpageheader name="documentHeader">
    <table class="layout">
        <tr>
            <td style="width: 55%; vertical-align: bottom;">
                @if ($logo)
                    <img src="{{ $logo }}"
                         style="max-width: {{ config('fiscal.print.logo_max_width_mm') }}mm; max-height: {{ config('fiscal.print.logo_max_height_mm') }}mm;"
                         alt="">
                @else
                    <div class="issuer-name">
                        {{ $document['company']['trade_name'] ?? $document['company']['legal_name'] }}
                    </div>
                @endif
            </td>
            <td style="width: 45%; vertical-align: bottom; text-align: right;">
                <div class="doc-kind">{{ $document['document_type_label'] }}</div>
                <div class="doc-number">{{ $document['document_no'] }}</div>
            </td>
        </tr>
    </table>
    <div class="header-rule"></div>
</htmlpageheader>

<htmlpagefooter name="documentFooter">
    <table class="footer">
        <tr>
            <td>
                {{ $document['company']['legal_name'] }} · NIF {{ $document['company']['tax_identification_number'] }}
                · {{ $document['document_type_label'] }} {{ $document['document_no'] }}
            </td>
            <td class="right nowrap">Página {PAGENO} de {nbpg}</td>
        </tr>
    </table>
</htmlpagefooter>
