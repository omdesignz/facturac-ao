{{--
    The document's facts on one band, label over value. Each sheet passes the
    ones it has; a correction adds the document it corrects and why.

    @param array<int, array{0: string, 1: string}> $facts
--}}
<table class="facts" style="margin-top: 14pt;">
    <tr>
        @foreach ($facts as [$label, $value])
            <td style="width: {{ round(100 / count($facts), 2) }}%;">
                <div class="label">{{ $label }}</div>
                <div class="fact-value">{{ $value }}</div>
            </td>
        @endforeach
    </tr>
</table>

@if ($document['references_document_no'] || $document['adjustment_reason'])
    <table class="facts" style="margin-top: 1pt;">
        <tr>
            @if ($document['references_document_no'])
                <td style="width: 32%;">
                    <div class="label">Documento de origem</div>
                    <div class="fact-value">{{ $document['references_document_no'] }}</div>
                </td>
            @endif
            @if ($document['adjustment_reason'])
                <td>
                    <div class="label">Motivo</div>
                    <div class="fact-value">{{ $document['adjustment_reason'] }}</div>
                </td>
            @endif
        </tr>
    </table>
@endif
